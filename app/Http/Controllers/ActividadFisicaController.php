<?php

namespace App\Http\Controllers;

use App\Exceptions\DayAlreadyClosedException;
use App\Http\Requests\ActividadFisicaRequest;
use App\Models\RegistroDiario;
use App\Services\ActivityCorrectionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redirect;
use Illuminate\View\View;

class ActividadFisicaController extends Controller
{
    public function __construct(private readonly ActivityCorrectionService $activityCorrectionService) {}

    /**
     * Show the form to register today's physical activity, along with the
     * activities already reported today.
     */
    public function create(Request $request): View
    {
        $registroDiario = RegistroDiario::where('usuario_id', $request->user()->id)
            ->whereDate('fecha', now()->toDateString())
            ->first();

        $actividades = $registroDiario
            ? $registroDiario->actividadesFisicas()->latest()->get()
            : collect();

        return view('actividades.create', [
            'actividades' => $actividades,
        ]);
    }

    /**
     * Store a physical activity for today, applying the correction factor
     * and recalculating the RegistroDiario's calorias_actividad_ajustada.
     */
    public function store(ActividadFisicaRequest $request): RedirectResponse
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

        // A closed day is frozen: a new activity would change the
        // calorias_actividad_ajustada its closure was computed from.
        if ($registroDiario->cerrado) {
            return Redirect::route('cierre.index')
                ->with('error', DayAlreadyClosedException::alRegistrarActividad($registroDiario->id)->getMessage());
        }

        DB::transaction(function () use ($request, $registroDiario) {
            $datos = $request->validated();

            $resultado = $this->activityCorrectionService->calcularCaloriasAjustadas(
                $datos['tipo_actividad'],
                (float) $datos['calorias_dispositivo'],
            );

            $registroDiario->actividadesFisicas()->create([
                'tipo' => $datos['tipo_actividad'],
                'duracion_min' => $datos['duracion_min'],
                'pasos' => $datos['pasos'] ?? null,
                'calorias_dispositivo' => $datos['calorias_dispositivo'],
                'fuente' => $datos['fuente'],
                'factor_correccion' => $resultado['factor_correccion'],
                'calorias_ajustadas' => $resultado['calorias_ajustadas'],
            ]);

            $registroDiario->update([
                'calorias_actividad_ajustada' => $registroDiario->actividadesFisicas()->sum('calorias_ajustadas'),
            ]);
        });

        return Redirect::route('actividades.create')->with('status', 'actividad-guardada');
    }
}
