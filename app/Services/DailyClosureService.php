<?php

namespace App\Services;

use App\Exceptions\DayAlreadyClosedException;
use App\Models\ComidaReal;
use App\Models\RecomendacionSistema;
use App\Models\RegistroDiario;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Closes a day: computes the five figures of the daily closure (target vs.
 * consumed calories, adjusted activity expenditure, estimated deficit and
 * protein compliance), persists them on the RegistroDiario and freezes it.
 *
 * All the formulas come from NutritionCalculatorService (CLAUDE.md section 5);
 * nothing is recomputed here by hand.
 */
class DailyClosureService
{
    public function __construct(
        private readonly NutritionCalculatorService $calculadora,
    ) {}

    /**
     * The five closure figures for a day, ready to be rendered.
     *
     * An open day is computed live (a preview of what closing would produce);
     * a closed day is read back from its persisted snapshot, so a later change
     * to the user's profile never rewrites history.
     *
     * @return array{calorias_objetivo: float, calorias_consumidas: float, calorias_actividad_ajustada: float, deficit_diario: float, proteina_objetivo_g: float, proteina_consumida_g: float, cumplimiento_proteina_pct: float, recomendaciones: Collection<int, RecomendacionSistema>}
     */
    public function resumen(RegistroDiario $registroDiario): array
    {
        return $registroDiario->cerrado
            ? $this->resumenPersistido($registroDiario)
            : $this->calcular($registroDiario);
    }

    /**
     * "Cerrar mi día": persist the closure and mark the day as closed.
     *
     * @return array{calorias_objetivo: float, calorias_consumidas: float, calorias_actividad_ajustada: float, deficit_diario: float, proteina_objetivo_g: float, proteina_consumida_g: float, cumplimiento_proteina_pct: float, recomendaciones: Collection<int, RecomendacionSistema>}
     *
     * @throws DayAlreadyClosedException when the day was already closed
     */
    public function cerrar(RegistroDiario $registroDiario): array
    {
        if ($registroDiario->cerrado) {
            throw DayAlreadyClosedException::alCerrar($registroDiario->id);
        }

        $resumen = $this->calcular($registroDiario);

        DB::transaction(function () use ($registroDiario, $resumen) {
            $registroDiario->update([
                'calorias_objetivo_dia' => round($resumen['calorias_objetivo'], 2),
                'calorias_consumidas' => round($resumen['calorias_consumidas'], 2),
                'calorias_actividad_ajustada' => round($resumen['calorias_actividad_ajustada'], 2),
                'deficit_diario' => round($resumen['deficit_diario'], 2),
                'proteina_objetivo_g' => round($resumen['proteina_objetivo_g'], 2),
                'proteina_consumida_g' => round($resumen['proteina_consumida_g'], 2),
                'cerrado' => true,
                'cerrado_en' => now(),
            ]);

            $this->generarRecomendaciones($registroDiario, $resumen);
        });

        return $this->resumen($registroDiario->refresh());
    }

    /**
     * Explicit reopen requested by the user: unfreezes the day so it can take
     * new ComidaReal/ActividadFisica records again. The figures of the previous
     * closure are left untouched on purpose — they stay visible until the day
     * is closed again, which recomputes all of them from scratch.
     */
    public function reabrir(RegistroDiario $registroDiario): void
    {
        if (! $registroDiario->cerrado) {
            return;
        }

        $registroDiario->update([
            'cerrado' => false,
            'cerrado_en' => null,
        ]);
    }

