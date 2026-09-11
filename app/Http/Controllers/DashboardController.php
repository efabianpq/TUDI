<?php

namespace App\Http\Controllers;

use App\Exceptions\InvalidNutritionParameterException;
use App\Exceptions\NegativeCarbohydrateException;
use App\Models\PlanComida;
use App\Models\RecomendacionSistema;
use App\Models\RegistroDiario;
use App\Services\DailyClosureService;
use App\Services\MealPlanGeneratorService;
use App\Services\SeguimientoService;
use App\Services\TrendAnalyticsService;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Pantalla de inicio (CLAUDE.md sección 4.17). Unifica lo que antes eran dos
 * pantallas que costaba distinguir —el dashboard y "Mi progreso"— en una sola
 * con tres alturas de mirada:
 *
 *  1. **Hoy** — el resumen del plan diario en curso y el estado de sus comidas.
 *  2. **Tendencia** — los promedios móviles de 7 días y el gráfico de peso, que
 *     antes vivían en "Mi progreso" (TrendAnalyticsService).
 *  3. **Seguimiento** — cómo evoluciona semana a semana y el historial de
 *     ajustes que el sistema le ha ido proponiendo (SeguimientoService).
 *
 * Es un controlador de solo composición: no reimplementa ningún cálculo, solo
 * junta lo que exponen los servicios de dominio y traduce sus excepciones a
 * mensajes, nunca a un 500.
 */
class DashboardController extends Controller
{
    /**
     * Same fields CierreDiarioController checks before touching
     * NutritionCalculatorService — without them there is no objetivo to compare against.
     */
    private const PARAMETROS_REQUERIDOS = [
        'peso_kg',
        'nivel_actividad',
        'tipo_deficit',
        'valor_deficit',
        'proteina_factor',
        'grasa_factor',
    ];

    /**
     * Días de historial que se dibujan en el gráfico de peso.
     */
    private const DIAS_GRAFICO = 30;

    /**
     * Semanas del bloque de seguimiento. Seis semanas son mes y medio: bastante
     * para ver una tendencia sin convertir la tabla en un muro.
     */
    private const SEMANAS_SEGUIMIENTO = 6;

    /**
     * Lo que ve el plan Gratis (CLAUDE.md sección 5.18): la ventana de 7 días,
     * que es justo lo que la landing promete como gratis para siempre.
     *
     * El bloque "Tu tendencia" —promedio móvil, déficit medio, racha— no se
     * toca: se calcula sobre esa misma ventana de 7 días y es gratis entero. Lo
     * que se recorta es el historial que va más atrás.
     */
    private const DIAS_GRAFICO_GRATIS = 7;

    private const SEMANAS_SEGUIMIENTO_GRATIS = 1;

    public function __construct(
        private readonly DailyClosureService $cierre,
        private readonly TrendAnalyticsService $tendencias,
        private readonly SeguimientoService $seguimiento,
    ) {}

    public function index(Request $request): View
    {
        $usuario = $request->user();
        $registroDiario = $this->registroDiarioDeHoy($request);

        $resumen = null;
        $errorResumen = null;

        if ($registroDiario) {
            if ($this->parametroFaltante($usuario) !== null) {
                $errorResumen = __('Completa tus parámetros nutricionales para ver el resumen de hoy.');
            } else {
                try {
                    $resumen = $this->cierre->resumen($registroDiario);
                } catch (NegativeCarbohydrateException|InvalidNutritionParameterException $e) {
                    $errorResumen = $e->getMessage();
                }
            }
        }

        // Escritura idempotente (una fila por usuario y fecha, actualizada en
        // sitio): mantiene alimentada la serie de metricas_tendencia también
        // donde el cron de app:calculate-trends no corre. Antes lo hacía
        // ProgresoController, que ya no existe como pantalla propia.
        $this->tendencias->calcularYPersistir($usuario);

        // El historial largo y las recomendaciones son Premium (sección 5.18).
        // Lo que se recorta es cuánto se enseña, nunca lo que se guarda: los
        // datos siguen ahí y vuelven enteros en cuanto haya plan.
        $premium = $usuario->tienePremium();

        return view('dashboard', [
            'registroDiario' => $registroDiario,
            'resumen' => $resumen,
            'errorResumen' => $errorResumen,
            'estadoComidas' => $this->estadoComidas($registroDiario),
            'metricas' => $this->tendencias->calcular($usuario),
            // Días seguidos cerrados (sección 5.23).
            'racha' => $this->tendencias->rachaDiasCerrados($usuario),
            // "No reportaste la cena de ayer" (sección 5.24).
            'avisoAyer' => $this->comidasSinReportarAyer($usuario),
            'serie' => $this->tendencias->serieHistorica(
                $usuario,
                $premium ? self::DIAS_GRAFICO : self::DIAS_GRAFICO_GRATIS,
            ),
            'semanas' => $this->seguimiento->resumenSemanal(
                $usuario,
                $premium ? self::SEMANAS_SEGUIMIENTO : self::SEMANAS_SEGUIMIENTO_GRATIS,
            ),
            'premium' => $premium,
            'historialRecomendaciones' => $premium
                ? $this->seguimiento->historialRecomendaciones($usuario)
                : collect(),
            'recomendacionesPendientes' => $premium
                ? RecomendacionSistema::whereHas(
                    'registroDiario',
                    fn ($query) => $query->where('usuario_id', $usuario->id),
                )->where('estado', 'pendiente')->latest()->get()
                : collect(),
        ]);
    }

