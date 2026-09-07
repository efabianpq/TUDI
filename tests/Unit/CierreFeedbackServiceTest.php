<?php

use App\Exceptions\MealDistributionUnavailableException;
use App\Models\ComidaReal;
use App\Models\PlanComida;
use App\Models\RegistroDiario;
use App\Models\User;
use App\Services\AI\MealDistributionProviderInterface;
use App\Services\CierreFeedbackService;
use App\Services\NutritionCalculatorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// El servicio toca Eloquent, así que este archivo activa TestCase +
// RefreshDatabase explícitamente (tests/Pest.php solo los aplica a Feature).
uses(TestCase::class, RefreshDatabase::class);

/**
 * Proveedor de mentira para el feedback del cierre: devuelve una estimación por
 * comida y recuerda cómo lo llamaron. Si se construye con `$falla`, simula que
 * el proveedor no está disponible.
 */
function proveedorDeConsumoFalso(bool $falla = false): object
{
    $falso = new class($falla) implements MealDistributionProviderInterface
    {
        /** @var array<int, array{comidas: array, contextoDia: array}> */
        public array $llamadas = [];

        public function __construct(private bool $falla) {}

        public function distribuirDia(array $comidas, array $contextoDia): array
        {
            throw new LogicException('El feedback del cierre no distribuye comidas.');
        }

        public function estimarConsumoReal(array $comidas, array $contextoDia): array
        {
            $this->llamadas[] = compact('comidas', 'contextoDia');

            if ($this->falla) {
                throw MealDistributionUnavailableException::porFalloDelProveedor('caído en el test');
            }

            $salida = [];

            foreach (array_keys($comidas) as $tipo) {
                $salida[$tipo] = [
                    'descripcion' => "Sándwich de pollo ({$tipo})",
                    'preparacion' => '',
                    'notas' => 'Asumí una porción estándar.',
                    'ingredientes' => [
                        ['nombre' => 'Sándwich de pollo', 'porcion' => '1 unidad', 'cantidad_g' => 220, 'calorias' => 480, 'proteina_g' => 28, 'grasa_g' => 18, 'carbohidratos_g' => 50],
                        ['nombre' => 'Gaseosa', 'porcion' => '1 lata', 'cantidad_g' => 350, 'calorias' => 140, 'proteina_g' => 0, 'grasa_g' => 0, 'carbohidratos_g' => 37],
                    ],
                ];
            }

            return $salida;
        }
    };

    app()->instance(MealDistributionProviderInterface::class, $falso);

    return $falso;
}

function diaConPlanesParaFeedback(): RegistroDiario
{
    $usuario = User::factory()->create([
        'peso_kg' => 80,
        'nivel_actividad' => 1.5,
        'tipo_deficit' => NutritionCalculatorService::TIPO_DEFICIT_PORCENTAJE,
        'valor_deficit' => 0.2,
        'proteina_factor' => 1.8,
        'grasa_factor' => 0.8,
        'calorias_objetivo' => 2112,
    ]);

    $registroDiario = RegistroDiario::factory()->for($usuario, 'usuario')->create([
        'fecha' => now()->toDateString(),
        'calorias_consumidas' => 0,
    ]);

    foreach ([['desayuno', 528.0], ['almuerzo', 845.0], ['cena', 739.0]] as [$tipo, $calorias]) {
        PlanComida::factory()->for($registroDiario, 'registroDiario')->create([
            'tipo_comida' => $tipo,
            'calorias_estimadas' => $calorias,
            'proteina_g' => 40.0,
            'grasa_g' => 15.0,
            'carbohidratos_g' => 60.0,
        ]);
    }

    return $registroDiario;
}

it('registra una comida cumplida con los macros del propio plan, sin llamar al proveedor', function () {
    $falso = proveedorDeConsumoFalso();
    $registroDiario = diaConPlanesParaFeedback();

    $registradas = app(CierreFeedbackService::class)->registrar($registroDiario, [
        'almuerzo' => ['cumplio' => true],
    ]);

    expect($registradas)->toHaveCount(1)
        ->and($falso->llamadas)->toBeEmpty();

    $comidaReal = $registroDiario->planesComida()->where('tipo_comida', 'almuerzo')->firstOrFail()->comidaReal;

    expect((float) $comidaReal->calorias_reales)->toBe(845.0)
        ->and((float) $comidaReal->proteina_g)->toBe(40.0)
        ->and($comidaReal->notas)->toBe('Cumplí con lo sugerido.')
        // Y el acumulado del día se actualiza vía ComidaRealService.
        ->and((float) $registroDiario->fresh()->calorias_consumidas)->toBe(845.0);
});

