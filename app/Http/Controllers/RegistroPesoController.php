<?php

namespace App\Http\Controllers;

use App\Http\Requests\RegistroPesoRequest;
use App\Models\RegistroDiario;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Illuminate\View\View;

/**
 * Captura el peso del día en registros_diarios.peso_kg — la columna que
 * TrendAnalyticsService promedia para el promedio móvil de peso (CLAUDE.md
 * sección 4.7), sin la cual porcentaje_perdida_semanal es siempre null y
 * DailyClosureService::generarRecomendaciones() nunca tiene nada que evaluar.
 *
 * A propósito no toca users.peso_kg (el peso "actual" que dimensiona el TMB
 * de NutritionCalculatorService): ese es un dato de perfil que el usuario
 * edita explícitamente en ProfileParametersController, no algo que un
 * pesaje diario deba desplazar en silencio.
 */
class RegistroPesoController extends Controller
{
    /**
     * Show the form to log today's weight, with today's already-reported
     * value if there is one.
     */
    public function create(Request $request): View
    {
        $registroDiario = RegistroDiario::where('usuario_id', $request->user()->id)
            ->whereDate('fecha', now()->toDateString())
            ->first();

        return view('peso.create', [
            'pesoHoy' => $registroDiario?->peso_kg,
        ]);
    }

    /**
     * Store (or correct) today's weight. Allowed even if the day is already
     * closed: peso_kg does not feed any figure of the closure snapshot
     * (DailyClosureService::armarResumen()), only the trend analytics.
     */
    public function store(RegistroPesoRequest $request): RedirectResponse
    {
        $registroDiario = RegistroDiario::where('usuario_id', $request->user()->id)
            ->whereDate('fecha', now()->toDateString())
            ->first();

        if (! $registroDiario) {
            $registroDiario = RegistroDiario::create([
                'usuario_id' => $request->user()->id,
                'fecha' => now()->toDateString(),
            ]);
        }

        $registroDiario->update([
            'peso_kg' => $request->validated('peso_kg'),
        ]);

        return Redirect::route('peso.create')->with('status', 'peso-guardado');
    }
}
