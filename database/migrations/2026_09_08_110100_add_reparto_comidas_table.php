<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reparto de calorías entre comidas, ajustable (CLAUDE.md sección 5.14).
 *
 * El 25/40/35 de MealPlanGeneratorService::DISTRIBUCION_COMIDAS sigue siendo el
 * valor de fábrica y la única declaración de qué comidas existen. Estas dos
 * columnas solo guardan una desviación respecto de él:
 *
 *  - `users.reparto_comidas` — el reparto habitual del usuario, que se aplica a
 *    los días nuevos.
 *  - `registros_diarios.reparto_comidas` — el reparto de ESE día, que manda
 *    sobre el habitual.
 *
 * Null en las dos significa "el de fábrica". Se guardan como json con las
 * mismas claves que DISTRIBUCION_COMIDAS y valores en proporción (0–1), no en
 * porcentaje: es la unidad con la que trabaja el reparto en PHP.
 *
 * Un plan ya generado NO se recalcula al cambiar el reparto: sus macros están
 * persistidos en planes_comida. El reparto solo dimensiona lo que queda por
 * generar y los objetivos que se muestran.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->json('reparto_comidas')->nullable()->after('calorias_objetivo');
        });

        Schema::table('registros_diarios', function (Blueprint $table) {
            $table->json('reparto_comidas')->nullable()->after('ingredientes_cena');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('reparto_comidas');
        });

        Schema::table('registros_diarios', function (Blueprint $table) {
            $table->dropColumn('reparto_comidas');
        });
    }
};
