<?php

use App\Exceptions\MealDistributionUnavailableException;
use App\Models\ComidaReal;
use App\Models\PlanComida;
use App\Models\RegistroDiario;
use App\Models\User;
use App\Services\AI\MealDistributionProviderInterface;
use App\Services\MealDistributionService;
use App\Services\MealPlanGeneratorService;
use App\Services\NutritionCalculatorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// El servicio toca Eloquent, así que este archivo activa TestCase +
// RefreshDatabase explícitamente (tests/Pest.php solo los aplica a Feature).
uses(TestCase::class, RefreshDatabase::class);

/**
 * Proveedor de mentira: devuelve una distribución por cada comida que se le
 * pida y recuerda con qué argumentos lo llamaron. Así los tests del servicio no
 * dependen de la red ni del modelo.
 */
function proveedorFalso(): object
{
    $falso = new class implements MealDistributionProviderInterface
    {
        /** @var array<int, array{comidas: array, contextoDia: array}> */
        public array $llamadas = [];

        /** @var array<int, array{comidas: array, contextoDia: array}> */
        public array $llamadasConsumoReal = [];

        public function distribuirDia(array $comidas, array $contextoDia): array
        {
            $this->llamadas[] = compact('comidas', 'contextoDia');

            return $this->responder(array_keys($comidas));
        }

        public function estimarConsumoReal(array $comidas, array $contextoDia): array
        {
            $this->llamadasConsumoReal[] = compact('comidas', 'contextoDia');

            return $this->responder(array_keys($comidas));
        }

        /**
         * @param  array<int, string>  $tipos
         */
        private function responder(array $tipos): array
        {
            $salida = [];

            foreach ($tipos as $tipo) {
                $salida[$tipo] = distribucionFalsa($tipo);
            }

            return $salida;
        }
    };

    app()->instance(MealDistributionProviderInterface::class, $falso);

    return $falso;
}

function distribucionFalsa(string $tipoComida = 'almuerzo'): array
{
    return [
        'descripcion' => "Pollo con arroz ({$tipoComida})",
        'preparacion' => 'A la plancha.',
        'notas' => 'Faltan ~10 g de proteína.',
        'ingredientes' => [
            ['nombre' => 'Pechuga de pollo', 'porcion' => '1 filete', 'cantidad_g' => 150.0, 'calorias' => 247.5, 'proteina_g' => 46.5, 'grasa_g' => 5.4, 'carbohidratos_g' => 0.0],
            ['nombre' => 'Arroz integral', 'porcion' => '1 taza', 'cantidad_g' => 100.0, 'calorias' => 350.0, 'proteina_g' => 7.5, 'grasa_g' => 2.7, 'carbohidratos_g' => 72.0],
        ],
    ];
}

function usuarioParaDistribucion(array $overrides = []): User
{
    return User::factory()->create(array_merge([
        'peso_kg' => 80,
        'nivel_actividad' => 1.5,
        'tipo_deficit' => NutritionCalculatorService::TIPO_DEFICIT_PORCENTAJE,
        'valor_deficit' => 0.2,
        'proteina_factor' => 1.8,
        'grasa_factor' => 0.8,
        'calorias_objetivo' => 2112,
    ], $overrides));
}

function registroDeHoyDe(User $usuario, array $overrides = []): RegistroDiario
{
    return RegistroDiario::factory()->for($usuario, 'usuario')->create(array_merge([
        'fecha' => now()->toDateString(),
    ], $overrides));
}

it('reparte el objetivo del día entre las tres comidas según DISTRIBUCION_COMIDAS', function () {
    proveedorFalso();
    $usuario = usuarioParaDistribucion();

    $objetivos = app(MealDistributionService::class)->objetivosDelDia($usuario);

    expect($objetivos['dia']['calorias_objetivo'])->toEqualWithDelta(2112.0, 0.01)
        ->and($objetivos['por_comida'])->toHaveKeys(['desayuno', 'almuerzo', 'cena'])
        ->and($objetivos['por_comida']['desayuno']['calorias'])->toEqualWithDelta(2112 * 0.25, 0.01)
        ->and($objetivos['por_comida']['almuerzo']['calorias'])->toEqualWithDelta(2112 * 0.40, 0.01)
        ->and($objetivos['por_comida']['cena']['calorias'])->toEqualWithDelta(2112 * 0.35, 0.01)
        // Los macros se reparten con el mismo porcentaje que las calorías.
        ->and($objetivos['por_comida']['almuerzo']['proteina_g'])->toEqualWithDelta(80 * 1.8 * 0.40, 0.01);
});

