<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Las dos métricas del snapshot de tendencia que la tabla original no
     * contemplaba: el promedio móvil de déficit calórico y el índice de
     * consistencia. `promedio_movil_calorias` se conserva con su significado
     * original (calorías consumidas), no se recicla para el déficit: son dos
     * cifras distintas y confundirlas falsearía el balance energético.
     */
    public function up(): void
    {
        Schema::table('metricas_tendencia', function (Blueprint $table) {
            $table->decimal('promedio_movil_deficit_kcal', 7, 2)->nullable()->after('promedio_movil_calorias');
            $table->decimal('indice_consistencia_pct', 5, 2)->nullable()->after('promedio_movil_deficit_kcal');
            $table->unsignedTinyInteger('dias_con_datos')->default(0)->after('indice_consistencia_pct');
        });
    }

    public function down(): void
    {
        Schema::table('metricas_tendencia', function (Blueprint $table) {
            $table->dropColumn(['promedio_movil_deficit_kcal', 'indice_consistencia_pct', 'dias_con_datos']);
        });
    }
};
