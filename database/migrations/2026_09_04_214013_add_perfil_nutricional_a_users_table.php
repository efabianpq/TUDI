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
        Schema::table('users', function (Blueprint $table) {
            $table->decimal('peso_kg', 5, 2)->nullable()->after('password');
            $table->decimal('estatura_m', 3, 2)->nullable()->after('peso_kg');
            $table->unsignedTinyInteger('edad')->nullable()->after('estatura_m');
            $table->enum('sexo', ['masculino', 'femenino'])->nullable()->after('edad');
            $table->decimal('nivel_actividad', 4, 3)->nullable()->after('sexo');
            $table->enum('tipo_deficit', ['porcentaje', 'fijo'])->nullable()->after('nivel_actividad');
            $table->decimal('valor_deficit', 6, 2)->nullable()->after('tipo_deficit');
            $table->decimal('proteina_factor', 3, 2)->nullable()->after('valor_deficit');
            $table->decimal('grasa_factor', 3, 2)->nullable()->after('proteina_factor');
            $table->decimal('calorias_objetivo', 7, 2)->nullable()->after('grasa_factor');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'peso_kg',
                'estatura_m',
                'edad',
                'sexo',
                'nivel_actividad',
                'tipo_deficit',
                'valor_deficit',
                'proteina_factor',
                'grasa_factor',
                'calorias_objetivo',
            ]);
        });
    }
};
