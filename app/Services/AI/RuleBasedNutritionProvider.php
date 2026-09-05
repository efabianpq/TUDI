<?php

namespace App\Services\AI;

use App\Services\RulesEngineService;
use InvalidArgumentException;

/**
 * Implementación de NutritionAiProviderInterface usada hoy en producción: una
 * heurística de reglas explicable, sin ningún modelo de IA de por medio.
 *
 * sugerirIngredientesParaComida() es la heurística codiciosa que antes vivía
 * en MealPlanGeneratorService (una pasada por macro, proteína → grasa →
 * carbohidratos — ver CLAUDE.md sección 4.2 para la justificación completa
 * de por qué ese orden y no "proteína + lo más calórico").
 *
 * generarTextoRecomendacion() son las plantillas de texto que antes vivían en
 * RulesEngineService (sección 4.6): el motor de reglas sigue siendo el único
 * que decide *si* corresponde una recomendación, esta clase solo la redacta.
 */
class RuleBasedNutritionProvider implements NutritionAiProviderInterface
{
    /**
     * Macro targets the heuristic tries to fill, in order of priority, mapped
     * to the ingredient column holding that macro's density. Protein first
     * because it is the macro the user must hit; carbohydrates last because
     * section 5 derives them as whatever calories are left over.
     *
     * @var array<string, string>
     */
    private const PASADAS_MACRO = [
        'proteina_g' => 'proteina_por_100g',
        'grasa_g' => 'grasa_por_100g',
        'carbohidratos_g' => 'carbohidratos_por_100g',
    ];

    /**
     * Ingredient portions smaller than this are not worth putting on a plan
     * ("0.4 g de aceite"), so they are skipped.
     */
    private const GRAMOS_MINIMOS_POR_INGREDIENTE = 1.0;

    public function sugerirIngredientesParaComida(array &$inventario, array $objetivos): array
    {
        $seleccion = [];
        $restantes = $objetivos;

        foreach (self::PASADAS_MACRO as $macro => $densidad) {
            foreach ($this->indicesOrdenadosPor($inventario, $densidad) as $indice) {
                if ($restantes[$macro] <= 0 || $restantes['calorias'] <= 0) {
                    break;
                }

                $porcion = $inventario[$indice];

                if ($porcion[$densidad] <= 0) {
                    continue;
                }

                $gramos = min(
                    $restantes[$macro] * 100 / $porcion[$densidad],
                    $porcion['disponible_g'],
                    $this->gramosQueCabenEn($restantes['calorias'], $porcion),
                );

                $this->tomar($inventario, $indice, $gramos, $seleccion, $restantes);
            }
        }

        foreach ($this->indicesOrdenadosPor($inventario, 'calorias_por_100g') as $indice) {
            if ($restantes['calorias'] <= 0) {
                break;
            }

            $porcion = $inventario[$indice];

            if ($porcion['calorias_por_100g'] <= 0) {
                continue;
            }

            $gramos = min($porcion['disponible_g'], $this->gramosQueCabenEn($restantes['calorias'], $porcion));

            $this->tomar($inventario, $indice, $gramos, $seleccion, $restantes);
        }

        return array_values($seleccion);
    }

    public function generarTextoRecomendacion(string $tipo, array $contexto): string
    {
        return match ($tipo) {
            RulesEngineService::TIPO_AJUSTE_CALORICO => $this->textoAjusteCalorico($contexto),
            RulesEngineService::TIPO_ALERTA_ESTANCAMIENTO => $this->textoAlertaEstancamiento($contexto),
            default => throw new InvalidArgumentException("Tipo de recomendación desconocido: {$tipo}"),
        };
    }

