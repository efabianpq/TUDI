<?php

namespace App\Services;

use App\Exceptions\NoIngredientsAvailableException;
use App\Models\PlanComida;
use App\Models\RegistroDiario;
use App\Services\AI\NutritionAiProviderInterface;
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
 * The selection itself (a deliberately simple, explainable greedy heuristic;
 * no solver, no optimisation library) is delegated to a
 * NutritionAiProviderInterface — see RuleBasedNutritionProvider for today's
 * implementation. MVP rule: clarity over mathematically perfect accuracy.
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

    public function __construct(
        private readonly NutritionAiProviderInterface $ingredientProvider,
    ) {}

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

                $seleccion = $this->ingredientProvider->sugerirIngredientesParaComida($inventario, [
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
