<?php

namespace App\Services;

/**
 * Applies the device-calorie correction factor (CLAUDE.md section 5) per
 * activity type. NutritionCalculatorService owns the actual multiplication
 * and the 0.8–0.9 range validation; this service only decides which factor
 * applies to a given tipo_actividad.
 */
class ActivityCorrectionService
{
    /**
     * Factor por defecto para cualquier tipo de actividad no listado abajo.
     */
    public const FACTOR_POR_DEFECTO = 0.85;

    /**
     * Factores de corrección por tipo de actividad, dentro del rango 0.8–0.9.
     *
     * Los ejercicios cardiovasculares (caminata, trote, ciclismo, natación)
     * suelen estar bien calibrados en la mayoría de dispositivos de consumo,
     * por lo que se les asigna el valor por defecto (0.85). El entrenamiento
     * de fuerza ("pesas") es el caso donde los wearables sobreestiman más el
     * gasto calórico (los sensores ópticos de frecuencia cardíaca son menos
     * fiables con movimientos intermitentes y explosivos), así que se corrige
     * más agresivamente con el extremo inferior del rango (0.80).
     *
     * @var array<string, float>
     */
    public const FACTORES_POR_TIPO = [
        'caminata' => 0.85,
        'trote' => 0.85,
        'ciclismo' => 0.85,
        'natación' => 0.85,
        'pesas' => 0.80,
    ];

    public function __construct(private readonly NutritionCalculatorService $nutritionCalculatorService) {}

    /**
     * Calcula el factor de corrección y las calorías ajustadas para una
     * actividad. Si no se especifica un factor personalizado, se usa el
     * definido en self::FACTORES_POR_TIPO para el tipo dado, o
     * self::FACTOR_POR_DEFECTO si el tipo no está en la tabla.
     *
     * Un factor fuera del rango 0.8–0.9 (ya sea personalizado o mal
     * configurado en la tabla) es RECHAZADO: se propaga la
     * InvalidNutritionParameterException que lanza
     * NutritionCalculatorService::calculateAdjustedActivityCalories, en vez
     * de normalizarlo silenciosamente al límite más cercano. Aceptar un
     * factor fuera de rango sin avisar escondería un error de configuración
     * dentro de un cálculo de balance energético del usuario.
     *
     * @return array{factor_correccion: float, calorias_ajustadas: float}
     */
    public function calcularCaloriasAjustadas(
        string $tipo,
        float $caloriasDispositivo,
        ?float $factorPersonalizado = null,
    ): array {
        $factor = $factorPersonalizado ?? $this->factorParaTipo($tipo);

        $caloriasAjustadas = $this->nutritionCalculatorService->calculateAdjustedActivityCalories(
            $caloriasDispositivo,
            $factor,
        );

        return [
            'factor_correccion' => $factor,
            'calorias_ajustadas' => $caloriasAjustadas,
        ];
    }

    private function factorParaTipo(string $tipo): float
    {
        return self::FACTORES_POR_TIPO[mb_strtolower($tipo)] ?? self::FACTOR_POR_DEFECTO;
    }
}
