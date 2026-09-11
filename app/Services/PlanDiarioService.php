<?php

namespace App\Services;

use App\Models\RegistroDiario;
use Illuminate\Support\Facades\DB;

/**
 * Ciclo de vida de un plan diario completo (CLAUDE.md sección 5.16): dos
 * acciones destructivas que el usuario pide explícitamente.
 *
 *  - **Resetear** — "no me sirve lo que llevo de hoy, quiero empezar de cero":
 *    el día sigue existiendo pero se queda como recién creado. Es lo que hace
 *    desaparecer las sugerencias ya generadas, que era la queja concreta.
 *  - **Eliminar** — el día entero sobra (se abrió por error, no se llenó nada).
 *
 * Las dos borran lo mismo; la diferencia es si la fila `registros_diarios`
 * sobrevive. Se agrupan aquí, y no en DailyClosureService, porque no son parte
 * del cierre: el cierre congela cifras, esto las borra.
 *
 * ── Qué arrastra ───────────────────────────────────────────────────────────
 *
 * Las FK del modelo de datos son `cascade` (sección 4), así que borrar los
 * PlanComida se lleva sus ComidaReal. Lo que la base de datos no sabe borrar
 * son las imágenes de evidencia del disco: de eso se encarga
 * ComidaRealService::borrarImagenesDelDia() antes de tocar ninguna fila.
 *
 * ── El peso del día ────────────────────────────────────────────────────────
 *
 * También se borra: resetear es "volver a empezar", y dejar un peso suelto de
 * un día del que ya no queda nada sería un dato huérfano. No rompe la ventana
 * de 7 días de TrendAnalyticsService: el promedio móvil ignora los días sin
 * peso en vez de contarlos como cero (sección 5.7), y el índice de consistencia
 * mide días cerrados, no pesajes. Nadie se pesa a diario, y el seguimiento no
 * depende solo del peso.
 */
class PlanDiarioService
{
    public function __construct(
        private readonly ComidaRealService $comidaRealService,
        private readonly ObjetivoDelDiaService $objetivoDelDia,
    ) {}

    /**
     * Vacía el día y lo deja abierto, como recién creado: sin planes de comida,
     * sin lo registrado como comido, sin actividades, sin recomendaciones, sin
     * los textos de ingredientes, sin peso y sin las cifras del cierre.
     *
     * Conserva la fecha, el reparto del día (sección 5.14) y el propio
     * RegistroDiario, para no romper el enlace desde el que se pulsó.
     */
    public function resetear(RegistroDiario $registroDiario): void
    {
        $this->comidaRealService->borrarImagenesDelDia($registroDiario);

        DB::transaction(function () use ($registroDiario) {
            $registroDiario->planesComida()->delete();
            $registroDiario->actividadesFisicas()->delete();
            $registroDiario->recomendacionesSistema()->delete();
            $registroDiario->ingredientesDisponibles()->delete();

            $registroDiario->update([
                'ingredientes_desayuno' => null,
                'ingredientes_almuerzo' => null,
                'ingredientes_cena' => null,
                'peso_kg' => null,
                'calorias_objetivo_dia' => null,
                'calorias_consumidas' => null,
                'calorias_actividad_ajustada' => null,
                'deficit_diario' => null,
                'proteina_objetivo_g' => null,
                'proteina_consumida_g' => null,
                'grasa_objetivo_g' => null,
                'grasa_consumida_g' => null,
                'carbohidratos_objetivo_g' => null,
                'carbohidratos_consumidos_g' => null,
                'cerrado' => false,
                'cerrado_en' => null,
            ]);

            /*
             * "Como recién creado" incluye el sello del objetivo (sección
             * 5.25): reiniciar solo se ofrece sobre el día de hoy, así que
             * volver a sellarlo con el perfil vigente es exactamente lo que
             * haría crearlo de nuevo. Sin esto el día quedaba sin sello y
             * pasaba a comportarse como un día heredado.
             */
            $this->objetivoDelDia->sellar($registroDiario->refresh());
        });
    }

    /**
     * Borra el plan diario entero. La cascada de las FK se lleva el resto del
     * día; las imágenes del disco hay que borrarlas antes.
     */
    public function eliminar(RegistroDiario $registroDiario): void
    {
        $this->comidaRealService->borrarImagenesDelDia($registroDiario);

        $registroDiario->delete();
    }
}