it('usa el objetivo vigente del usuario y no el derivado de la fórmula', function () {
    proveedorFalso();
    // Una RecomendacionSistema confirmada movió el objetivo (sección 4.10).
    $usuario = usuarioParaDistribucion(['calorias_objetivo' => 1962]);

    $objetivos = app(MealDistributionService::class)->objetivosDelDia($usuario);

    expect($objetivos['dia']['calorias_objetivo'])->toEqualWithDelta(1962.0, 0.01);
});

it('guarda el texto de ingredientes de la comida indicada y lo limpia si llega vacío', function () {
    proveedorFalso();
    $registroDiario = registroDeHoyDe(usuarioParaDistribucion());
    $servicio = app(MealDistributionService::class);

    $servicio->guardarIngredientes($registroDiario, 'almuerzo', '  pollo y arroz  ');

    expect($registroDiario->fresh()->ingredientes_almuerzo)->toBe('pollo y arroz')
        ->and($registroDiario->fresh()->ingredientes_desayuno)->toBeNull();

    $servicio->guardarIngredientes($registroDiario, 'almuerzo', '   ');

    expect($registroDiario->fresh()->ingredientes_almuerzo)->toBeNull();
});

it('rechaza un tipo de comida que no existe', function () {
    proveedorFalso();
    $registroDiario = registroDeHoyDe(usuarioParaDistribucion());

    expect(fn () => app(MealDistributionService::class)->guardarIngredientes($registroDiario, 'merienda', 'algo'))
        ->toThrow(InvalidArgumentException::class);
});

it('resuelve las tres comidas en una sola llamada, sumando los macros en PHP', function () {
    $falso = proveedorFalso();
    $registroDiario = registroDeHoyDe(usuarioParaDistribucion());

    $planes = app(MealDistributionService::class)->distribuirDia($registroDiario, [
        'desayuno' => 'huevos y pan',
        'almuerzo' => 'pollo y arroz',
        'cena' => 'ensalada y atún',
    ]);

    expect($planes)->toHaveCount(3)
        ->and($falso->llamadas)->toHaveCount(1)
        ->and(array_keys($falso->llamadas[0]['comidas']))->toBe(['desayuno', 'almuerzo', 'cena']);

    $almuerzo = $registroDiario->planesComida()->where('tipo_comida', 'almuerzo')->firstOrFail();

    // 247.5 + 350 = 597.5 kcal; 46.5 + 7.5 = 54 g de proteína.
    expect((float) $almuerzo->calorias_estimadas)->toBe(597.5)
        ->and((float) $almuerzo->proteina_g)->toBe(54.0)
        ->and((float) $almuerzo->grasa_g)->toBe(8.1)
        ->and((float) $almuerzo->carbohidratos_g)->toBe(72.0)
        ->and($almuerzo->preparacion)->toBe('A la plancha.')
        ->and($almuerzo->notas_ia)->toBe('Faltan ~10 g de proteína.')
        ->and($almuerzo->ingredientes_detalle)->toHaveCount(2);

    // Los textos quedan guardados para que el usuario los pueda corregir.
    expect($registroDiario->fresh()->ingredientes_cena)->toBe('ensalada y atún');

    // Con las tres a la vez no hay nada reservado ni fijo, y cada una recibe su
    // porcentaje íntegro del reparto 25/40/35.
    $contexto = $falso->llamadas[0]['contextoDia'];

    expect($contexto['comidas_fijas'])->toBe([])
        ->and($contexto['comidas_reservadas'])->toBe([])
        ->and($contexto['reparto'])->toBe(MealPlanGeneratorService::DISTRIBUCION_COMIDAS)
        ->and($contexto['calorias_objetivo_dia'])->toEqualWithDelta(2112.0, 0.01)
        ->and($falso->llamadas[0]['comidas']['almuerzo']['objetivos']['calorias'])->toEqualWithDelta(2112 * 0.40, 0.01);
});

it('reserva las calorías de la cena cuando solo se escriben desayuno y almuerzo', function () {
    $falso = proveedorFalso();
    $registroDiario = registroDeHoyDe(usuarioParaDistribucion());

    app(MealDistributionService::class)->distribuirDia($registroDiario, [
        'desayuno' => 'huevos y pan',
        'almuerzo' => 'pollo y arroz',
        'cena' => null,
    ]);

    $contexto = $falso->llamadas[0]['contextoDia'];

    // La cena no se resuelve, pero su 35% del día se aparta.
    expect(array_keys($falso->llamadas[0]['comidas']))->toBe(['desayuno', 'almuerzo'])
        ->and($contexto['comidas_reservadas'])->toHaveKey('cena')
        ->and($contexto['comidas_reservadas']['cena']['calorias'])->toEqualWithDelta(2112 * 0.35, 0.01);

    // Lo que queda (65% del día) se reparte entre desayuno y almuerzo con sus
    // pesos relativos: 25/65 y 40/65.
    $disponible = 2112 * 0.65;

    expect($falso->llamadas[0]['comidas']['desayuno']['objetivos']['calorias'])
        ->toEqualWithDelta($disponible * (0.25 / 0.65), 0.02)
        ->and($falso->llamadas[0]['comidas']['almuerzo']['objetivos']['calorias'])
        ->toEqualWithDelta($disponible * (0.40 / 0.65), 0.02);

    expect($registroDiario->planesComida()->count())->toBe(2);
});

