<?php

use App\Exceptions\NoIngredientsAvailableException;
use App\Models\ComidaReal;
use App\Models\IngredienteDisponible;
use App\Models\RegistroDiario;
use App\Services\MealPlanGeneratorService;
use App\Services\NutritionCalculatorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Unlike NutritionCalculatorServiceTest, this service reads IngredienteDisponible
// and persists PlanComida, so it needs the Laravel test case and a database even
// though it stays a unit test of the service (no HTTP).
uses(TestCase::class, RefreshDatabase::class);

/**
 * Daily target used across the tests, straight out of the section 5 formulas:
 * TMB = 80 * 22 = 1760 → mantenimiento = 1760 * 1.5 = 2640 → objetivo con 20% de
 * déficit = 2112 kcal, con 144 g de proteína, 64 g de grasa y 240 g de carbos.
 */
function planNutricionalDelDia(): array
{
    return app(NutritionCalculatorService::class)->calculatePlan(
        pesoKg: 80.0,
        nivelActividad: 1.5,
        tipoDeficit: NutritionCalculatorService::TIPO_DEFICIT_PORCENTAJE,
        valorDeficit: 0.2,
        proteinaFactor: 1.8,
        grasaFactor: 0.8,
    );
}

/**
 * A day stocked with enough of every macro to cover the 2112 kcal target.
 */
function registroConDespensaCompleta(): RegistroDiario
{
    $registroDiario = RegistroDiario::factory()->create(['fecha' => now()->toDateString()]);

    $despensa = [
        // nombre, cantidad_g, kcal, proteína, grasa, carbos (por 100 g)
        ['Pechuga de pollo', 1000, 165, 31.0, 3.6, 0.0],
        ['Arroz integral', 1000, 350, 7.5, 2.7, 72.0],
        ['Avena', 500, 389, 16.9, 6.9, 66.0],
        ['Huevo', 500, 155, 13.0, 11.0, 1.1],
        ['Aceite de oliva', 200, 884, 0.0, 100.0, 0.0],
    ];

    foreach ($despensa as [$nombre, $cantidad, $calorias, $proteina, $grasa, $carbohidratos]) {
        IngredienteDisponible::factory()->for($registroDiario, 'registroDiario')->create([
            'nombre' => $nombre,
            'cantidad_g' => $cantidad,
            'calorias_por_100g' => $calorias,
            'proteina_por_100g' => $proteina,
            'grasa_por_100g' => $grasa,
            'carbohidratos_por_100g' => $carbohidratos,
        ]);
    }

    return $registroDiario;
}

test('la distribución porcentual entre comidas suma 100%', function () {
    $distribucion = MealPlanGeneratorService::DISTRIBUCION_COMIDAS;

    expect(array_sum($distribucion))->toEqualWithDelta(1.0, 0.000001)
        ->and(array_keys($distribucion))->toBe(['desayuno', 'almuerzo', 'cena']);
});

test('generar un plan con ingredientes suficientes produce tres comidas cercanas al objetivo diario', function () {
    $registroDiario = registroConDespensaCompleta();
    $planNutricional = planNutricionalDelDia();

    $planes = app(MealPlanGeneratorService::class)->generarPlan($registroDiario, $planNutricional);

    expect($planes)->toHaveCount(3)
        ->and($planes->pluck('tipo_comida')->all())->toBe(['desayuno', 'almuerzo', 'cena'])
        ->and($registroDiario->planesComida()->count())->toBe(3);

    $caloriasPlanificadas = $planes->sum(fn ($plan) => (float) $plan->calorias_estimadas);
    $proteinaPlanificada = $planes->sum(fn ($plan) => (float) $plan->proteina_g);

    // "Razonablemente cercana": la heurística es voluntariamente simple, así que
    // se exige un 5% de margen sobre las calorías, no una coincidencia exacta.
    //
    // La proteína se admite con un 20% porque el reparto codicioso solo sabe
    // sumar: las pasadas de grasa y carbohidratos arrastran la proteína que
    // llevan dentro sus ingredientes (el arroz aporta 7.5 g/100 g), así que el
    // total se pasa del objetivo. Pasarse de proteína manteniendo las calorías
    // en el objetivo es un resultado aceptable para el MVP; evitarlo exigiría
    // anticipar en la primera pasada lo que aportarán las siguientes.
    expect($caloriasPlanificadas)->toEqualWithDelta($planNutricional['calorias_objetivo'], $planNutricional['calorias_objetivo'] * 0.05)
        ->and($proteinaPlanificada)->toBeGreaterThanOrEqual($planNutricional['proteina_g'] * 0.9)
        ->and($proteinaPlanificada)->toEqualWithDelta($planNutricional['proteina_g'], $planNutricional['proteina_g'] * 0.20);
});

