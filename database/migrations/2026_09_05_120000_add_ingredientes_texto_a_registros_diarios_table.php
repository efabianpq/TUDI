<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Texto libre (dictado o escrito) con los ingredientes disponibles para
     * cada comida del día. Es el prompt que MealDistributionService le pasa a
     * Claude Haiku 4.5 — ver CLAUDE.md sección 4.12.
     *
     * Una columna por comida en lugar de una tabla nueva: RegistroDiario ya es
     * la fila única por usuario+fecha y el reparto de comidas es fijo
     * (MealPlanGeneratorService::DISTRIBUCION_COMIDAS), así que no hay
     * cardinalidad variable que justifique una tabla aparte.
     */
    public function up(): void
    {
        Schema::table('registros_diarios', function (Blueprint $table) {
            $table->text('ingredientes_desayuno')->nullable()->after('peso_kg');
            $table->text('ingredientes_almuerzo')->nullable()->after('ingredientes_desayuno');
            $table->text('ingredientes_cena')->nullable()->after('ingredientes_almuerzo');
        });
    }

    public function down(): void
    {
        Schema::table('registros_diarios', function (Blueprint $table) {
            $table->dropColumn(['ingredientes_desayuno', 'ingredientes_almuerzo', 'ingredientes_cena']);
        });
    }
};