    /**
     * @param  array{direccion: string, porcentaje_perdida_semanal: float, calorias_actuales: float, calorias_sugeridas: float, ajuste_kcal_sugerido: float}  $contexto
     */
    private function textoAjusteCalorico(array $contexto): string
    {
        return $contexto['direccion'] === 'reducir'
            ? sprintf(
                'Tu pérdida de peso promedio de las últimas semanas es %.2f%% semanal, por debajo del 0.5%% recomendado. '.
                'Sugerimos reducir tu objetivo calórico en %d kcal: de %s a %s kcal.',
                $contexto['porcentaje_perdida_semanal'],
                $contexto['ajuste_kcal_sugerido'],
                number_format($contexto['calorias_actuales'], 0),
                number_format($contexto['calorias_sugeridas'], 0),
            )
            : sprintf(
                'Tu pérdida de peso promedio de las últimas semanas es %.2f%% semanal, por encima del 1%% recomendado. '.
                'Sugerimos aumentar tu objetivo calórico en %d kcal: de %s a %s kcal.',
                $contexto['porcentaje_perdida_semanal'],
                $contexto['ajuste_kcal_sugerido'],
                number_format($contexto['calorias_actuales'], 0),
                number_format($contexto['calorias_sugeridas'], 0),
            );
    }

    /**
     * @param  array{umbral_estancamiento_kg: float, semanas_estancamiento: int}  $contexto
     */
    private function textoAlertaEstancamiento(array $contexto): string
    {
        return sprintf(
            'Tu peso se ha mantenido prácticamente igual (variación menor a %.1f kg) durante las últimas %d semanas. '.
            'Esto puede indicar un estancamiento; es solo informativo, no cambia tu objetivo calórico automáticamente.',
            $contexto['umbral_estancamiento_kg'],
            $contexto['semanas_estancamiento'],
        );
    }

    /**
     * Grams of an ingredient that fit in a calorie budget (INF when it has no
     * calories, so it never caps a protein pick).
     *
     * @param  array<string, mixed>  $porcion
     */
    private function gramosQueCabenEn(float $caloriasRestantes, array $porcion): float
    {
        if ($porcion['calorias_por_100g'] <= 0) {
            return INF;
        }

        return $caloriasRestantes * 100 / $porcion['calorias_por_100g'];
    }

    /**
     * Move $gramos of $inventario[$indice] into $seleccion, updating the
     * stock and every remaining calorie/macro gap of the meal.
     *
     * @param  array<int, array<string, mixed>>  $inventario
     * @param  array<int, array<string, mixed>>  $seleccion
     * @param  array{calorias: float, proteina_g: float, grasa_g: float, carbohidratos_g: float}  $restantes
     */
    private function tomar(
        array &$inventario,
        int $indice,
        float $gramos,
        array &$seleccion,
        array &$restantes,
    ): void {
        $gramos = round($gramos, 2);

        if ($gramos < self::GRAMOS_MINIMOS_POR_INGREDIENTE) {
            return;
        }

        $porcion = $inventario[$indice];
        $inventario[$indice]['disponible_g'] = round($porcion['disponible_g'] - $gramos, 2);

        $aporte = [
            'ingrediente_id' => $porcion['ingrediente_id'],
            'nombre' => $porcion['nombre'],
            'cantidad_g' => $gramos,
            'calorias' => $gramos * $porcion['calorias_por_100g'] / 100,
            'proteina_g' => $gramos * $porcion['proteina_por_100g'] / 100,
            'grasa_g' => $gramos * $porcion['grasa_por_100g'] / 100,
            'carbohidratos_g' => $gramos * $porcion['carbohidratos_por_100g'] / 100,
        ];

        $restantes['calorias'] -= $aporte['calorias'];

        foreach (array_keys(self::PASADAS_MACRO) as $macro) {
            $restantes[$macro] -= $aporte[$macro];
        }

        // The same ingredient can be picked in several passes: merge instead
        // of listing it twice on the plan.
        if (isset($seleccion[$indice])) {
            foreach (['cantidad_g', 'calorias', 'proteina_g', 'grasa_g', 'carbohidratos_g'] as $campo) {
                $aporte[$campo] += $seleccion[$indice][$campo];
            }
        }

        $seleccion[$indice] = $aporte;
    }

    /**
     * Indices of the stock still holding grams, sorted by $campo descending.
     *
     * @param  array<int, array<string, mixed>>  $inventario
     * @return array<int, int>
     */
    private function indicesOrdenadosPor(array $inventario, string $campo): array
    {
        $indices = array_keys(array_filter(
            $inventario,
            fn (array $porcion) => $porcion['disponible_g'] >= self::GRAMOS_MINIMOS_POR_INGREDIENTE,
        ));

        usort($indices, fn (int $a, int $b) => $inventario[$b][$campo] <=> $inventario[$a][$campo]);

        return $indices;
    }
}
