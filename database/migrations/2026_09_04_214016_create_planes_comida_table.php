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
        Schema::create('planes_comida', function (Blueprint $table) {
            $table->id();
            $table->foreignId('registro_diario_id')->constrained('registros_diarios')->cascadeOnDelete();
            $table->enum('tipo_comida', ['desayuno', 'almuerzo', 'cena', 'snack']);
            $table->text('descripcion')->nullable();
            $table->decimal('calorias_estimadas', 7, 2);
            $table->decimal('proteina_g', 6, 2);
            $table->decimal('grasa_g', 6, 2);
            $table->decimal('carbohidratos_g', 6, 2);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('planes_comida');
    }
};
