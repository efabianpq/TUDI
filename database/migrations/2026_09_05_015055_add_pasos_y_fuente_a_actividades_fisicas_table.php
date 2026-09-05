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
        Schema::table('actividades_fisicas', function (Blueprint $table) {
            $table->unsignedInteger('pasos')->nullable()->after('duracion_min');
            $table->enum('fuente', ['manual', 'dispositivo'])->default('manual')->after('calorias_ajustadas');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('actividades_fisicas', function (Blueprint $table) {
            $table->dropColumn(['pasos', 'fuente']);
        });
    }
};
