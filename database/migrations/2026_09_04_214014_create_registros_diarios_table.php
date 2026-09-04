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
        Schema::create('registros_diarios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('usuario_id')->constrained('users')->cascadeOnDelete();
            $table->date('fecha');
            $table->decimal('calorias_objetivo_dia', 7, 2)->nullable();
            $table->decimal('calorias_consumidas', 7, 2)->nullable();
            $table->decimal('calorias_actividad_ajustada', 7, 2)->nullable();
            $table->decimal('deficit_diario', 7, 2)->nullable();
            $table->boolean('cerrado')->default(false);
            $table->timestamps();

            $table->unique(['usuario_id', 'fecha']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('registros_diarios');
    }
};
