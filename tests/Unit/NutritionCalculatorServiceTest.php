<?php

use App\Exceptions\InvalidNutritionParameterException;
use App\Exceptions\NegativeCarbohydrateException;
use App\Services\NutritionCalculatorService;

/**
 * Tolerance for float comparisons: the formulas chain multiplications of
 * non-representable decimals (1.55, 0.8, ...), so binary rounding noise around
 * the 12th decimal is expected and harmless.
 */
const NUTRITION_DELTA = 0.000001;

function nutritionCalculator(): NutritionCalculatorService
{
    return new NutritionCalculatorService;
}

test('calculates the plan with a percentage deficit', function () {
    // peso_kg 80 | nivel_actividad 1.55 | porcentaje 20% | proteina 2.0 | grasa 0.8
    //   TMB                    = 80 * 22            = 1760
    //   calorias_mantenimiento = 1760 * 1.55        = 2728
    //   calorias_objetivo      = 2728 * (1 - 0.20)  = 2182.4
    //   proteina_g             = 80 * 2.0           = 160    -> 160 * 4 = 640 kcal
    //   grasa_g                = 80 * 0.8           = 64     -> 64 * 9  = 576 kcal
    //   carbohidratos_kcal     = 2182.4 - 1216      = 966.4
    //   carbohidratos_g        = 966.4 / 4          = 241.6
    $plan = nutritionCalculator()->calculatePlan(
        pesoKg: 80.0,
        nivelActividad: 1.55,
        tipoDeficit: 'porcentaje',
        valorDeficit: 0.20,
        proteinaFactor: 2.0,
        grasaFactor: 0.8,
    );

    expect($plan['calorias_objetivo'])->toEqualWithDelta(2182.4, NUTRITION_DELTA)
        ->and($plan['proteina_g'])->toEqualWithDelta(160.0, NUTRITION_DELTA)
        ->and($plan['grasa_g'])->toEqualWithDelta(64.0, NUTRITION_DELTA)
        ->and($plan['carbohidratos_g'])->toEqualWithDelta(241.6, NUTRITION_DELTA);
});

test('calculates the plan with a fixed deficit', function () {
    // peso_kg 90 | nivel_actividad 1.375 | fijo 500 kcal | proteina 1.8 | grasa 0.9
    //   TMB                    = 90 * 22          = 1980
    //   calorias_mantenimiento = 1980 * 1.375     = 2722.5
    //   calorias_objetivo      = 2722.5 - 500     = 2222.5
    //   proteina_g             = 90 * 1.8         = 162    -> 162 * 4 = 648 kcal
    //   grasa_g                = 90 * 0.9         = 81     -> 81 * 9  = 729 kcal
    //   carbohidratos_kcal     = 2222.5 - 1377    = 845.5
    //   carbohidratos_g        = 845.5 / 4        = 211.375
    $plan = nutritionCalculator()->calculatePlan(
        pesoKg: 90.0,
        nivelActividad: 1.375,
        tipoDeficit: 'fijo',
        valorDeficit: 500.0,
        proteinaFactor: 1.8,
        grasaFactor: 0.9,
    );

    expect($plan['calorias_objetivo'])->toEqualWithDelta(2222.5, NUTRITION_DELTA)
        ->and($plan['proteina_g'])->toEqualWithDelta(162.0, NUTRITION_DELTA)
        ->and($plan['grasa_g'])->toEqualWithDelta(81.0, NUTRITION_DELTA)
        ->and($plan['carbohidratos_g'])->toEqualWithDelta(211.375, NUTRITION_DELTA);
});

