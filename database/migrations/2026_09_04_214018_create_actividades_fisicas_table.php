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
        Schema::create('actividades_fisicas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('registro_diario_id')->constrained('registros_diarios')->cascadeOnDelete();
            $table->string('tipo');
            $table->unsignedInteger('duracion_min');
            $table->decimal('calorias_dispositivo', 6, 2)->nullable();
            $table->decimal('factor_correccion', 3, 2)->default(0.85);
            $table->decimal('calorias_ajustadas', 6, 2)->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('actividades_fisicas');
    }
};
