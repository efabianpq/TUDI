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
        Schema::table('registros_diarios', function (Blueprint $table) {
            // Protein target vs. protein actually eaten, frozen at closure time:
            // the target derives from the user's profile, which may change later.
            $table->decimal('proteina_objetivo_g', 6, 2)->nullable()->after('deficit_diario');
            $table->decimal('proteina_consumida_g', 6, 2)->nullable()->after('proteina_objetivo_g');
            // When the day was closed. Together with the existing "cerrado" flag
            // this is the whole closure state — no separate estado_cierre column.
            $table->dateTime('cerrado_en')->nullable()->after('cerrado');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('registros_diarios', function (Blueprint $table) {
            $table->dropColumn(['proteina_objetivo_g', 'proteina_consumida_g', 'cerrado_en']);
        });
    }
};