test('matches a hand-verified real-world case end to end', function () {
    // Real profile: 68.4 kg, actividad ligera (1.375), déficit fijo de 400 kcal,
    // proteina_factor 1.8, grasa_factor 0.7. Cálculo hecho a mano, paso a paso:
    //
    //   TMB                    = 68.4 * 22            = 1504.8 kcal
    //   calorias_mantenimiento = 1504.8 * 1.375
    //                          = 1504.8 + (1504.8 * 0.375)
    //                          = 1504.8 + 564.3       = 2069.1 kcal
    //   calorias_objetivo      = 2069.1 - 400         = 1669.1 kcal
    //
    //   proteina_g             = 68.4 * 1.8           = 123.12 g
    //   proteina_kcal          = 123.12 * 4           = 492.48 kcal
    //   grasa_g                = 68.4 * 0.7           = 47.88 g
    //   grasa_kcal             = 47.88 * 9            = 430.92 kcal
    //   proteina + grasa                              = 923.40 kcal
    //
    //   carbohidratos_kcal     = 1669.1 - 923.4       = 745.7 kcal
    //   carbohidratos_g        = 745.7 / 4            = 186.425 g
    //
    // Comprobación cruzada (las kcal de los macros suman las objetivo):
    //   492.48 + 430.92 + 745.7 = 1669.1 = calorias_objetivo  ✔
    $plan = nutritionCalculator()->calculatePlan(
        pesoKg: 68.4,
        nivelActividad: 1.375,
        tipoDeficit: 'fijo',
        valorDeficit: 400.0,
        proteinaFactor: 1.8,
        grasaFactor: 0.7,
    );

    expect($plan['calorias_objetivo'])->toEqualWithDelta(1669.1, NUTRITION_DELTA)
        ->and($plan['proteina_g'])->toEqualWithDelta(123.12, NUTRITION_DELTA)
        ->and($plan['grasa_g'])->toEqualWithDelta(47.88, NUTRITION_DELTA)
        ->and($plan['carbohidratos_g'])->toEqualWithDelta(186.425, NUTRITION_DELTA);

    $kcalDeLosMacros = $plan['proteina_g'] * 4 + $plan['grasa_g'] * 9 + $plan['carbohidratos_g'] * 4;

    expect($kcalDeLosMacros)->toEqualWithDelta($plan['calorias_objetivo'], NUTRITION_DELTA);
});

test('throws when the carbohydrate kcal would be negative', function () {
    // Aggressive deficit + maximum allowed macro factors:
    //   TMB                    = 100 * 22           = 2200
    //   calorias_mantenimiento = 2200 * 1.2         = 2640
    //   calorias_objetivo      = 2640 * (1 - 0.40)  = 1584
    //   proteina_kcal          = (100 * 2.2) * 4    = 880
    //   grasa_kcal             = (100 * 1.0) * 9    = 900
    //   carbohidratos_kcal     = 1584 - 1780        = -196  -> plan inválido
    nutritionCalculator()->calculatePlan(
        pesoKg: 100.0,
        nivelActividad: 1.2,
        tipoDeficit: 'porcentaje',
        valorDeficit: 0.40,
        proteinaFactor: 2.2,
        grasaFactor: 1.0,
    );
})->throws(NegativeCarbohydrateException::class);

test('accepts a plan whose carbohydrate kcal land exactly on zero', function () {
    // Boundary: only < 0 is rejected, 0 kcal of carbs is a valid (if extreme) plan.
    // Inputs are picked to be exactly representable in binary floating point so
    // the result lands on a true 0 instead of a ±1e-13 rounding artifact.
    //   calorias_mantenimiento = (80 * 22) * 1.5    = 2640
    //   proteina_kcal          = (80 * 2.0) * 4     = 640
    //   grasa_kcal             = (80 * 0.75) * 9    = 540
    //   valor_deficit fijo     = 2640 - 1180        = 1460 -> calorias_objetivo = 1180
    //   carbohidratos_kcal     = 1180 - 1180        = 0
    $plan = nutritionCalculator()->calculatePlan(
        pesoKg: 80.0,
        nivelActividad: 1.5,
        tipoDeficit: 'fijo',
        valorDeficit: 1460.0,
        proteinaFactor: 2.0,
        grasaFactor: 0.75,
    );

    expect($plan['calorias_objetivo'])->toEqualWithDelta(1180.0, NUTRITION_DELTA)
        ->and($plan['carbohidratos_g'])->toEqualWithDelta(0.0, NUTRITION_DELTA);
});

