<?php

namespace App\Services;

use App\Exceptions\InvalidNutritionParameterException;
use App\Exceptions\NegativeCarbohydrateException;

/**
 * Source of truth for the nutritional algorithm defined in CLAUDE.md section 5.
 *
 * TMB is deliberately computed as peso_kg * 22: estatura_m, edad and sexo are
 * captured on the User model but intentionally unused here. That is a documented
 * MVP product decision (CLAUDE.md section 5), not a bug — do not "fix" it by
 * switching to Mifflin-St Jeor or any other formula without an explicit request.
 *
 * Results are returned unrounded; rounding to the decimal precision of each
 * column is the responsibility of the persistence layer.
 */
class NutritionCalculatorService
{
    public const TIPO_DEFICIT_PORCENTAJE = 'porcentaje';

    public const TIPO_DEFICIT_FIJO = 'fijo';

    public const PROTEINA_FACTOR_MIN = 1.6;

    public const PROTEINA_FACTOR_MAX = 2.2;

    public const GRASA_FACTOR_MIN = 0.6;

    public const GRASA_FACTOR_MAX = 1.0;

    public const FACTOR_CORRECCION_MIN = 0.8;

    public const FACTOR_CORRECCION_MAX = 0.9;

    private const TMB_FACTOR = 22;

    private const KCAL_POR_GRAMO_PROTEINA = 4;

    private const KCAL_POR_GRAMO_GRASA = 9;

    private const KCAL_POR_GRAMO_CARBOHIDRATO = 4;

    /**
     * Compute the daily calorie target and the macronutrient split for a user.
     *
     * $caloriasObjetivoVigente is the target currently in force for the user
     * (users.calorias_objetivo). When it is set it replaces the target derived
     * from TMB and the deficit, because CLAUDE.md section 6 lets a confirmed
     * RecomendacionSistema move that target away from the raw formula. The
     * macros keep deriving exactly as section 5 says: protein and fat from
     * weight × factor, carbohydrates as whatever calories are left over — so
     * the negative-carbohydrate validation still applies to the new target.
     *
     * @param  string  $tipoDeficit  self::TIPO_DEFICIT_PORCENTAJE ("porcentaje") or self::TIPO_DEFICIT_FIJO ("fijo")
     * @param  float  $valorDeficit  fraction (0.2 = 20%) when "porcentaje", kcal when "fijo"
     * @param  float|null  $caloriasObjetivoVigente  target in force, or null to derive it from the deficit
     * @return array{calorias_objetivo: float, proteina_g: float, grasa_g: float, carbohidratos_g: float}
     *
     * @throws InvalidNutritionParameterException when proteina_factor/grasa_factor are out of range or tipo_deficit is unknown
     * @throws NegativeCarbohydrateException when the resulting carbohydrate kcal are negative
     */
    public function calculatePlan(
        float $pesoKg,
        float $nivelActividad,
        string $tipoDeficit,
        float $valorDeficit,
        float $proteinaFactor,
        float $grasaFactor,
        ?float $caloriasObjetivoVigente = null,
    ): array {
        // CLAUDE.md section 6: always validate the macro factors before computing a plan.
        $this->assertInRange('proteina_factor', $proteinaFactor, self::PROTEINA_FACTOR_MIN, self::PROTEINA_FACTOR_MAX);
        $this->assertInRange('grasa_factor', $grasaFactor, self::GRASA_FACTOR_MIN, self::GRASA_FACTOR_MAX);

        $tmb = $pesoKg * self::TMB_FACTOR;
        $caloriasMantenimiento = $tmb * $nivelActividad;

        $caloriasObjetivo = $caloriasObjetivoVigente ?? match ($tipoDeficit) {
            self::TIPO_DEFICIT_PORCENTAJE => $caloriasMantenimiento * (1 - $valorDeficit),
            self::TIPO_DEFICIT_FIJO => $caloriasMantenimiento - $valorDeficit,
            default => throw InvalidNutritionParameterException::unsupportedDeficitType($tipoDeficit),
        };

        $proteinaG = $pesoKg * $proteinaFactor;
        $proteinaKcal = $proteinaG * self::KCAL_POR_GRAMO_PROTEINA;

        $grasaG = $pesoKg * $grasaFactor;
        $grasaKcal = $grasaG * self::KCAL_POR_GRAMO_GRASA;

        $carbohidratosKcal = $caloriasObjetivo - ($proteinaKcal + $grasaKcal);

        if ($carbohidratosKcal < 0) {
            throw NegativeCarbohydrateException::fromKcal(
                $carbohidratosKcal,
                $caloriasObjetivo,
                $proteinaKcal,
                $grasaKcal,
            );
        }

        return [
            'calorias_objetivo' => $caloriasObjetivo,
            'proteina_g' => $proteinaG,
            'grasa_g' => $grasaG,
            'carbohidratos_g' => $carbohidratosKcal / self::KCAL_POR_GRAMO_CARBOHIDRATO,
        ];
    }

    /**
     * calorias_actividad_ajustada = calorias_dispositivo * factor_correccion.
     *
     * @throws InvalidNutritionParameterException when factor_correccion is outside 0.8–0.9
     */
    public function calculateAdjustedActivityCalories(float $caloriasDispositivo, float $factorCorreccion): float
    {
        $this->assertInRange(
            'factor_correccion',
            $factorCorreccion,
            self::FACTOR_CORRECCION_MIN,
            self::FACTOR_CORRECCION_MAX,
        );

        return $caloriasDispositivo * $factorCorreccion;
    }

    /**
     * deficit_diario = calorias_objetivo - calorias_consumidas + calorias_actividad_ajustada.
     */
    public function calculateDailyDeficit(
        float $caloriasObjetivo,
        float $caloriasConsumidas,
        float $caloriasActividadAjustada,
    ): float {
        return $caloriasObjetivo - $caloriasConsumidas + $caloriasActividadAjustada;
    }

    /**
     * @throws InvalidNutritionParameterException
     */
    private function assertInRange(string $parametro, float $valor, float $min, float $max): void
    {
        if ($valor < $min || $valor > $max) {
            throw InvalidNutritionParameterException::outOfRange($parametro, $valor, $min, $max);
        }
    }
}
