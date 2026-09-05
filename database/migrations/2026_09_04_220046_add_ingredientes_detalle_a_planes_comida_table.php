<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('planes_comida', function (Blueprint $table) {
            // Snapshot of the ingredients (and grams) MealPlanGeneratorService picked
            // for this meal. Denormalised on purpose: it must stay readable even if
            // the underlying IngredienteDisponible rows are edited or deleted later.
            $table->json('ingredientes_detalle')->nullable()->after('descripcion');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('planes_comida', function (Blueprint $table) {
            $table->dropColumn('ingredientes_detalle');
        });
    }
};
