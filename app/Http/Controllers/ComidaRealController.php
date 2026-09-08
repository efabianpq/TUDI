<?php

namespace App\Http\Controllers;

use App\Exceptions\DayAlreadyClosedException;
use App\Http\Requests\ComidaRealRequest;
use App\Models\PlanComida;
use App\Services\ComidaRealService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Illuminate\View\View;

/**
 * Registro detallado de una comida real, con macros exactos e imagen.
 *
 * **Ya no está enlazado desde la interfaz** (CLAUDE.md sección 4.23): el botón
 * "Registrar" de cada comida del plan diario desapareció porque duplicaba la
 * pregunta que el cierre ya hace ("¿cumpliste con lo sugerido?"), y la foto de
 * evidencia se subió a esa misma sección. Las rutas se conservan —igual que las
 * de ingredientes estructurados (sección 4.1)— porque siguen siendo un camino
 * válido y cubierto por tests para corregir los macros de una comida a mano.
 */
class ComidaRealController extends Controller
{
    public function __construct(
        private readonly ComidaRealService $comidaRealService,
    ) {}

    /**
     * Show the form to log what was actually eaten for a planned meal.
     */
    public function create(Request $request, PlanComida $planComida): View|RedirectResponse
    {
        abort_unless($planComida->registroDiario->usuario_id === $request->user()->id, 403);

        if ($planComida->registroDiario->cerrado) {
            return Redirect::route('planes.show', $planComida->registroDiario)
                ->with('error', DayAlreadyClosedException::alRegistrarComida($planComida->registro_diario_id)->getMessage());
        }

        if ($planComida->comidaReal) {
            return Redirect::route('planes.show', $planComida->registroDiario)
                ->with('error', __('Esta comida ya tiene una comida real registrada.'));
        }

        return view('comidas-reales.create', [
            'planComida' => $planComida,
        ]);
    }

    /**
     * Log the ComidaReal for a planned meal. Triggers, via ComidaRealService,
     * the redistribution of the day's remaining calorie budget and the
     * refresh of the RegistroDiario's calorias_consumidas.
     */
    public function store(ComidaRealRequest $request, PlanComida $planComida): RedirectResponse
    {
        abort_unless($planComida->registroDiario->usuario_id === $request->user()->id, 403);

        if ($planComida->comidaReal) {
            return Redirect::route('planes.show', $planComida->registroDiario)
                ->with('error', __('Esta comida ya tiene una comida real registrada.'));
        }

        try {
            $this->comidaRealService->registrar(
                $planComida,
                $request->validated(),
                $request->file('imagen'),
            );
        } catch (DayAlreadyClosedException $e) {
            return Redirect::route('planes.show', $planComida->registroDiario)->with('error', $e->getMessage());
        }

        return Redirect::route('planes.show', $planComida->registroDiario)->with('status', 'comida-real-guardada');
    }

    /**
     * "Cambiar mi respuesta": borra lo registrado para esa comida y devuelve la
     * pregunta del cierre (CLAUDE.md sección 5.5).
     *
     * Este sí está enlazado desde la interfaz, a diferencia del resto del
     * controlador: es lo que hace que, al reabrir un día, el cierre vuelva a
     * preguntar comida a comida en vez de dar por buena la respuesta anterior.
     */
    public function destroy(Request $request, PlanComida $planComida): RedirectResponse
    {
        abort_unless($planComida->registroDiario->usuario_id === $request->user()->id, 403);

        try {
            $borrada = $this->comidaRealService->eliminar($planComida);
        } catch (DayAlreadyClosedException $e) {
            return Redirect::back(fallback: route('planes.show', $planComida->registroDiario))
                ->with('error', $e->getMessage());
        }

        return Redirect::route('planes.show', $planComida->registroDiario)
            ->with('status', $borrada ? 'comida-real-eliminada' : 'comida-real-inexistente');
    }
}
