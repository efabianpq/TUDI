<?php

namespace App\Services;

use App\Exceptions\InvalidNutritionParameterException;
use App\Exceptions\NegativeCarbohydrateException;
use App\Models\PlanComida;
use App\Models\RecomendacionSistema;
use App\Models\RegistroDiario;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Decide qué sub-estado de cada bloque de Inicio corresponde mostrar
 * (CLAUDE.md sección 5.8) y junta lo que ya calculan los servicios de dominio
 * para pintarlo. No calcula ninguna cifra de negocio nueva ni de balance
 * energético (regla 7 de la sección 13): es pura composición y clasificación
 * de estado, la misma frontera de responsabilidad que ya tenían
 * DashboardController y PlanComidaController antes de esta clase.
 *
 * ── Por qué existe ───────────────────────────────────────────────────────
 *
 * Antes esta clasificación vivía a medias en DashboardController (con
 * `@if` sobre `$registroDiario`/`$resumen`) y a medias en la propia vista
 * Blade. La sección 13 pide controladores delgados y nada de lógica de
 * negocio en la vista: aquí vive la única fuente de verdad de qué bloque le
 * toca ver a un usuario dado, para que un test de este servicio baste para
 * cubrir cada combinación sin levantar una petición HTTP completa.
 */
class DashboardEstadoService
{
    /**
     * Días de historial que se dibujan en el gráfico/sparkline de peso.
     */
    private const DIAS_GRAFICO = 30;

    /**
     * Lo que ve el plan Gratis (CLAUDE.md sección 5.18): la ventana de 7 días,
     * que es justo lo que la landing promete como gratis para siempre.
     */
    private const DIAS_GRAFICO_GRATIS = 7;

    /**
     * Semanas del bloque de seguimiento. Seis semanas son mes y medio: bastante
     * para ver una tendencia sin convertir la tabla en un muro.
     */
    private const SEMANAS_SEGUIMIENTO = 6;

    private const SEMANAS_SEGUIMIENTO_GRATIS = 1;

    /**
     * Mismos campos que exige la Calculadora antes de fijar un objetivo
     * calórico (sección 5.2) — sin ellos no hay nada que resumir.
     */
    private const PARAMETROS_REQUERIDOS = [
        'peso_kg',
        'nivel_actividad',
        'tipo_deficit',
        'valor_deficit',
        'proteina_factor',
        'grasa_factor',
    ];

    public function __construct(
        private readonly DailyClosureService $cierre,
        private readonly TrendAnalyticsService $tendencias,
        private readonly SeguimientoService $seguimientoService,
        private readonly MealDistributionService $distribucion,
    ) {}

    /**
     * El estado completo de Inicio para un usuario: qué bloques se pintan y
     * con qué datos.
     *
     * @return array<string, mixed>
     */
    public function calcular(User $usuario): array
    {
        $premium = $usuario->tienePremium();

        // Escritura idempotente (una fila por usuario y fecha, actualizada en
        // sitio): mantiene alimentada metricas_tendencia también donde el cron
        // de app:calculate-trends no corre (mismo motivo que tenía antes en
        // DashboardController).
        $this->tendencias->calcularYPersistir($usuario);

        // Bloque 1 — Bienvenida (sección 5.8): sin ningún RegistroDiario, los
        // bloques 2-4 mostrarían ceros disfrazados de progreso, así que los
        // sustituye por completo un único mensaje con una sola acción.
        if (! RegistroDiario::where('usuario_id', $usuario->id)->exists()) {
            return [
                'primerLogin' => true,
                'ctaCalculadora' => $this->parametroFaltante($usuario) !== null,
                'hoy' => null,
                'tendencia' => null,
                'seguimiento' => null,
                'premium' => $premium,
                'avisoAyer' => null,
            ];
        }

        return [
            'primerLogin' => false,
            'ctaCalculadora' => false,
            'hoy' => $this->bloqueHoy($usuario),
            'tendencia' => $this->bloqueTendencia($usuario, $premium),
            'seguimiento' => $this->bloqueSeguimiento($usuario, $premium),
            'premium' => $premium,
            'avisoAyer' => $this->comidasSinReportarAyer($usuario),
        ];
    }

    /**
     * Bloque 2 — Hoy: tres sub-estados mutuamente excluyentes (sin plan, en
     * curso, cerrado), más el caso transversal de parámetros incompletos que
     * ya cubría el dashboard anterior.
     *
     * @return array{estado: string, registroDiario: ?RegistroDiario, error: ?string, resumen: ?array, saldo: ?array, estadoComidas: array}
     */
    private function bloqueHoy(User $usuario): array
    {
        $registroDiario = $this->registroDiarioDeHoy($usuario);

        $base = [
            'registroDiario' => $registroDiario,
            'error' => null,
            'resumen' => null,
            'saldo' => null,
            'estadoComidas' => $this->estadoComidas($registroDiario),
        ];

        if ($this->parametroFaltante($usuario) !== null) {
            return [
                ...$base,
                'estado' => 'error',
                'error' => __('Completa tus parámetros nutricionales para ver el resumen de hoy.'),
            ];
        }

        if ($registroDiario === null) {
            return [...$base, 'estado' => 'sin_plan'];
        }

        try {
            $resumen = $this->cierre->resumen($registroDiario);
            $saldo = $this->distribucion->saldoDelDia($registroDiario);
        } catch (NegativeCarbohydrateException|InvalidNutritionParameterException $e) {
            return [...$base, 'estado' => 'error', 'error' => $e->getMessage()];
        }

        return [
            ...$base,
            'estado' => $registroDiario->cerrado ? 'cerrado' : 'en_curso',
            'resumen' => $resumen,
            'saldo' => $saldo,
        ];
    }

