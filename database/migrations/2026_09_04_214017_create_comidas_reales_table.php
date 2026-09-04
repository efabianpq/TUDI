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
        Schema::create('comidas_reales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_comida_id')->unique()->constrained('planes_comida')->cascadeOnDelete();
            $table->decimal('calorias_reales', 7, 2);
            $table->decimal('proteina_g', 6, 2);
            $table->decimal('grasa_g', 6, 2);
            $table->decimal('carbohidratos_g', 6, 2);
            $table->dateTime('consumido_en')->nullable();
            $table->text('notas')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('comidas_reales');
    }
};
