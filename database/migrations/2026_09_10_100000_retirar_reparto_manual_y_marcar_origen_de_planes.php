<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dos cambios que van juntos porque son las dos caras del mismo rediseño del
 * plan diario (CLAUDE.md secciones 5.14 y 5.20).
 *
 * ── 1. Se retira el reparto manual entre comidas ───────────────────────────
 *
 * El reparto en porcentajes que el usuario tecleaba ("Reparto del día") se
 * retiró: repartir el día es una decisión nutricional, no una preferencia de
 * interfaz. Ahora lo calcula RepartoComidasService a partir de un reparto
 * balanceado y de la actividad física registrada de ese día, así que no queda
 * nada que persistir — un reparto guardado a mano solo podría contradecir al
 * que el servicio deriva.
 *
 * Las dos columnas se van enteras en vez de quedarse como columnas muertas: lo
 * que guardaban era la preferencia de una pantalla que ya no existe.
 *
 * ── 2. `planes_comida.origen` ──────────────────────────────────────────────
 *
 * Con el cierre por comida (sección 5.5) se puede reportar lo que se comió
 * aunque nunca se generara un plan para esa comida. Esos reportes necesitan un
 * PlanComida donde colgar su ComidaReal, pero no son un plan: nada se planificó
 * y sus macros estimados son cero.
 *
 *  - `plan`    — lo generó "Ajustar mi plan" (o el generador heurístico).
 *  - `reporte` — lo creó el reporte de una comida sin plan previo.
 *
 * Distinguirlos importa en dos sitios: al reabrir la comida (un `reporte` se
 * borra entero, un `plan` sobrevive y vuelve a estar "planificado") y en la
 * pantalla, que no debe enseñar "sugerido 0 kcal" como si fuera una sugerencia.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('reparto_comidas');
        });

        Schema::table('registros_diarios', function (Blueprint $table) {
            $table->dropColumn('reparto_comidas');
        });

        Schema::table('planes_comida', function (Blueprint $table) {
            $table->enum('origen', ['plan', 'reporte'])->default('plan')->after('tipo_comida');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->json('reparto_comidas')->nullable()->after('calorias_objetivo');
        });

        Schema::table('registros_diarios', function (Blueprint $table) {
            $table->json('reparto_comidas')->nullable()->after('ingredientes_cena');
        });

        Schema::table('planes_comida', function (Blueprint $table) {
            $table->dropColumn('origen');
        });
    }
};
