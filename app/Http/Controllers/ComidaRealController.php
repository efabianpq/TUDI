<?php

namespace App\Http\Controllers;

use App\Http\Requests\ComidaRealRequest;
use App\Models\PlanComida;
use App\Services\ComidaRealService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Illuminate\View\View;

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

        if ($planComida->comidaReal) {
            return Redirect::route('planes.index')
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
            return Redirect::route('planes.index')
                ->with('error', __('Esta comida ya tiene una comida real registrada.'));
        }

        $this->comidaRealService->registrar(
            $planComida,
            $request->validated(),
            $request->file('imagen'),
        );

        return Redirect::route('planes.index')->with('status', 'comida-real-guardada');
    }
}
