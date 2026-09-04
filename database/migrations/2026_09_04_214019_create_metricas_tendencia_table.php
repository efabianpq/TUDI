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
        Schema::create('metricas_tendencia', function (Blueprint $table) {
            $table->id();
            $table->foreignId('usuario_id')->constrained('users')->cascadeOnDelete();
            $table->date('fecha');
            $table->decimal('promedio_movil_peso_kg', 5, 2)->nullable();
            $table->decimal('promedio_movil_calorias', 7, 2)->nullable();
            $table->decimal('porcentaje_perdida_semanal', 5, 2)->nullable();
            $table->enum('tendencia', ['perdida_lenta', 'perdida_adecuada', 'perdida_rapida', 'estable', 'ganancia'])->nullable();
            $table->timestamps();

            $table->unique(['usuario_id', 'fecha']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('metricas_tendencia');
    }
};