test('cada comida recibe su porcentaje de las calorías objetivo', function () {
    $registroDiario = registroConDespensaCompleta();
    $planNutricional = planNutricionalDelDia();

    $planes = app(MealPlanGeneratorService::class)->generarPlan($registroDiario, $planNutricional)
        ->keyBy('tipo_comida');

    foreach (MealPlanGeneratorService::DISTRIBUCION_COMIDAS as $tipoComida => $porcentaje) {
        $esperado = $planNutricional['calorias_objetivo'] * $porcentaje;

        expect((float) $planes[$tipoComida]->calorias_estimadas)
            ->toEqualWithDelta($esperado, $esperado * 0.05);
    }
});

test('generar un plan sin ingredientes reportados lanza NoIngredientsAvailableException', function () {
    $registroDiario = RegistroDiario::factory()->create(['fecha' => now()->toDateString()]);

    expect(fn () => app(MealPlanGeneratorService::class)->generarPlan($registroDiario, planNutricionalDelDia()))
        ->toThrow(NoIngredientsAvailableException::class);

    expect($registroDiario->planesComida()->count())->toBe(0);
});

test('el plan nunca reparte más gramos de un ingrediente de los reportados', function () {
    $registroDiario = registroConDespensaCompleta();

    $planes = app(MealPlanGeneratorService::class)->generarPlan($registroDiario, planNutricionalDelDia());

    $gramosPlanificados = [];

    foreach ($planes as $plan) {
        foreach ($plan->ingredientes_detalle as $aporte) {
            $gramosPlanificados[$aporte['ingrediente_id']] =
                ($gramosPlanificados[$aporte['ingrediente_id']] ?? 0) + $aporte['cantidad_g'];
        }
    }

    expect($gramosPlanificados)->not->toBeEmpty();

    foreach ($gramosPlanificados as $ingredienteId => $gramos) {
        expect($gramos)->toBeLessThanOrEqual((float) IngredienteDisponible::find($ingredienteId)->cantidad_g);
    }
});

test('cada comida persiste el detalle de ingredientes usados', function () {
    $registroDiario = registroConDespensaCompleta();

    $planes = app(MealPlanGeneratorService::class)->generarPlan($registroDiario, planNutricionalDelDia());

    foreach ($planes as $plan) {
        expect($plan->ingredientes_detalle)->toBeArray()->not->toBeEmpty()
            ->and($plan->ingredientes_detalle[0])->toHaveKeys([
                'ingrediente_id', 'nombre', 'cantidad_g', 'calorias', 'proteina_g', 'grasa_g', 'carbohidratos_g',
            ])
            ->and($plan->descripcion)->toContain($plan->ingredientes_detalle[0]['nombre']);

        // El detalle debe cuadrar con los totales persistidos de la comida.
        expect(array_sum(array_column($plan->ingredientes_detalle, 'calorias')))
            ->toEqualWithDelta((float) $plan->calorias_estimadas, 0.01);
    }
});

test('regenerar el plan reemplaza las comidas pendientes pero conserva las ya consumidas', function () {
    $registroDiario = registroConDespensaCompleta();
    $generador = app(MealPlanGeneratorService::class);

    $planes = $generador->generarPlan($registroDiario, planNutricionalDelDia())->keyBy('tipo_comida');
    $desayuno = $planes['desayuno'];
    ComidaReal::factory()->for($desayuno, 'planComida')->create();

    $regenerados = $generador->generarPlan($registroDiario, planNutricionalDelDia())->keyBy('tipo_comida');

    expect($registroDiario->planesComida()->count())->toBe(3)
        ->and($regenerados['desayuno']->id)->toBe($desayuno->id)
        ->and($regenerados['almuerzo']->id)->not->toBe($planes['almuerzo']->id)
        ->and($regenerados['cena']->id)->not->toBe($planes['cena']->id);
});
