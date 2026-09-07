<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Campos que produce la distribución generada por IA (CLAUDE.md sección
     * 4.12) y que no caben en `descripcion`: cómo preparar la comida y el aviso
     * del modelo sobre lo que no pudo cuadrar ("faltan ~20 g de proteína").
     *
     * Nullable porque la generación heurística de MealPlanGeneratorService
     * (sección 4.2) no los produce.
     */
    public function up(): void
    {
        Schema::table('planes_comida', function (Blueprint $table) {
            $table->text('preparacion')->nullable()->after('descripcion');
            $table->text('notas_ia')->nullable()->after('preparacion');
        });
    }

    public function down(): void
    {
        Schema::table('planes_comida', function (Blueprint $table) {
            $table->dropColumn(['preparacion', 'notas_ia']);
        });
    }
};
