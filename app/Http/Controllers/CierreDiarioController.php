<?php

namespace App\Http\Controllers;

use App\Exceptions\DayAlreadyClosedException;
use App\Exceptions\InvalidNutritionParameterException;
use App\Exceptions\MealDistributionUnavailableException;
use App\Exceptions\NegativeCarbohydrateException;
use App\Http\Requests\CierreDiarioRequest;
use App\Models\RegistroDiario;
use App\Services\CierreFeedbackService;
use App\Services\DailyClosureService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;

/**
 * Cierre de un plan diario (CLAUDE.md secciones 4.5 y 4.16).
 *
 * No tiene pantalla propia: el cierre es la tercera sección del detalle del
 * plan diario. Antes de congelar el día se registra el feedback de cumplimiento
 * de cada comida (CierreFeedbackService), de modo que las cifras del cierre
 * reflejen lo que realmente se comió y no solo lo planificado.
 */
class CierreDiarioController extends Controller
{
    /**
     * Profile fields NutritionCalculatorService needs before a day can be closed.
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
        private readonly CierreFeedbackService $feedback,
    ) {}

    /**
     * "Cerrar mi día": consolida el feedback de las comidas y congela el día
     * con sus cifras. Todo fallo de dominio vuelve como redirect con mensaje,
     * nunca como un 500.
     */
    public function cerrar(CierreDiarioRequest $request, RegistroDiario $registroDiario): RedirectResponse
    {
        abort_unless($registroDiario->usuario_id === $request->user()->id, 403);

        $volverAlPlan = route('planes.show', $registroDiario);

        if ($this->parametroFaltante($request) !== null) {
            return Redirect::route('calculadora.edit')
                ->with('error', __('Completa tus parámetros nutricionales antes de cerrar el día.'));
        }

        if ($registroDiario->cerrado) {
            return Redirect::back(fallback: $volverAlPlan)
                ->with('error', DayAlreadyClosedException::alCerrar($registroDiario->id)->getMessage());
        }

        // El feedback se consolida antes de cerrar: un día ya cerrado no admite
        // ComidaReal nuevas (sección 4.5). Si el proveedor de IA no puede
        // interpretar lo que se comió, el día no se cierra y el usuario puede
        // corregir el texto y reintentar.
        try {
            $this->feedback->registrar($registroDiario, (array) $request->validated('feedback', []));
        } catch (MealDistributionUnavailableException $e) {
            return Redirect::back(fallback: $volverAlPlan)->with('error', $e->getMessage());
        }

        try {
            $this->cierre->cerrar($registroDiario->refresh());
        } catch (DayAlreadyClosedException $e) {
            return Redirect::back(fallback: $volverAlPlan)->with('error', $e->getMessage());
        } catch (NegativeCarbohydrateException|InvalidNutritionParameterException $e) {
            return Redirect::route('calculadora.edit')->with('error', $e->getMessage());
        }

        return Redirect::back(fallback: $volverAlPlan)->with('status', 'dia-cerrado');
    }

    /**
     * Explicit reopen: the only way to modify a day that was already closed.
     */
    public function reabrir(Request $request, RegistroDiario $registroDiario): RedirectResponse
    {
        abort_unless($registroDiario->usuario_id === $request->user()->id, 403);

        $this->cierre->reabrir($registroDiario);

        return Redirect::back(fallback: route('planes.show', $registroDiario))
            ->with('status', 'dia-reabierto');
    }

    /**
     * Name of the first nutritional parameter missing from the user's profile,
     * or null when the profile is complete.
     */
    private function parametroFaltante(Request $request): ?string
    {
        foreach (self::PARAMETROS_REQUERIDOS as $parametro) {
            if ($request->user()->{$parametro} === null) {
                return $parametro;
            }
        }

        return null;
    }
}
