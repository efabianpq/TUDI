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
        Schema::create('ingredientes_disponibles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('registro_diario_id')->constrained('registros_diarios')->cascadeOnDelete();
            $table->string('nombre');
            $table->decimal('cantidad_g', 7, 2);
            $table->decimal('calorias_por_100g', 6, 2);
            $table->decimal('proteina_por_100g', 5, 2);
            $table->decimal('grasa_por_100g', 5, 2);
            $table->decimal('carbohidratos_por_100g', 5, 2);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ingredientes_disponibles');
    }
};