it('al añadir la cena por la tarde no toca el desayuno ni el almuerzo ya generados', function () {
    $falso = proveedorFalso();
    $registroDiario = registroDeHoyDe(usuarioParaDistribucion());
    $servicio = app(MealDistributionService::class);

    $servicio->distribuirDia($registroDiario, [
        'desayuno' => 'huevos y pan',
        'almuerzo' => 'pollo y arroz',
    ]);

    $idsDeLaManana = $registroDiario->planesComida()->pluck('id', 'tipo_comida')->all();

    // Segunda pasada: el usuario reenvía el mismo texto de la mañana y añade la cena.
    $nuevos = $servicio->distribuirDia($registroDiario, [
        'desayuno' => 'huevos y pan',
        'almuerzo' => 'pollo y arroz',
        'cena' => 'ensalada y atún',
    ]);

    expect($nuevos)->toHaveCount(1)
        ->and($nuevos->first()->tipo_comida)->toBe('cena')
        ->and($falso->llamadas)->toHaveCount(2)
        ->and(array_keys($falso->llamadas[1]['comidas']))->toBe(['cena']);

    // Los planes de la mañana son literalmente los mismos registros.
    expect($registroDiario->planesComida()->pluck('id', 'tipo_comida')->only(['desayuno', 'almuerzo'])->all())
        ->toBe($idsDeLaManana);

    // Y su consumo entra como presupuesto ya gastado del día.
    expect($falso->llamadas[1]['contextoDia']['comidas_fijas'])->toHaveKeys(['desayuno', 'almuerzo'])
        ->and($falso->llamadas[1]['contextoDia']['comidas_fijas']['almuerzo']['calorias'])->toBe(597.5)
        ->and($falso->llamadas[1]['contextoDia']['comidas_reservadas'])->toBe([]);

    // A la cena le queda el día menos lo que ya se comió: 2112 − 597.5 × 2.
    expect($falso->llamadas[1]['comidas']['cena']['objetivos']['calorias'])
        ->toEqualWithDelta(2112 - 597.5 * 2, 0.02);
});

it('regenera una comida cuando su texto cambia', function () {
    $falso = proveedorFalso();
    $registroDiario = registroDeHoyDe(usuarioParaDistribucion());
    $servicio = app(MealDistributionService::class);

    $servicio->distribuirDia($registroDiario, ['desayuno' => 'huevos y pan']);
    $primero = $registroDiario->planesComida()->where('tipo_comida', 'desayuno')->firstOrFail();

    $servicio->distribuirDia($registroDiario, ['desayuno' => 'huevos, pan y palta']);

    expect($registroDiario->planesComida()->where('tipo_comida', 'desayuno')->count())->toBe(1)
        ->and(PlanComida::find($primero->id))->toBeNull()
        ->and($falso->llamadas)->toHaveCount(2);
});

it('rehacer fuerza a regenerar una comida aunque su texto no haya cambiado', function () {
    $falso = proveedorFalso();
    $registroDiario = registroDeHoyDe(usuarioParaDistribucion());
    $servicio = app(MealDistributionService::class);

    $servicio->distribuirDia($registroDiario, ['almuerzo' => 'pollo y arroz']);

    // Sin "rehacer" no habría nada nuevo que distribuir.
    expect(fn () => $servicio->distribuirDia($registroDiario, ['almuerzo' => 'pollo y arroz']))
        ->toThrow(MealDistributionUnavailableException::class, 'nada nuevo que distribuir');

    $servicio->distribuirDia($registroDiario, ['almuerzo' => 'pollo y arroz'], 'almuerzo');

    expect($falso->llamadas)->toHaveCount(2)
        ->and($registroDiario->planesComida()->where('tipo_comida', 'almuerzo')->count())->toBe(1);
});

it('no llama al proveedor cuando ninguna comida tiene texto', function () {
    $falso = proveedorFalso();
    $registroDiario = registroDeHoyDe(usuarioParaDistribucion());

    expect(fn () => app(MealDistributionService::class)->distribuirDia($registroDiario, [
        'desayuno' => null,
        'almuerzo' => '   ',
    ]))->toThrow(MealDistributionUnavailableException::class, 'nada nuevo que distribuir');

    expect($falso->llamadas)->toBeEmpty()
        ->and($registroDiario->planesComida()->count())->toBe(0);
});

