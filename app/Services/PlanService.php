<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Ciclo de vida del plan de un usuario (CLAUDE.md sección 5.18).
 *
 * Es el espejo de CuentaService: aquel decide si una cuenta entra a la
 * aplicación, este decide qué funciones tiene dentro. Se separan a propósito —
 * ningún plan deja a nadie fuera, y ningún estado de cuenta quita funciones.
 *
 * Toda transición de plan pasa por aquí y por ningún otro sitio:
 *
 *  - `iniciarPrueba()` — al registrarse, sin pedirlo y sin tarjeta.
 *  - `expirarVencidos()` — el barrido nocturno que baja a `gratis` las pruebas
 *    caducadas, sin tocar un solo dato del historial del usuario.
 *  - `activarPremium()` / `degradarAGratis()` — las dos manijas que usará la
 *    pasarela de pago cuando exista, y que hoy solo se llaman desde consola.
 *
 * Quién *tiene* Premium ahora mismo no se pregunta aquí sino a
 * `User::tienePremium()`, que compara contra el reloj: una prueba vencida deja
 * de valer en el acto, no cuando el cron pasa a medianoche.
 */
class PlanService
{
    /**
     * Arranca la prueba de Premium. Idempotente hacia arriba: no se la quita a
     * quien ya es Premium ni le acorta la prueba a quien ya la tiene corriendo.
     */
    public function iniciarPrueba(User $usuario, ?int $dias = null): User
    {
        if ($usuario->plan === User::PLAN_PREMIUM || $usuario->enPrueba()) {
            return $usuario;
        }

        $usuario->forceFill([
            'plan' => User::PLAN_TRIAL,
            'plan_expira_en' => Carbon::now()->addDays($dias ?? $this->diasDePrueba()),
        ])->save();

        return $usuario;
    }

    /**
     * Baja a `gratis` toda prueba cuya fecha ya pasó. Devuelve cuántas.
     *
     * Un UPDATE masivo y no un bucle de modelos: no hay eventos que disparar ni
     * nada que recalcular. Lo único que cambia es qué funciones ve el usuario —
     * sus registros diarios, sus tendencias y sus recomendaciones siguen
     * exactamente donde estaban (requisito de la sección 5.18: vencer la prueba
     * nunca borra ni oculta datos históricos).
     */
    public function expirarVencidos(?Carbon $hasta = null): int
    {
        return User::query()
            ->where('plan', User::PLAN_TRIAL)
            ->whereNotNull('plan_expira_en')
            ->where('plan_expira_en', '<=', $hasta ?? Carbon::now())
            ->update([
                'plan' => User::PLAN_GRATIS,
                'plan_expira_en' => null,
            ]);
    }

    /**
     * Premium de verdad. `$hasta = null` es Premium sin caducidad; cuando exista
     * la pasarela, aquí llegará la fecha del siguiente cobro.
     */
    public function activarPremium(User $usuario, ?Carbon $hasta = null): User
    {
        $usuario->forceFill([
            'plan' => User::PLAN_PREMIUM,
            'plan_expira_en' => $hasta,
        ])->save();

        return $usuario;
    }

    public function degradarAGratis(User $usuario): User
    {
        $usuario->forceFill([
            'plan' => User::PLAN_GRATIS,
            'plan_expira_en' => null,
        ])->save();

        return $usuario;
    }

    public function diasDePrueba(): int
    {
        return max(1, (int) config('planes.prueba_dias', 3));
    }

    /**
     * Los precios ya formateados para pintarlos, más el descuento anual
     * DERIVADO de los dos importes en vez de declarado aparte: así no puede
     * contradecirlos cuando alguno cambie (sección 5.18).
     *
     * @return array{moneda: string, mensual: int, anual: int, mensual_formateado: string, anual_formateado: string, ahorro_anual_pct: int, prueba_dias: int}
     */
    public function precios(): array
    {
        $moneda = (string) config('planes.precio.moneda', 'COP');
        $mensual = (int) config('planes.precio.mensual', 0);
        $anual = (int) config('planes.precio.anual', 0);

        $doceMeses = $mensual * 12;

        return [
            'moneda' => $moneda,
            'mensual' => $mensual,
            'anual' => $anual,
            'mensual_formateado' => $this->formatear($moneda, $mensual),
            'anual_formateado' => $this->formatear($moneda, $anual),
            'ahorro_anual_pct' => $doceMeses > 0
                ? (int) round((1 - $anual / $doceMeses) * 100)
                : 0,
            'prueba_dias' => $this->diasDePrueba(),
        ];
    }

    /**
     * Formato colombiano: punto de millar y sin decimales — los precios en COP
     * no llevan centavos.
     */
    private function formatear(string $moneda, int $importe): string
    {
        return $moneda.' $'.number_format($importe, 0, ',', '.');
    }
}
