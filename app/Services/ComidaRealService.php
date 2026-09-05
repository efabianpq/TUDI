<?php

namespace App\Services;

use App\Exceptions\DayAlreadyClosedException;
use App\Models\ComidaReal;
use App\Models\PlanComida;
use App\Models\RegistroDiario;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Registers what the user actually ate against an existing PlanComida, and
 * propagates the effect of that record on the rest of the day: the
 * RegistroDiario's calorias_consumidas total, and the calorie budget left
 * for the meals of that same day that have not been logged yet.
 */
class ComidaRealService
{
    /**
     * Disk (see config/filesystems.php, "public" -> storage/app/public) and
     * directory where evidence images are stored. Requires `storage:link` to
     * be reachable at /storage/comidas-reales/*. No processing (compression,
     * analysis) is done on the image per the architecture document — it is
     * only visual evidence.
     */
    private const DISCO_IMAGENES = 'public';

    private const DIRECTORIO_IMAGENES = 'comidas-reales';

    /**
     * Create the ComidaReal for $planComida, then redistribute any calorie
     * deviation across the day's still-pending meals and refresh the
     * RegistroDiario's calorias_consumidas. Runs inside a transaction so a
     * failure midway never leaves the day's totals out of sync.
     *
     * @param  array{calorias_reales: float, proteina_g: float, grasa_g: float, carbohidratos_g: float, notas: ?string}  $datos
     *
     * @throws DayAlreadyClosedException when the day was already closed — a closed
     *                                   day is frozen until the user reopens it
     */
    public function registrar(PlanComida $planComida, array $datos, ?UploadedFile $imagen = null): ComidaReal
    {
        if ($planComida->registroDiario->cerrado) {
            throw DayAlreadyClosedException::alRegistrarComida($planComida->registro_diario_id);
        }

        return DB::transaction(function () use ($planComida, $datos, $imagen) {
            $rutaImagen = $imagen?->store(self::DIRECTORIO_IMAGENES, self::DISCO_IMAGENES);

            $comidaReal = $planComida->comidaReal()->create([
                'calorias_reales' => $datos['calorias_reales'],
                'proteina_g' => $datos['proteina_g'],
                'grasa_g' => $datos['grasa_g'],
                'carbohidratos_g' => $datos['carbohidratos_g'],
                'notas' => $datos['notas'] ?? null,
                'consumido_en' => now(),
                'imagen_evidencia' => $rutaImagen,
            ]);

            $desviacion = (float) $datos['calorias_reales'] - (float) $planComida->calorias_estimadas;

            $registroDiario = $planComida->registroDiario;

            $this->redistribuirCaloriasPendientes($registroDiario, $desviacion);
            $this->actualizarCaloriasConsumidas($registroDiario);

            return $comidaReal;
        });
    }

    /**
     * Spread the deviation between real and planned calories of the meal
     * just logged across the meals of the same day that don't have a
     * ComidaReal yet, proportionally to each one's current planned share.
     * An excess at breakfast (positive deviation) shrinks what's left for
     * lunch/dinner; eating less than planned (negative deviation) grows it.
     * Never lets a meal's budget go below zero.
     */
    private function redistribuirCaloriasPendientes(RegistroDiario $registroDiario, float $desviacion): void
    {
        if ($desviacion === 0.0) {
            return;
        }

        $pendientes = $registroDiario->planesComida()->doesntHave('comidaReal')->get();

        $totalPlanificadoPendiente = (float) $pendientes->sum(fn (PlanComida $plan) => (float) $plan->calorias_estimadas);

        if ($totalPlanificadoPendiente <= 0) {
            return;
        }

        foreach ($pendientes as $pendiente) {
            $participacion = (float) $pendiente->calorias_estimadas / $totalPlanificadoPendiente;
            $nuevasCalorias = max(0, (float) $pendiente->calorias_estimadas - $desviacion * $participacion);

            $pendiente->update(['calorias_estimadas' => round($nuevasCalorias, 2)]);
        }
    }

    /**
     * calorias_consumidas of the RegistroDiario = sum of calorias_reales of
     * every ComidaReal logged so far that day.
     */
    private function actualizarCaloriasConsumidas(RegistroDiario $registroDiario): void
    {
        $total = ComidaReal::whereIn('plan_comida_id', $registroDiario->planesComida()->pluck('id'))
            ->sum('calorias_reales');

        $registroDiario->update(['calorias_consumidas' => $total]);
    }
}
