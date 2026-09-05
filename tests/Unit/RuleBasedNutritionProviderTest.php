<?php

use App\Services\AI\NutritionAiProviderInterface;
use App\Services\AI\RuleBasedNutritionProvider;
use App\Services\RulesEngineService;
use Tests\TestCase;

// Pure unit tests: no Eloquent involved, so no RefreshDatabase — but the app
// container (and its bindings) still needs bootstrapping to resolve
// NutritionAiProviderInterface, so this file opts into TestCase explicitly
// (tests/Pest.php only auto-applies it to Feature tests).
uses(TestCase::class);

it('resuelve la interfaz al RuleBasedNutritionProvider vía el contenedor', function () {
    $provider = app(NutritionAiProviderInterface::class);

    expect($provider)->toBeInstanceOf(RuleBasedNutritionProvider::class);
});

it('sugiere ingredientes cubriendo proteína primero, luego grasa, luego carbohidratos', function () {
    $inventario = [
        ['ingrediente_id' => 1, 'nombre' => 'Pechuga de pollo', 'disponible_g' => 1000.0, 'calorias_por_100g' => 165.0, 'proteina_por_100g' => 31.0, 'grasa_por_100g' => 3.6, 'carbohidratos_por_100g' => 0.0],
        ['ingrediente_id' => 2, 'nombre' => 'Arroz integral', 'disponible_g' => 1000.0, 'calorias_por_100g' => 350.0, 'proteina_por_100g' => 7.5, 'grasa_por_100g' => 2.7, 'carbohidratos_por_100g' => 72.0],
        ['ingrediente_id' => 3, 'nombre' => 'Aceite de oliva', 'disponible_g' => 200.0, 'calorias_por_100g' => 884.0, 'proteina_por_100g' => 0.0, 'grasa_por_100g' => 100.0, 'carbohidratos_por_100g' => 0.0],
    ];

    $objetivos = [
        'calorias' => 528.0, // 25% of 2112 kcal, same target used by MealPlanGeneratorServiceTest
        'proteina_g' => 36.0,
        'grasa_g' => 16.0,
        'carbohidratos_g' => 60.0,
    ];

    $provider = app(NutritionAiProviderInterface::class);
    $seleccion = $provider->sugerirIngredientesParaComida($inventario, $objetivos);

    expect($seleccion)->not->toBeEmpty();

    $sumar = fn (string $campo) => array_sum(array_column($seleccion, $campo));

    // Calories must land close to the meal's budget (same ±5% margin the
    // generator's own tests use), and the stock must have been drawn down.
    expect($sumar('calorias'))->toBeGreaterThan(0)
        ->and($sumar('calorias'))->toBeLessThanOrEqual($objetivos['calorias'] * 1.05)
        ->and(collect($inventario)->firstWhere('ingrediente_id', 1)['disponible_g'])->toBeLessThan(1000.0);
});

it('descuenta el inventario in place para que una segunda comida no reutilice lo ya asignado', function () {
    $inventario = [
        ['ingrediente_id' => 1, 'nombre' => 'Pechuga de pollo', 'disponible_g' => 150.0, 'calorias_por_100g' => 165.0, 'proteina_por_100g' => 31.0, 'grasa_por_100g' => 3.6, 'carbohidratos_por_100g' => 0.0],
    ];

    $provider = app(NutritionAiProviderInterface::class);

    $primeraComida = $provider->sugerirIngredientesParaComida($inventario, [
        'calorias' => 200.0,
        'proteina_g' => 30.0,
        'grasa_g' => 5.0,
        'carbohidratos_g' => 0.0,
    ]);

    expect($primeraComida)->not->toBeEmpty();

    $disponibleTrasPrimera = $inventario[0]['disponible_g'];

    $segundaComida = $provider->sugerirIngredientesParaComida($inventario, [
        'calorias' => 200.0,
        'proteina_g' => 30.0,
        'grasa_g' => 5.0,
        'carbohidratos_g' => 0.0,
    ]);

    expect($inventario[0]['disponible_g'])->toBeLessThan($disponibleTrasPrimera)
        ->and(array_sum(array_column($segundaComida, 'cantidad_g')))
        ->toBeLessThanOrEqual($disponibleTrasPrimera);
});

it('genera el texto de ajuste calórico para reducir, consistente con RulesEngineService', function () {
    $provider = app(NutritionAiProviderInterface::class);

    $texto = $provider->generarTextoRecomendacion(RulesEngineService::TIPO_AJUSTE_CALORICO, [
        'direccion' => 'reducir',
        'porcentaje_perdida_semanal' => 0.3,
        'calorias_actuales' => 2000.0,
        'calorias_sugeridas' => 1850.0,
        'ajuste_kcal_sugerido' => 150.0,
    ]);

    expect($texto)->toContain('0.30%')
        ->toContain('reducir')
        ->toContain('150 kcal')
        ->toContain('2,000')
        ->toContain('1,850');
});

it('genera el texto de ajuste calórico para aumentar', function () {
    $provider = app(NutritionAiProviderInterface::class);

    $texto = $provider->generarTextoRecomendacion(RulesEngineService::TIPO_AJUSTE_CALORICO, [
        'direccion' => 'aumentar',
        'porcentaje_perdida_semanal' => 1.5,
        'calorias_actuales' => 2000.0,
        'calorias_sugeridas' => 2150.0,
        'ajuste_kcal_sugerido' => 150.0,
    ]);

    expect($texto)->toContain('1.50%')->toContain('aumentar');
});

it('genera el texto de alerta de estancamiento', function () {
    $provider = app(NutritionAiProviderInterface::class);

    $texto = $provider->generarTextoRecomendacion(RulesEngineService::TIPO_ALERTA_ESTANCAMIENTO, [
        'umbral_estancamiento_kg' => 0.2,
        'semanas_estancamiento' => 3,
    ]);

    expect($texto)->toContain('estancamiento')->toContain('3 semanas');
});

it('lanza una excepción ante un tipo de recomendación desconocido', function () {
    $provider = new RuleBasedNutritionProvider;

    $provider->generarTextoRecomendacion('tipo_inexistente', []);
})->throws(InvalidArgumentException::class);