it('nunca pisa una comida que ya tiene su ComidaReal registrada', function () {
    $falso = proveedorFalso();
    $registroDiario = registroDeHoyDe(usuarioParaDistribucion());
    $servicio = app(MealDistributionService::class);

    $servicio->distribuirDia($registroDiario, ['desayuno' => 'huevos y pan']);
    $plan = $registroDiario->planesComida()->where('tipo_comida', 'desayuno')->firstOrFail();
    ComidaReal::factory()->for($plan, 'planComida')->create(['calorias_reales' => 400]);

    // Ni siquiera "rehacer" la toca: se preserva planificado vs. ejecutado.
    $servicio->distribuirDia($registroDiario, [
        'desayuno' => 'huevos, pan y palta',
        'cena' => 'ensalada y atún',
    ], 'desayuno');

    expect(PlanComida::find($plan->id))->not->toBeNull()
        ->and(array_keys($falso->llamadas[1]['comidas']))->toBe(['cena'])
        // Y lo que realmente comió es lo que gasta presupuesto, no lo planificado.
        ->and($falso->llamadas[1]['contextoDia']['comidas_fijas']['desayuno']['calorias'])->toBe(400.0);
});

/*
|--------------------------------------------------------------------------
| Reparto por día (CLAUDE.md sección 5.14)
|--------------------------------------------------------------------------
*/

it('reparte con el reparto propio del día en vez del de fábrica', function () {
    proveedorFalso();
    $usuario = usuarioParaDistribucion();
    $registroDiario = registroDeHoyDe($usuario, [
        'reparto_comidas' => ['desayuno' => 0.10, 'almuerzo' => 0.50, 'cena' => 0.40],
    ]);

    $objetivos = app(MealDistributionService::class)->objetivosDelRegistro($registroDiario);

    expect($objetivos['por_comida']['desayuno']['calorias'])->toEqualWithDelta(2112 * 0.10, 0.01)
        ->and($objetivos['por_comida']['almuerzo']['calorias'])->toEqualWithDelta(2112 * 0.50, 0.01)
        ->and($objetivos['por_comida']['cena']['calorias'])->toEqualWithDelta(2112 * 0.40, 0.01)
        // El día entero sigue sumando el mismo objetivo: solo cambia el reparto.
        ->and(array_sum(array_column($objetivos['por_comida'], 'calorias')))
        ->toEqualWithDelta(2112.0, 0.01);
});

it('manda al proveedor el presupuesto del reparto propio del día', function () {
    $falso = proveedorFalso();
    $registroDiario = registroDeHoyDe(usuarioParaDistribucion(), [
        'reparto_comidas' => ['desayuno' => 0.10, 'almuerzo' => 0.50, 'cena' => 0.40],
    ]);

    app(MealDistributionService::class)->distribuirDia($registroDiario, [
        'desayuno' => 'huevos y pan',
        'almuerzo' => 'pollo y arroz',
        'cena' => 'ensalada y atún',
    ]);

    $contexto = $falso->llamadas[0]['contextoDia'];

    expect($contexto['reparto'])->toBe(['desayuno' => 0.10, 'almuerzo' => 0.50, 'cena' => 0.40])
        ->and($falso->llamadas[0]['comidas']['almuerzo']['objetivos']['calorias'])
        ->toEqualWithDelta(2112 * 0.50, 0.01);
});

it('reparte lo que queda con los pesos del reparto propio del día', function () {
    $falso = proveedorFalso();
    $registroDiario = registroDeHoyDe(usuarioParaDistribucion(), [
        'reparto_comidas' => ['desayuno' => 0.10, 'almuerzo' => 0.50, 'cena' => 0.40],
    ]);

    // Solo almuerzo y cena: el desayuno reserva su 10% y el resto se reparte
    // 50/40 entre las dos, no a partes iguales.
    app(MealDistributionService::class)->distribuirDia($registroDiario, [
        'almuerzo' => 'pollo y arroz',
        'cena' => 'ensalada y atún',
    ]);

    $comidas = $falso->llamadas[0]['comidas'];
    $disponible = 2112 * 0.90;

    expect($comidas['almuerzo']['objetivos']['calorias'])
        ->toEqualWithDelta($disponible * (0.50 / 0.90), 0.5)
        ->and($comidas['cena']['objetivos']['calorias'])
        ->toEqualWithDelta($disponible * (0.40 / 0.90), 0.5);
});
