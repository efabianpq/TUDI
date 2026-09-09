<?php

namespace App\Services\AI;

use App\Exceptions\MealDistributionUnavailableException;
use App\Models\User;
use Illuminate\Contracts\Auth\Factory as Auth;

/**
 * Control de acceso por plan de las dos funciones que llaman al proveedor de IA
 * (CLAUDE.md sección 5.18): la distribución de comidas y la estimación de lo
 * que se comió de verdad.
 *
 * ── Por qué un decorador y no un `if` en cada controlador ────────────────────
 *
 * El requisito es que el control viva en el mismo punto donde hoy se invoca al
 * proveedor, sin duplicarse. Ese punto es exactamente esta interfaz: la cruzan
 * MealDistributionService (al distribuir el día) y CierreFeedbackService (al
 * cerrarlo), y no hay ningún otro camino hacia Gemini. Envolviéndola, las dos
 * funciones quedan cubiertas por una sola comprobación y ningún camino nuevo
 * que se añada mañana puede saltársela por olvido.
 *
 * No inventa una excepción propia: lanza la misma
 * MealDistributionUnavailableException que ya traducen PlanComidaController y
 * CierreDiarioController a un mensaje en pantalla, así que el usuario del plan
 * Gratis ve una explicación de qué le falta y no un error genérico ni un 500
 * (regla 6 de la sección 13). Ningún controlador cambia por esto.
 *
 * ── Sin sesión, no hay plan que comprobar ───────────────────────────────────
 *
 * En consola (seeders, comandos programados) no hay usuario autenticado. Ahí se
 * deja pasar: no es un usuario esquivando su plan, es código del propio sistema
 * — y hoy nada de eso llama al proveedor (DemoSeeder escribe sus planes de un
 * catálogo justamente para no gastar llamadas facturables, sección 5.17).
 */
class PremiumGatedMealDistributionProvider implements MealDistributionProviderInterface
{
    public function __construct(
        private readonly MealDistributionProviderInterface $siguiente,
        private readonly Auth $auth,
    ) {}

    public function distribuirDia(array $comidas, array $contextoDia): array
    {
        $this->exigirPremium();

        return $this->siguiente->distribuirDia($comidas, $contextoDia);
    }

    public function estimarConsumoReal(array $comidas, array $contextoDia): array
    {
        $this->exigirPremium();

        return $this->siguiente->estimarConsumoReal($comidas, $contextoDia);
    }

    /**
     * @throws MealDistributionUnavailableException cuando quien pide la llamada no tiene plan vigente
     */
    private function exigirPremium(): void
    {
        $usuario = $this->auth->guard()->user();

        if (! $usuario instanceof User || $usuario->tienePremium()) {
            return;
        }

        throw MealDistributionUnavailableException::requierePremium($usuario->pruebaTerminada());
    }
}
