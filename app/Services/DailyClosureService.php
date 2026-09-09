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
        private readonly TrendAnalyticsService $analiticaTendencias,
        private readonly RulesEngineService $reglas,
    ) {}

    /**
     * The five closure figures for a day, ready to be rendered.
     *
     * An open day is computed live (a preview of what closing would produce);
     * a closed day is read back from its persisted snapshot, so a later change
     * to the user's profile never rewrites history.
     *
     * @return array{calorias_objetivo: float, calorias_consumidas: float, calorias_actividad_ajustada: float, deficit_diario: float, proteina_objetivo_g: float, proteina_consumida_g: float, grasa_objetivo_g: ?float, grasa_consumida_g: ?float, carbohidratos_objetivo_g: ?float, carbohidratos_consumidos_g: ?float, cumplimiento_proteina_pct: float, recomendaciones: Collection<int, RecomendacionSistema>}
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
     * @return array{calorias_objetivo: float, calorias_consumidas: float, calorias_actividad_ajustada: float, deficit_diario: float, proteina_objetivo_g: float, proteina_consumida_g: float, grasa_objetivo_g: ?float, grasa_consumida_g: ?float, carbohidratos_objetivo_g: ?float, carbohidratos_consumidos_g: ?float, cumplimiento_proteina_pct: float, recomendaciones: Collection<int, RecomendacionSistema>}
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
                'grasa_objetivo_g' => round($resumen['grasa_objetivo_g'], 2),
                'grasa_consumida_g' => round($resumen['grasa_consumida_g'], 2),
                'carbohidratos_objetivo_g' => round($resumen['carbohidratos_objetivo_g'], 2),
                'carbohidratos_consumidos_g' => round($resumen['carbohidratos_consumidos_g'], 2),
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
     * Por qué la sección de recomendaciones de este día está (o no está) vacía.
     *
     * El motor solo sugiere ajustes sobre promedios móviles de 7 días
     * (sección 8), así que en las primeras semanas no tiene nada que decir. Sin
     * esto la pantalla solo mostraba "sin recomendaciones", que no distingue
     * "todavía no hay historial" de "tu ritmo es el correcto" — dos cosas muy
     * distintas para quien está empezando.
     *
     * Es una lectura, no un cálculo nuevo: reutiliza la misma ventana de
     * TrendAnalyticsService que usa el cierre.
     *
     * @return array{dias_con_datos: int, dias_necesarios: int, dias_cerrados: int, dias_con_peso: int, dias_con_peso_anterior: int, historial_completo: bool, comparacion_de_peso_lista: bool, listo: bool, ritmo_pct: float|null}
     */
    public function diagnosticoRecomendaciones(RegistroDiario $registroDiario): array
    {
        $tendencia = $this->analiticaTendencias->calcular($registroDiario->usuario, $registroDiario->fecha);

        $historialCompleto = $tendencia['datos_suficientes'];
        $comparacionLista = $tendencia['porcentaje_perdida_semanal'] !== null;

        return [
            'dias_con_datos' => $tendencia['dias_con_datos'],
            'dias_necesarios' => TrendAnalyticsService::DIAS_VENTANA,
            'dias_cerrados' => $tendencia['dias_cerrados'],
            'dias_con_peso' => $tendencia['dias_con_peso'],
            'dias_con_peso_anterior' => $tendencia['dias_con_peso_anterior'],
            'historial_completo' => $historialCompleto,
            'comparacion_de_peso_lista' => $comparacionLista,
            'listo' => $historialCompleto && $comparacionLista,
            'ritmo_pct' => $tendencia['porcentaje_perdida_semanal'],
        ];
    }

    /**
     * Live computation of the five closure figures out of the day's records.
     *
     * @return array{calorias_objetivo: float, calorias_consumidas: float, calorias_actividad_ajustada: float, deficit_diario: float, proteina_objetivo_g: float, proteina_consumida_g: float, grasa_objetivo_g: ?float, grasa_consumida_g: ?float, carbohidratos_objetivo_g: ?float, carbohidratos_consumidos_g: ?float, cumplimiento_proteina_pct: float, recomendaciones: Collection<int, RecomendacionSistema>}
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
        $grasaConsumida = (float) $comidasReales->sum(fn (ComidaReal $comida) => (float) $comida->grasa_g);
        $carbohidratosConsumidos = (float) $comidasReales->sum(fn (ComidaReal $comida) => (float) $comida->carbohidratos_g);
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
            $planNutricional['grasa_g'],
            $grasaConsumida,
            $planNutricional['carbohidratos_g'],
            $carbohidratosConsumidos,
        );
    }

    /**
     * The snapshot stored when the day was closed.
     *
     * @return array{calorias_objetivo: float, calorias_consumidas: float, calorias_actividad_ajustada: float, deficit_diario: float, proteina_objetivo_g: float, proteina_consumida_g: float, grasa_objetivo_g: ?float, grasa_consumida_g: ?float, carbohidratos_objetivo_g: ?float, carbohidratos_consumidos_g: ?float, cumplimiento_proteina_pct: float, recomendaciones: Collection<int, RecomendacionSistema>}
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
            // Nullable: los días cerrados antes de que el snapshot incluyera
            // grasa y carbohidratos no tienen estas cifras, y se muestran como
            // ausentes en vez de como cero (sección 5.5).
            $registroDiario->grasa_objetivo_g !== null ? (float) $registroDiario->grasa_objetivo_g : null,
            $registroDiario->grasa_consumida_g !== null ? (float) $registroDiario->grasa_consumida_g : null,
            $registroDiario->carbohidratos_objetivo_g !== null ? (float) $registroDiario->carbohidratos_objetivo_g : null,
            $registroDiario->carbohidratos_consumidos_g !== null ? (float) $registroDiario->carbohidratos_consumidos_g : null,
        );
    }

    /**
     * @return array{calorias_objetivo: float, calorias_consumidas: float, calorias_actividad_ajustada: float, deficit_diario: float, proteina_objetivo_g: float, proteina_consumida_g: float, grasa_objetivo_g: ?float, grasa_consumida_g: ?float, carbohidratos_objetivo_g: ?float, carbohidratos_consumidos_g: ?float, cumplimiento_proteina_pct: float, recomendaciones: Collection<int, RecomendacionSistema>}
     */
    private function armarResumen(
        RegistroDiario $registroDiario,
        float $caloriasObjetivo,
        float $caloriasConsumidas,
        float $caloriasActividad,
        float $deficitDiario,
        float $proteinaObjetivo,
        float $proteinaConsumida,
        ?float $grasaObjetivo = null,
        ?float $grasaConsumida = null,
        ?float $carbohidratosObjetivo = null,
        ?float $carbohidratosConsumidos = null,
    ): array {
        return [
            'calorias_objetivo' => $caloriasObjetivo,
            'calorias_consumidas' => $caloriasConsumidas,
            'calorias_actividad_ajustada' => $caloriasActividad,
            'deficit_diario' => $deficitDiario,
            'proteina_objetivo_g' => $proteinaObjetivo,
            'proteina_consumida_g' => $proteinaConsumida,
            'grasa_objetivo_g' => $grasaObjetivo,
            'grasa_consumida_g' => $grasaConsumida,
            'carbohidratos_objetivo_g' => $carbohidratosObjetivo,
            'carbohidratos_consumidos_g' => $carbohidratosConsumidos,
            'cumplimiento_proteina_pct' => $proteinaObjetivo > 0
                ? $proteinaConsumida / $proteinaObjetivo * 100
                : 0.0,
            'recomendaciones' => $registroDiario->recomendacionesSistema()->latest()->get(),
        ];
    }

    /**
     * Traduce los promedios móviles de 7 días de TrendAnalyticsService en las
     * RecomendacionSistema pendientes de confirmación que decida RulesEngineService
     * (CLAUDE.md sección 6: nunca un ajuste derivado de un solo día).
     *
     * Se calcula con la fecha del propio $registroDiario (no "hoy"), para que
     * este método sea correcto tanto si lo llama un cierre en vivo como si lo
     * llama app:run-daily-closure sobre el día de ayer.
     *
     * El motor de recomendaciones es Premium (CLAUDE.md sección 5.18), y el
     * corte va aquí —la única puerta por la que RulesEngineService produce algo—
     * y no en la vista: una recomendación creada y luego escondida seguiría
     * moviendo el objetivo calórico el día que el usuario volviera a Premium y
     * la confirmara sin haberla visto nunca. El cierre en sí no es Premium: un
     * usuario del plan Gratis cierra su día con todas sus cifras.
     *
     * @param  array{calorias_objetivo: float, calorias_consumidas: float, calorias_actividad_ajustada: float, deficit_diario: float, proteina_objetivo_g: float, proteina_consumida_g: float, grasa_objetivo_g: ?float, grasa_consumida_g: ?float, carbohidratos_objetivo_g: ?float, carbohidratos_consumidos_g: ?float, cumplimiento_proteina_pct: float, recomendaciones: Collection<int, RecomendacionSistema>}  $resumen
     * @return Collection<int, RecomendacionSistema>
     */
    private function generarRecomendaciones(RegistroDiario $registroDiario, array $resumen): Collection
    {
        $usuario = $registroDiario->usuario;
        $recomendaciones = collect();

        if (! $usuario->tienePremium()) {
            return $recomendaciones;
        }

        $tendencia = $this->analiticaTendencias->calcular($usuario, $registroDiario->fecha);

        // Con menos de una ventana completa de 7 días, la sección 6 prohíbe
        // sugerir un ajuste: no hay promedio móvil de fiar todavía.
        if ($tendencia['datos_suficientes'] && $tendencia['porcentaje_perdida_semanal'] !== null) {
            $ajuste = $this->reglas->generarRecomendacionAjusteCalorico(
                $registroDiario,
                $tendencia['porcentaje_perdida_semanal'],
            );

            if ($ajuste !== null) {
                $recomendaciones->push($ajuste);
            }
        }

        $variaciones = $this->analiticaTendencias->variacionesSemanalesPesoKg($usuario, 3, $registroDiario->fecha);

        if (count($variaciones) >= 3) {
            $estancamiento = $this->reglas->detectarEstancamiento($registroDiario, $variaciones);

            if ($estancamiento !== null) {
                $recomendaciones->push($estancamiento);
            }
        }

        return $recomendaciones;
    }
}
