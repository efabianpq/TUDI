<?php

namespace App\Http\Controllers;

use App\Exceptions\DayAlreadyClosedException;
use App\Exceptions\InvalidNutritionParameterException;
use App\Exceptions\NegativeCarbohydrateException;
use App\Http\Requests\CierreDiarioRequest;
use App\Models\RegistroDiario;
use App\Services\DailyClosureService;
use App\Services\MealPlanGeneratorService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;

/**
 * Cierre de un plan diario (CLAUDE.md sección 5.5).
 *
 * No tiene pantalla propia: el cierre es la última sección del detalle del plan
 * diario.
 *
 * ── Cerrar el día ya no llama a la IA ──────────────────────────────────────
 *
 * Antes, cerrar el día era también el momento de contar qué se había comido, y
 * eso significaba una llamada al proveedor dentro de la petición del cierre:
 * costaba dinero, tardaba, y un proveedor caído dejaba el día sin cerrar. Desde
 * el cierre por comida (ReporteComidaController) lo que se comió ya está
 * reportado, así que aquí solo se consolida: sumar, calcular el déficit con
 * NutritionCalculatorService y congelar. Cerrar el día es gratis, instantáneo y
 * no puede fallar por un servicio externo.
 *
 * ── Los controles de validación ────────────────────────────────────────────
 *
 * Un día cerrado sin reportar nada no es un día sin comer: es un día sin
 * contar, y su cero entra luego en el promedio móvil de 7 días como si fuera un
 * dato bueno. Por eso:
 *
 *  - **Sin ninguna comida reportada** no se cierra, y se dice qué falta.
 *  - **Con alguna comida sin reportar** se cierra, pero solo si el usuario lo
 *    confirma explícitamente; la pantalla le dice cuáles son.
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
    ) {}

    /**
     * "Cerrar mi día": consolida lo reportado y congela las cifras del día.
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

        $sinReportar = $this->cierre->comidasSinReportar($registroDiario);

        if (count($sinReportar) === count(MealPlanGeneratorService::DISTRIBUCION_COMIDAS)) {
            return Redirect::back(fallback: $volverAlPlan)->with('error', __(
                'Todavía no has reportado ninguna comida. Cierra al menos una antes de cerrar el día: '.
                'si no, el día quedaría congelado con cero calorías consumidas.',
            ));
        }

        if ($sinReportar !== [] && ! $request->boolean('confirmar_sin_reportar')) {
            return Redirect::back(fallback: $volverAlPlan)->with('error', __(
                'Te faltan por reportar: :comidas. Repórtalas, o marca la casilla para cerrar el día igualmente.',
                ['comidas' => implode(', ', $sinReportar)],
            ));
        }

        try {
            $this->cierre->cerrar($registroDiario);
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
