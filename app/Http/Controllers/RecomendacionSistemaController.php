<?php

namespace App\Http\Controllers;

use App\Exceptions\RecomendacionYaProcesadaException;
use App\Models\RecomendacionSistema;
use App\Services\RulesEngineService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;

class RecomendacionSistemaController extends Controller
{
    public function __construct(
        private readonly RulesEngineService $motor,
    ) {}

    public function confirmar(Request $request, RecomendacionSistema $recomendacion): RedirectResponse
    {
        abort_unless($recomendacion->registroDiario->usuario_id === $request->user()->id, 403);

        try {
            $this->motor->confirmar($recomendacion);
        } catch (RecomendacionYaProcesadaException $e) {
            return Redirect::back(fallback: route('cierre.index'))->with('error', $e->getMessage());
        }

        return Redirect::back(fallback: route('cierre.index'))->with('status', 'recomendacion-confirmada');
    }

    public function rechazar(Request $request, RecomendacionSistema $recomendacion): RedirectResponse
    {
        abort_unless($recomendacion->registroDiario->usuario_id === $request->user()->id, 403);

        try {
            $this->motor->rechazar($recomendacion);
        } catch (RecomendacionYaProcesadaException $e) {
            return Redirect::back(fallback: route('cierre.index'))->with('error', $e->getMessage());
        }

        return Redirect::back(fallback: route('cierre.index'))->with('status', 'recomendacion-rechazada');
    }
}
