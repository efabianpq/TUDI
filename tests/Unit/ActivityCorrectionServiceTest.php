<?php

use App\Exceptions\InvalidNutritionParameterException;
use App\Services\ActivityCorrectionService;
use App\Services\NutritionCalculatorService;

beforeEach(function () {
    $this->service = new ActivityCorrectionService(new NutritionCalculatorService);
});

test('applies the configured factor for a known activity type', function () {
    $resultado = $this->service->calcularCaloriasAjustadas('pesas', 400);

    expect($resultado['factor_correccion'])->toBe(ActivityCorrectionService::FACTORES_POR_TIPO['pesas'])
        ->and($resultado['factor_correccion'])->toBe(0.80)
        ->and($resultado['calorias_ajustadas'])->toBe(400 * 0.80);
});

test('falls back to the default factor for an unlisted activity type', function () {
    $resultado = $this->service->calcularCaloriasAjustadas('yoga', 200);

    expect($resultado['factor_correccion'])->toBe(ActivityCorrectionService::FACTOR_POR_DEFECTO)
        ->and($resultado['calorias_ajustadas'])->toBe(200 * ActivityCorrectionService::FACTOR_POR_DEFECTO);
});

test('is case-insensitive when matching the activity type', function () {
    $resultado = $this->service->calcularCaloriasAjustadas('PESAS', 400);

    expect($resultado['factor_correccion'])->toBe(0.80);
});

test('a custom factor outside the 0.8-0.9 range is rejected, not normalized', function () {
    $this->service->calcularCaloriasAjustadas('caminata', 300, 0.5);
})->throws(InvalidNutritionParameterException::class);

test('a custom factor within range overrides the table', function () {
    $resultado = $this->service->calcularCaloriasAjustadas('caminata', 300, 0.9);

    expect($resultado['factor_correccion'])->toBe(0.9)
        ->and($resultado['calorias_ajustadas'])->toBe(270.0);
});