    /**
     * Bloque 3 — Tu tendencia: sin promedio móvil de fiar (menos de 7 días de
     * historial) se enseña el mismo checklist que el cierre, nunca un gráfico
     * vacío ni un promedio con un solo dato (sección 5.8).
     *
     * @return array{suficiente: bool, diagnostico?: array, metricas?: array, ultimoPeso?: ?array, racha?: int, serie?: array}
     */
    private function bloqueTendencia(User $usuario, bool $premium): array
    {
        $metricas = $this->tendencias->calcular($usuario);

        if (! $metricas['datos_suficientes']) {
            return [
                'suficiente' => false,
                'diagnostico' => $this->tendencias->diagnosticoRecomendaciones($usuario),
            ];
        }

        return [
            'suficiente' => true,
            'metricas' => $metricas,
            'ultimoPeso' => $this->tendencias->ultimoPesoRegistrado($usuario),
            'racha' => $this->tendencias->rachaDiasCerrados($usuario),
            'serie' => $this->tendencias->serieHistorica(
                $usuario,
                $premium ? self::DIAS_GRAFICO : self::DIAS_GRAFICO_GRATIS,
            ),
        ];
    }

    /**
     * Bloque 4 — Tu seguimiento: sin una semana natural completa desde el
     * primer RegistroDiario, la tabla de seis semanas se vería casi entera en
     * blanco, así que se sustituye por un mensaje con la fecha en la que
     * tendrá sentido volver a mirarla.
     *
     * "Semana completa" se define contra el calendario, no contra la
     * adherencia: basta con que hayan pasado los DIAS_POR_SEMANA días
     * naturales desde el primer día que el usuario usó la app, aunque no los
     * haya cerrado todos — la propia tabla ya distingue días con plan de días
     * cerrados (sección 4.16, ambigüedad resuelta según la regla 4 de la
     * sección 13).
     *
     * @return array{completo: bool, fechaEstimada?: ?Carbon, semanas?: array, historialRecomendaciones?: Collection<int, RecomendacionSistema>, recomendacionesPendientes?: Collection<int, RecomendacionSistema>}
     */
    private function bloqueSeguimiento(User $usuario, bool $premium): array
    {
        $primerDia = $this->seguimientoService->primerDiaRegistrado($usuario);
        $diasTranscurridos = $primerDia !== null
            ? $primerDia->diffInDays(now()->startOfDay()) + 1
            : 0;

        if ($diasTranscurridos < SeguimientoService::DIAS_POR_SEMANA) {
            return [
                'completo' => false,
                'fechaEstimada' => $primerDia?->copy()->addDays(SeguimientoService::DIAS_POR_SEMANA - 1),
            ];
        }

        return [
            'completo' => true,
            'semanas' => $this->seguimientoService->resumenSemanal(
                $usuario,
                $premium ? self::SEMANAS_SEGUIMIENTO : self::SEMANAS_SEGUIMIENTO_GRATIS,
            ),
            'historialRecomendaciones' => $premium
                ? $this->seguimientoService->historialRecomendaciones($usuario)
                : collect(),
            'recomendacionesPendientes' => $premium
                ? RecomendacionSistema::whereHas(
                    'registroDiario',
                    fn ($query) => $query->where('usuario_id', $usuario->id),
                )->where('estado', 'pendiente')->latest()->get()
                : collect(),
        ];
    }

    /**
     * El plan de ayer y las comidas que quedaron sin reportar en él, o null si
     * no hay nada que avisar (CLAUDE.md sección 5.24). Se mira solo el día
     * anterior: recordar lo de hace tres días ya no es fiable.
     *
     * @return array{registro: RegistroDiario, comidas: array<int, string>}|null
     */
    private function comidasSinReportarAyer(User $usuario): ?array
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
     * Una entrada por comida de MealPlanGeneratorService::DISTRIBUCION_COMIDAS,
     * en ese orden, con su estado: 'pendiente' sin plan todavía, 'planificada'
     * con PlanComida sin ComidaReal, 'registrada' con las dos.
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

    private function parametroFaltante(User $usuario): ?string
    {
        foreach (self::PARAMETROS_REQUERIDOS as $parametro) {
            if ($usuario->{$parametro} === null) {
                return $parametro;
            }
        }

        return null;
    }

    private function registroDiarioDeHoy(User $usuario): ?RegistroDiario
    {
        return RegistroDiario::where('usuario_id', $usuario->id)
            ->whereDate('fecha', now()->toDateString())
            ->first();
    }
}
