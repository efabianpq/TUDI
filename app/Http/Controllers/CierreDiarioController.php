<?php

namespace App\Http\Controllers;

use App\Exceptions\DayAlreadyClosedException;
use App\Exceptions\InvalidNutritionParameterException;
use App\Exceptions\NegativeCarbohydrateException;
use App\Models\RegistroDiario;
use App\Services\DailyClosureService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Illuminate\View\View;

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
     * Summary of today's closure: live preview while the day is open, the
     * persisted snapshot once it is closed.
     */
    public function index(Request $request): View
    {
        $registroDiario = $this->registroDiarioDeHoy($request);
        $resumen = null;
        $errorResumen = null;

        if ($registroDiario) {
            if ($this->parametroFaltante($request) !== null) {
                $errorResumen = __('Completa tus parámetros nutricionales para poder cerrar el día.');
            } else {
                try {
                    $resumen = $this->cierre->resumen($registroDiario);
                } catch (NegativeCarbohydrateException|InvalidNutritionParameterException $e) {
                    $errorResumen = $e->getMessage();
                }
            }
        }

        return view('cierre.index', [
            'registroDiario' => $registroDiario,
            'resumen' => $resumen,
            'errorResumen' => $errorResumen,
        ]);
    }

    /**
     * "Cerrar mi día": freeze today's record with its closure figures. Every
     * domain failure comes back as a redirect with an error message, never as
     * a 500.
     */
    public function cerrar(Request $request): RedirectResponse
    {
        if ($this->parametroFaltante($request) !== null) {
            return Redirect::route('profile.parametros.edit')
                ->with('error', __('Completa tus parámetros nutricionales antes de cerrar el día.'));
        }

        $registroDiario = $this->registroDiarioDeHoy($request);

        if (! $registroDiario) {
            return Redirect::route('cierre.index')
                ->with('error', __('Todavía no hay nada registrado hoy: no hay un día que cerrar.'));
        }

        try {
            $this->cierre->cerrar($registroDiario);
        } catch (DayAlreadyClosedException $e) {
            return Redirect::route('cierre.index')->with('error', $e->getMessage());
        } catch (NegativeCarbohydrateException|InvalidNutritionParameterException $e) {
            return Redirect::route('profile.parametros.edit')->with('error', $e->getMessage());
        }

        return Redirect::route('cierre.index')->with('status', 'dia-cerrado');
    }

    /**
     * Explicit reopen: the only way to modify a day that was already closed.
     */
    public function reabrir(Request $request, RegistroDiario $registroDiario): RedirectResponse
    {
        abort_unless($registroDiario->usuario_id === $request->user()->id, 403);

        $this->cierre->reabrir($registroDiario);

        return Redirect::route('cierre.index')->with('status', 'dia-reabierto');
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

    private function registroDiarioDeHoy(Request $request): ?RegistroDiario
    {
        return RegistroDiario::where('usuario_id', $request->user()->id)
            ->whereDate('fecha', now()->toDateString())
            ->first();
    }
}
