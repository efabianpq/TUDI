<?php

namespace App\Services;

use App\Exceptions\NoIngredientsAvailableException;
use App\Models\PlanComida;
use App\Models\RegistroDiario;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Builds the day's meal plan (desayuno / almuerzo / cena) out of the ingredients
 * the user reported as available for that RegistroDiario.
 *
 * It does NOT recompute calorie targets: it receives the result of
 * NutritionCalculatorService::calculatePlan() — that service is the single
 * source of truth for the formulas of CLAUDE.md section 5.
 *
 * The selection is a deliberately simple, explainable greedy heuristic (see
 * seleccionarIngredientes()); no solver, no optimisation library. MVP rule:
 * clarity over mathematically perfect accuracy.
 */
class MealPlanGeneratorService
{
    /**
     * Share of the daily calorie/macro targets assigned to each meal.
     * Single place where the split is defined — must always sum to 1.0.
     *
     * @var array<string, float>
     */
    public const DISTRIBUCION_COMIDAS = [
        'desayuno' => 0.25,
        'almuerzo' => 0.40,
        'cena' => 0.35,
    ];

    /**
     * Macro targets the heuristic tries to fill, in order of priority, mapped to
     * the ingredient column holding that macro's density. Protein first because
     * it is the macro the user must hit; carbohydrates last because section 5
     * derives them as whatever calories are left over.
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
     * ("0.4 g de aceite"), so they are skipped. It costs a couple of kcal of
     * accuracy per meal and buys a readable plan.
     */
    private const GRAMOS_MINIMOS_POR_INGREDIENTE = 1.0;

    /**
     * Generate and persist the three meals of the day for a RegistroDiario.
     *
     * Meals that already have a ComidaReal logged are left untouched (CLAUDE.md
     * section 4: the "planificado vs. ejecutado" history must be preserved);
     * every other meal of the day is replaced. The returned collection always
     * contains one PlanComida per key of DISTRIBUCION_COMIDAS, in that order.
     *
     * @param  array{calorias_objetivo: float, proteina_g: float, grasa_g: float, carbohidratos_g: float}  $planNutricional
     *                                                                                                                       result of NutritionCalculatorService::calculatePlan()
     * @return Collection<int, PlanComida>
     *
     * @throws NoIngredientsAvailableException when the day has no reported ingredients
     */
    public function generarPlan(RegistroDiario $registroDiario, array $planNutricional): Collection
    {
        $inventario = $this->inventarioDisponible($registroDiario);

        if ($inventario === []) {
            throw NoIngredientsAvailableException::paraRegistroDiario($registroDiario->id);
        }

        return DB::transaction(function () use ($registroDiario, $planNutricional, &$inventario) {
            $yaConsumidas = $registroDiario->planesComida()
                ->has('comidaReal')
                ->get()
                ->keyBy('tipo_comida');

            $registroDiario->planesComida()->doesntHave('comidaReal')->delete();

            $planes = new Collection;

            foreach (self::DISTRIBUCION_COMIDAS as $tipoComida => $porcentaje) {
                if ($yaConsumidas->has($tipoComida)) {
                    $planes->push($yaConsumidas->get($tipoComida));

                    continue;
                }

                $seleccion = $this->seleccionarIngredientes($inventario, [
                    'calorias' => $planNutricional['calorias_objetivo'] * $porcentaje,
                    'proteina_g' => $planNutricional['proteina_g'] * $porcentaje,
                    'grasa_g' => $planNutricional['grasa_g'] * $porcentaje,
                    'carbohidratos_g' => $planNutricional['carbohidratos_g'] * $porcentaje,
                ]);

                $planes->push($registroDiario->planesComida()->create(
                    $this->atributosDelPlan($tipoComida, $seleccion),
                ));
            }

            return $planes;
        });
    }

    /**
     * Reported ingredients as a mutable stock list, so that the same 200 g of
     * chicken cannot be assigned to breakfast, lunch and dinner at once.
     *
     * @return array<int, array<string, mixed>>
     */
    private function inventarioDisponible(RegistroDiario $registroDiario): array
    {
        return $registroDiario->ingredientesDisponibles()
            ->orderBy('id')
            ->get()
            ->map(fn ($ingrediente) => [
                'ingrediente_id' => $ingrediente->id,
                'nombre' => $ingrediente->nombre,
                'disponible_g' => (float) $ingrediente->cantidad_g,
                'calorias_por_100g' => (float) $ingrediente->calorias_por_100g,
                'proteina_por_100g' => (float) $ingrediente->proteina_por_100g,
                'grasa_por_100g' => (float) $ingrediente->grasa_por_100g,
                'carbohidratos_por_100g' => (float) $ingrediente->carbohidratos_por_100g,
            ])
            ->values()
            ->all();
    }

    /**
     * Greedy heuristic for one meal: one pass per macro (protein, then fat, then
     * carbohydrates), each walking the stock from the most macro-dense ingredient
     * down and taking the grams needed to close that macro's gap — never taking
     * more calories than the meal's calorie budget allows.
     *
     * Calories need no pass of their own: section 5 builds the macro targets so
     * that 4·protein + 9·fat + 4·carbs equals the calorie target, so filling the
     * macros fills the calories. The final calorie pass is only a fallback for
     * when the stock cannot cover some macro (no carb source reported, say) and
     * the meal would otherwise come up short on energy.
     *
     * Whatever grams are taken are removed from $inventario, so the next meal
     * only sees what is left and the same 200 g of chicken is never planned
     * twice.
     *
     * @param  array<int, array<string, mixed>>  $inventario  consumed grams are subtracted in place
     * @param  array{calorias: float, proteina_g: float, grasa_g: float, carbohidratos_g: float}  $objetivos  this meal's share of the day
     * @return array<int, array<string, mixed>> the ingredients (and grams) chosen for this meal
     */
    private function seleccionarIngredientes(array &$inventario, array $objetivos): array
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
     * Move $gramos of $inventario[$indice] into $seleccion, updating the stock
     * and every remaining calorie/macro gap of the meal — a portion taken to
     * close the protein gap also shrinks the fat and carbohydrate gaps, which is
     * what keeps the later passes from over-serving.
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

        // The same ingredient can be picked in several passes: merge instead of
        // listing it twice on the plan.
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

    /**
     * Totals + human readable description for one PlanComida row.
     *
     * @param  array<int, array<string, mixed>>  $seleccion
     * @return array<string, mixed>
     */
    private function atributosDelPlan(string $tipoComida, array $seleccion): array
    {
        $sumar = fn (string $campo) => array_sum(array_column($seleccion, $campo));

        $descripcion = $seleccion === []
            ? 'Sin ingredientes suficientes para esta comida.'
            : implode(', ', array_map(
                fn (array $aporte) => sprintf('%s (%.0f g)', $aporte['nombre'], $aporte['cantidad_g']),
                $seleccion,
            ));

        return [
            'tipo_comida' => $tipoComida,
            'descripcion' => $descripcion,
            'ingredientes_detalle' => $seleccion,
            'calorias_estimadas' => round($sumar('calorias'), 2),
            'proteina_g' => round($sumar('proteina_g'), 2),
            'grasa_g' => round($sumar('grasa_g'), 2),
            'carbohidratos_g' => round($sumar('carbohidratos_g'), 2),
        ];
    }
}
