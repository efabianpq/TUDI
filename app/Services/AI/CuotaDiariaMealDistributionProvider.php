<?php

namespace App\Services\AI;

use App\Exceptions\MealDistributionUnavailableException;
use App\Models\User;
use App\Services\CuotaIaService;
use Illuminate\Contracts\Auth\Factory as Auth;

/**
 * Cuota diaria de llamadas al proveedor de IA (CLAUDE.md sección 5.20).
 *
 * Va en el mismo sitio y por el mismo motivo que el control de plan
 * (PremiumGatedMealDistributionProvider): esta interfaz es el único camino
 * hacia el proveedor, así que envolverla cubre de una vez la distribución del
 * día y la estimación de lo que se comió, y ningún camino nuevo puede
 * saltárselo por olvidar un `if` en un controlador (regla 14 de la sección 13).
 *
 * ── Orden de los dos envoltorios ────────────────────────────────────────────
 *
 * Premium por fuera, cuota por dentro: a un usuario del plan Gratis se le dice
 * qué plan necesita, no cuántas llamadas le quedan de una función que no tiene.
 *
 * ── Se descuenta al pedir ───────────────────────────────────────────────────
 *
 * Antes de llamar, no después: un intento que falla en el proveedor ya ha
 * costado tokens, y cobrar solo los aciertos dejaría un bucle de fallos
 * llamando gratis para siempre — justo lo que esto evita.
 *
 * ── Sin sesión no hay a quién contarle nada ─────────────────────────────────
 *
 * En consola (seeders, comandos programados) se deja pasar, igual que el
 * control de plan: no es alguien esquivando su cuota, es el propio sistema.
 */
class CuotaDiariaMealDistributionProvider implements MealDistributionProviderInterface
{
    public function __construct(
        private readonly MealDistributionProviderInterface $siguiente,
        private readonly CuotaIaService $cuotas,
        private readonly Auth $auth,
    ) {}

    public function distribuirDia(array $comidas, array $contextoDia): array
    {
        $this->descontar(CuotaIaService::CONCEPTO_DISTRIBUCION);

        return $this->siguiente->distribuirDia($comidas, $contextoDia);
    }

    public function estimarConsumoReal(array $comidas, array $contextoDia): array
    {
        $this->descontar(CuotaIaService::CONCEPTO_REPORTE);

        return $this->siguiente->estimarConsumoReal($comidas, $contextoDia);
    }

    /**
     * @throws MealDistributionUnavailableException cuando el usuario ya gastó su cuota de hoy
     */
    private function descontar(string $concepto): void
    {
        $usuario = $this->auth->guard()->user();

        if (! $usuario instanceof User) {
            return;
        }

        if (! $this->cuotas->consumir($usuario, $concepto)) {
            throw MealDistributionUnavailableException::cuotaDiariaAgotada(
                $concepto,
                $this->cuotas->limite($concepto),
            );
        }
    }
}
