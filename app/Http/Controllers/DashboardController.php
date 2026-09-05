<?php

namespace App\Http\Controllers;

use App\Exceptions\InvalidNutritionParameterException;
use App\Exceptions\NegativeCarbohydrateException;
use App\Models\PlanComida;
use App\Models\RecomendacionSistema;
use App\Models\RegistroDiario;
use App\Services\DailyClosureService;
use App\Services\MealPlanGeneratorService;
use App\Services\TrendAnalyticsService;
use Illuminate\Http\Request;
use Illuminate\View\View;

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

    public function __construct(
        private readonly DailyClosureService $cierre,
        private readonly TrendAnalyticsService $tendencias,
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

        return view('dashboard', [
            'registroDiario' => $registroDiario,
            'resumen' => $resumen,
            'errorResumen' => $errorResumen,
            'estadoComidas' => $this->estadoComidas($registroDiario),
            'metricas' => $this->tendencias->calcular($usuario),
            'serie' => $this->tendencias->serieHistorica($usuario),
            'recomendacionesPendientes' => RecomendacionSistema::whereHas(
                'registroDiario',
                fn ($query) => $query->where('usuario_id', $usuario->id),
            )->where('estado', 'pendiente')->latest()->get(),
        ]);
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
