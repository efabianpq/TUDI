<?php

namespace App\Http\Controllers;

use App\Exceptions\InvalidNutritionParameterException;
use App\Exceptions\NegativeCarbohydrateException;
use App\Exceptions\NoIngredientsAvailableException;
use App\Models\RegistroDiario;
use App\Services\MealPlanGeneratorService;
use App\Services\NutritionCalculatorService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Illuminate\View\View;

class PlanComidaController extends Controller
{
    /**
     * Profile fields NutritionCalculatorService needs before a plan can be generated.
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
        private readonly NutritionCalculatorService $calculadora,
        private readonly MealPlanGeneratorService $generador,
    ) {}

    /**
     * Show today's meal plan (and the button to generate it).
     */
    public function index(Request $request): View
    {
        $registroDiario = $this->registroDiarioDeHoy($request);

        return view('planes.index', [
            'registroDiario' => $registroDiario,
            'planes' => $registroDiario
                ? $registroDiario->planesComida()->with('comidaReal')->orderBy('id')->get()
                : collect(),
            'ingredientes' => $registroDiario
                ? $registroDiario->ingredientesDisponibles()->get()
                : collect(),
        ]);
    }

    /**
     * "Generar mi plan de hoy": build the plan out of today's reported
     * ingredients. Every domain failure comes back as a redirect with an error
     * message — never as a 500.
     */
    public function generar(Request $request): RedirectResponse
    {
        $usuario = $request->user();

        foreach (self::PARAMETROS_REQUERIDOS as $parametro) {
            if ($usuario->{$parametro} === null) {
                return Redirect::route('profile.parametros.edit')
                    ->with('error', __('Completa tus parámetros nutricionales antes de generar el plan.'));
            }
        }

        $registroDiario = $this->registroDiarioDeHoy($request);

        if (! $registroDiario) {
            return Redirect::route('ingredientes.create')
                ->with('error', __('No hay ingredientes disponibles reportados para hoy. Repórtalos antes de generar el plan.'));
        }

        try {
            $planNutricional = $this->calculadora->calculatePlan(
                (float) $usuario->peso_kg,
                (float) $usuario->nivel_actividad,
                $usuario->tipo_deficit,
                (float) $usuario->valor_deficit,
                (float) $usuario->proteina_factor,
                (float) $usuario->grasa_factor,
            );

            $this->generador->generarPlan($registroDiario, $planNutricional);
        } catch (NoIngredientsAvailableException $e) {
            return Redirect::route('ingredientes.create')->with('error', $e->getMessage());
        } catch (NegativeCarbohydrateException|InvalidNutritionParameterException $e) {
            return Redirect::route('profile.parametros.edit')->with('error', $e->getMessage());
        }

        return Redirect::route('planes.index')->with('status', 'plan-generado');
    }

    private function registroDiarioDeHoy(Request $request): ?RegistroDiario
    {
        return RegistroDiario::where('usuario_id', $request->user()->id)
            ->whereDate('fecha', now()->toDateString())
            ->first();
    }
}