it('interpreta con la IA lo que se comió de verdad y suma los macros en PHP', function () {
    $falso = proveedorDeConsumoFalso();
    $registroDiario = diaConPlanesParaFeedback();

    app(CierreFeedbackService::class)->registrar($registroDiario, [
        'cena' => ['texto' => '  al final me comí un sándwich y una gaseosa  '],
    ]);

    $comidaReal = $registroDiario->planesComida()->where('tipo_comida', 'cena')->firstOrFail()->comidaReal;

    // 480 + 140 = 620 kcal; 28 + 0 = 28 g de proteína. Las suma PHP, no el modelo.
    expect((float) $comidaReal->calorias_reales)->toBe(620.0)
        ->and((float) $comidaReal->proteina_g)->toBe(28.0)
        ->and((float) $comidaReal->carbohidratos_g)->toBe(87.0)
        // Las notas conservan lo que escribió el usuario y lo que asumió el modelo.
        ->and($comidaReal->notas)->toContain('sándwich y una gaseosa')
        ->and($comidaReal->notas)->toContain('porción estándar');

    // El proveedor recibe el plan como referencia y el objetivo del día.
    expect($falso->llamadas)->toHaveCount(1)
        ->and($falso->llamadas[0]['comidas']['cena']['plan']['calorias'])->toBe(739.0)
        ->and($falso->llamadas[0]['contextoDia']['calorias_objetivo_dia'])->toEqualWithDelta(2112.0, 0.01);
});

it('consolida todas las comidas descritas en una sola llamada al proveedor', function () {
    $falso = proveedorDeConsumoFalso();
    $registroDiario = diaConPlanesParaFeedback();

    app(CierreFeedbackService::class)->registrar($registroDiario, [
        'desayuno' => ['texto' => 'café con leche y una tostada'],
        'almuerzo' => ['cumplio' => true],
        'cena' => ['texto' => 'un sándwich'],
    ]);

    expect($falso->llamadas)->toHaveCount(1)
        ->and(array_keys($falso->llamadas[0]['comidas']))->toBe(['desayuno', 'cena'])
        ->and($registroDiario->planesComida()->has('comidaReal')->count())->toBe(3);

    // 620 (desayuno) + 845 (almuerzo cumplido) + 620 (cena).
    expect((float) $registroDiario->fresh()->calorias_consumidas)->toBe(2085.0);
});

it('el texto manda sobre la casilla cuando llegan los dos', function () {
    proveedorDeConsumoFalso();
    $registroDiario = diaConPlanesParaFeedback();

    app(CierreFeedbackService::class)->registrar($registroDiario, [
        'almuerzo' => ['cumplio' => true, 'texto' => 'en realidad me comí un sándwich'],
    ]);

    $comidaReal = $registroDiario->planesComida()->where('tipo_comida', 'almuerzo')->firstOrFail()->comidaReal;

    expect((float) $comidaReal->calorias_reales)->toBe(620.0);
});

it('ignora una comida que ya tiene ComidaReal registrada', function () {
    $falso = proveedorDeConsumoFalso();
    $registroDiario = diaConPlanesParaFeedback();
    $plan = $registroDiario->planesComida()->where('tipo_comida', 'desayuno')->firstOrFail();

    ComidaReal::factory()->for($plan, 'planComida')->create(['calorias_reales' => 500]);

    $registradas = app(CierreFeedbackService::class)->registrar($registroDiario, [
        'desayuno' => ['texto' => 'me comí otra cosa'],
    ]);

    expect($registradas)->toBeEmpty()
        ->and($falso->llamadas)->toBeEmpty()
        ->and((float) $plan->fresh()->comidaReal->calorias_reales)->toBe(500.0);
});

it('ignora una comida sin plan y un feedback vacío', function () {
    $falso = proveedorDeConsumoFalso();
    $registroDiario = diaConPlanesParaFeedback();
    $registroDiario->planesComida()->where('tipo_comida', 'cena')->delete();

    $registradas = app(CierreFeedbackService::class)->registrar($registroDiario, [
        'cena' => ['texto' => 'algo comí'],
        'almuerzo' => ['cumplio' => false, 'texto' => '   '],
    ]);

    expect($registradas)->toBeEmpty()
        ->and($falso->llamadas)->toBeEmpty()
        ->and((float) $registroDiario->fresh()->calorias_consumidas)->toBe(0.0);
});

it('no registra nada si el proveedor falla, ni siquiera las comidas confirmadas con la casilla', function () {
    proveedorDeConsumoFalso(falla: true);
    $registroDiario = diaConPlanesParaFeedback();

    expect(fn () => app(CierreFeedbackService::class)->registrar($registroDiario, [
        'desayuno' => ['cumplio' => true],
        'almuerzo' => ['texto' => 'un sándwich'],
    ]))->toThrow(MealDistributionUnavailableException::class);

    expect(ComidaReal::count())->toBe(0)
        ->and((float) $registroDiario->fresh()->calorias_consumidas)->toBe(0.0);
});
