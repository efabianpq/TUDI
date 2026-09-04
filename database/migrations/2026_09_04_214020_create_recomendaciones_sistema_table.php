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
        Schema::create('recomendaciones_sistema', function (Blueprint $table) {
            $table->id();
            $table->foreignId('registro_diario_id')->constrained('registros_diarios')->cascadeOnDelete();
            $table->string('tipo')->default('ajuste_calorico');
            $table->decimal('calorias_objetivo_sugeridas', 7, 2)->nullable();
            $table->text('justificacion');
            $table->enum('estado', ['pendiente', 'confirmada', 'rechazada'])->default('pendiente');
            $table->dateTime('confirmada_en')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('recomendaciones_sistema');
    }
};
