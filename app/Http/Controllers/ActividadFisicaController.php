<?php

namespace App\Http\Controllers;

use App\Exceptions\DayAlreadyClosedException;
use App\Http\Requests\ActividadFisicaRequest;
use App\Models\RegistroDiario;
use App\Services\ActivityCorrectionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redirect;

/**
 * Registro de la actividad física de un plan diario (CLAUDE.md sección 4.13).
 *
 * No tiene pantalla propia: la sección "Actividad física" vive dentro del
 * detalle del plan diario, que es donde está el día al que pertenece lo que se
 * registra.
 */
class ActividadFisicaController extends Controller
{
    public function __construct(private readonly ActivityCorrectionService $activityCorrectionService) {}

    /**
     * Registra una actividad en un plan diario, aplicando el factor de
     * corrección de su tipo y recalculando `calorias_actividad_ajustada` del
     * RegistroDiario desde cero (idempotente, igual que ComidaRealService con
     * `calorias_consumidas`).
     */
    public function store(ActividadFisicaRequest $request, RegistroDiario $registroDiario): RedirectResponse
    {
        abort_unless($registroDiario->usuario_id === $request->user()->id, 403);

        $volverAlPlan = route('planes.show', $registroDiario);

        // Un día cerrado está congelado: una actividad nueva cambiaría la
        // calorias_actividad_ajustada con la que se calculó su cierre.
        if ($registroDiario->cerrado) {
            return Redirect::back(fallback: $volverAlPlan)
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

        return Redirect::back(fallback: $volverAlPlan)->with('status', 'actividad-guardada');
    }
}