    /**
     * El plan de ayer y las comidas que quedaron sin reportar en él, o null si
     * no hay nada que avisar (CLAUDE.md sección 5.24).
     *
     * Sirve para dos cosas a la vez: el historial gana calidad —una comida sin
     * reportar es un día con menos calorías de las que se comieron, y ese hueco
     * entra luego en el promedio móvil— y el usuario se entera de que puede
     * arreglarlo, que es justo lo que "reabrir" permite. Se mira solo el día
     * anterior: recordar lo de hace tres días ya no es fiable.
     *
     * @return array{registro: RegistroDiario, comidas: array<int, string>}|null
     */
    private function comidasSinReportarAyer($usuario): ?array
    {
        $ayer = RegistroDiario::where('usuario_id', $usuario->id)
            ->whereDate('fecha', now()->subDay()->toDateString())
            ->first();

        if ($ayer === null) {
            return null;
        }

        $comidas = $this->cierre->comidasSinReportar($ayer);

        // Un día en el que no se reportó absolutamente nada no es un descuido:
        // es un día que no se usó, y recordárselo sería ruido.
        if ($comidas === [] || count($comidas) === count(MealPlanGeneratorService::DISTRIBUCION_COMIDAS)) {
            return null;
        }

        return ['registro' => $ayer, 'comidas' => $comidas];
    }

    /**
     * One entry per meal slot of MealPlanGeneratorService::DISTRIBUCION_COMIDAS,
     * in that order, each tagged with its status: 'pendiente' when the day has
     * no plan yet for that slot, 'planificada' when a PlanComida exists without
     * a ComidaReal, 'registrada' once the ComidaReal is in.
     *
     * @return array<int, array{tipo: string, estado: string, planComida: ?PlanComida}>
     */
    private function estadoComidas(?RegistroDiario $registroDiario): array
    {
        $planes = $registroDiario
            ? $registroDiario->planesComida()->with('comidaReal')->get()->keyBy('tipo_comida')
            : collect();

        $estados = [];

        foreach (array_keys(MealPlanGeneratorService::DISTRIBUCION_COMIDAS) as $tipo) {
            $plan = $planes->get($tipo);

            $estados[] = [
                'tipo' => $tipo,
                'estado' => match (true) {
                    $plan === null => 'pendiente',
                    $plan->comidaReal !== null => 'registrada',
                    default => 'planificada',
                },
                'planComida' => $plan,
            ];
        }

        return $estados;
    }

    private function parametroFaltante($usuario): ?string
    {
        foreach (self::PARAMETROS_REQUERIDOS as $parametro) {
            if ($usuario->{$parametro} === null) {
                return $parametro;
            }
        }

        return null;
    }

    private function registroDiarioDeHoy(Request $request): ?RegistroDiario
    {
        return RegistroDiario::where('usuario_id', $request->user()->id)
            ->whereDate('fecha', now()->toDateString())
            ->first();
    }
}