test('rejects macro factors outside the ranges of CLAUDE.md sections 5 and 6', function (string $parametro, float $proteinaFactor, float $grasaFactor) {
    expect(fn () => nutritionCalculator()->calculatePlan(
        pesoKg: 80.0,
        nivelActividad: 1.55,
        tipoDeficit: 'porcentaje',
        valorDeficit: 0.20,
        proteinaFactor: $proteinaFactor,
        grasaFactor: $grasaFactor,
    ))->toThrow(InvalidNutritionParameterException::class, $parametro);
})->with([
    'proteina_factor below range' => ['proteina_factor', 1.5, 0.8],
    'proteina_factor above range' => ['proteina_factor', 2.3, 0.8],
    'grasa_factor below range' => ['grasa_factor', 2.0, 0.59],
    'grasa_factor above range' => ['grasa_factor', 2.0, 1.01],
]);

test('rejects an unsupported deficit type', function () {
    expect(fn () => nutritionCalculator()->calculatePlan(
        pesoKg: 80.0,
        nivelActividad: 1.55,
        tipoDeficit: 'agresivo',
        valorDeficit: 0.20,
        proteinaFactor: 2.0,
        grasaFactor: 0.8,
    ))->toThrow(InvalidNutritionParameterException::class);
});

test('adjusts device calories by the correction factor', function () {
    // calorias_actividad_ajustada = 500 * 0.85 = 425
    expect(nutritionCalculator()->calculateAdjustedActivityCalories(500.0, 0.85))
        ->toEqualWithDelta(425.0, NUTRITION_DELTA);
});

test('accepts the correction factor at both ends of the 0.8-0.9 range', function (float $factor, float $esperado) {
    expect(nutritionCalculator()->calculateAdjustedActivityCalories(500.0, $factor))
        ->toEqualWithDelta($esperado, NUTRITION_DELTA);
})->with([
    'lower bound' => [0.8, 400.0],   // 500 * 0.8 = 400
    'upper bound' => [0.9, 450.0],   // 500 * 0.9 = 450
]);

test('rejects a correction factor outside the 0.8-0.9 range', function (float $factor) {
    expect(fn () => nutritionCalculator()->calculateAdjustedActivityCalories(500.0, $factor))
        ->toThrow(InvalidNutritionParameterException::class, 'factor_correccion');
})->with([
    'just below range' => 0.79,
    'well below range' => 0.5,
    'zero' => 0.0,
    'just above range' => 0.91,
    'well above range' => 1.0,
]);

test('calculates the real daily deficit', function () {
    // deficit_diario = calorias_objetivo - calorias_consumidas + calorias_actividad_ajustada
    //                = 2182.4 - 1900 + 340 = 622.4
    // (340 = 400 kcal reportadas por el dispositivo * 0.85 de factor de corrección)
    $calculator = nutritionCalculator();

    $caloriasActividadAjustada = $calculator->calculateAdjustedActivityCalories(400.0, 0.85);

    expect($caloriasActividadAjustada)->toEqualWithDelta(340.0, NUTRITION_DELTA)
        ->and($calculator->calculateDailyDeficit(2182.4, 1900.0, $caloriasActividadAjustada))
        ->toEqualWithDelta(622.4, NUTRITION_DELTA);
});

test('returns a negative daily deficit when the user eats over the target', function () {
    // 2000 - 2500 + 200 = -300 -> superávit, no déficit
    expect(nutritionCalculator()->calculateDailyDeficit(2000.0, 2500.0, 200.0))
        ->toEqualWithDelta(-300.0, NUTRITION_DELTA);
});
