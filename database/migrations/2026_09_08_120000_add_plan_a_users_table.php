<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plan del usuario (CLAUDE.md sección 5.18).
 *
 * Dos columnas en `users` y no una tabla `suscripciones`: mientras no haya
 * cobro, un usuario tiene exactamente un plan y una fecha en la que deja de
 * tenerlo. Es el mismo patrón que ya usa el ciclo de vida de la cuenta
 * (`estado` + `activado_en`), no un mecanismo nuevo. Cuando entre la pasarela
 * de pago hará falta el historial de cobros — esa sesión añadirá sus tablas y
 * estas dos columnas seguirán siendo el plan vigente.
 *
 * `gratis` por defecto: las cuentas que ya existían no estrenan una prueba
 * retroactiva de la que nadie les avisó.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $tabla) {
            $tabla->enum('plan', [
                User::PLAN_GRATIS,
                User::PLAN_TRIAL,
                User::PLAN_PREMIUM,
            ])->default(User::PLAN_GRATIS)->after('estado');

            // Null en `premium` significa "sin caducidad" (hoy solo lo pone un
            // administrador a mano); en `trial` es cuándo cae a `gratis`.
            $tabla->timestamp('plan_expira_en')->nullable()->after('plan');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $tabla) {
            $tabla->dropColumn(['plan', 'plan_expira_en']);
        });
    }
};