    /**
     * Live computation of the five closure figures out of the day's records.
     *
     * @return array{calorias_objetivo: float, calorias_consumidas: float, calorias_actividad_ajustada: float, deficit_diario: float, proteina_objetivo_g: float, proteina_consumida_g: float, cumplimiento_proteina_pct: float, recomendaciones: Collection<int, RecomendacionSistema>}
     */
    private function calcular(RegistroDiario $registroDiario): array
    {
        $usuario = $registroDiario->usuario;

        $planNutricional = $this->calculadora->calculatePlan(
            (float) $usuario->peso_kg,
            (float) $usuario->nivel_actividad,
            $usuario->tipo_deficit,
            (float) $usuario->valor_deficit,
            (float) $usuario->proteina_factor,
            (float) $usuario->grasa_factor,
            // The target in force, which a confirmed RecomendacionSistema may
            // have moved away from the raw formula (CLAUDE.md section 4.10).
            $usuario->calorias_objetivo !== null ? (float) $usuario->calorias_objetivo : null,
        );

        $comidasReales = ComidaReal::whereIn(
            'plan_comida_id',
            $registroDiario->planesComida()->select('id'),
        )->get();

        $caloriasConsumidas = (float) $comidasReales->sum(fn (ComidaReal $comida) => (float) $comida->calorias_reales);
        $proteinaConsumida = (float) $comidasReales->sum(fn (ComidaReal $comida) => (float) $comida->proteina_g);
        $caloriasActividad = (float) $registroDiario->actividadesFisicas()->sum('calorias_ajustadas');

        return $this->armarResumen(
            $registroDiario,
            $planNutricional['calorias_objetivo'],
            $caloriasConsumidas,
            $caloriasActividad,
            $this->calculadora->calculateDailyDeficit(
                $planNutricional['calorias_objetivo'],
                $caloriasConsumidas,
                $caloriasActividad,
            ),
            $planNutricional['proteina_g'],
            $proteinaConsumida,
        );
    }

    /**
     * The snapshot stored when the day was closed.
     *
     * @return array{calorias_objetivo: float, calorias_consumidas: float, calorias_actividad_ajustada: float, deficit_diario: float, proteina_objetivo_g: float, proteina_consumida_g: float, cumplimiento_proteina_pct: float, recomendaciones: Collection<int, RecomendacionSistema>}
     */
    private function resumenPersistido(RegistroDiario $registroDiario): array
    {
        return $this->armarResumen(
            $registroDiario,
            (float) $registroDiario->calorias_objetivo_dia,
            (float) $registroDiario->calorias_consumidas,
            (float) $registroDiario->calorias_actividad_ajustada,
            (float) $registroDiario->deficit_diario,
            (float) $registroDiario->proteina_objetivo_g,
            (float) $registroDiario->proteina_consumida_g,
        );
    }

    /**
     * @return array{calorias_objetivo: float, calorias_consumidas: float, calorias_actividad_ajustada: float, deficit_diario: float, proteina_objetivo_g: float, proteina_consumida_g: float, cumplimiento_proteina_pct: float, recomendaciones: Collection<int, RecomendacionSistema>}
     */
    private function armarResumen(
        RegistroDiario $registroDiario,
        float $caloriasObjetivo,
        float $caloriasConsumidas,
        float $caloriasActividad,
        float $deficitDiario,
        float $proteinaObjetivo,
        float $proteinaConsumida,
    ): array {
        return [
            'calorias_objetivo' => $caloriasObjetivo,
            'calorias_consumidas' => $caloriasConsumidas,
            'calorias_actividad_ajustada' => $caloriasActividad,
            'deficit_diario' => $deficitDiario,
            'proteina_objetivo_g' => $proteinaObjetivo,
            'proteina_consumida_g' => $proteinaConsumida,
            'cumplimiento_proteina_pct' => $proteinaObjetivo > 0
                ? $proteinaConsumida / $proteinaObjetivo * 100
                : 0.0,
            'recomendaciones' => $registroDiario->recomendacionesSistema()->latest()->get(),
        ];
    }

    /**
     * Extension point for the automatic recommendations (Prompt 10).
     *
     * It stays empty on purpose: CLAUDE.md section 6 forbids deriving an
     * adjustment from a single day — recommendations come from the 7-day moving
     * averages of TrendAnalyticsService, and are always persisted as a pending
     * RecomendacionSistema that the user has to confirm. When that lands, this
     * is where the closure hands the day over to it.
     *
     * @param  array{calorias_objetivo: float, calorias_consumidas: float, calorias_actividad_ajustada: float, deficit_diario: float, proteina_objetivo_g: float, proteina_consumida_g: float, cumplimiento_proteina_pct: float, recomendaciones: Collection<int, RecomendacionSistema>}  $resumen
     * @return Collection<int, RecomendacionSistema>
     */
    private function generarRecomendaciones(RegistroDiario $registroDiario, array $resumen): Collection
    {
        return collect();
    }
}
