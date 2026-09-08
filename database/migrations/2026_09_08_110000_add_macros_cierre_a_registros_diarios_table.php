<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Completa el snapshot del cierre con los dos macros que faltaban (CLAUDE.md
 * sección 5.5).
 *
 * El cierre ya congelaba calorías y proteína, objetivo y consumido. La tarjeta
 * de "Resultado real del día" muestra los cuatro macros, y para un día cerrado
 * tienen que salir del snapshot y no del perfil actual del usuario: si mañana
 * cambia su grasa_factor, el resultado de ayer no puede moverse.
 *
 * Nullable a propósito: los días cerrados antes de esta migración no tienen
 * estas cifras y se muestran como "—", no como cero.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('registros_diarios', function (Blueprint $table) {
            $table->decimal('grasa_objetivo_g', 6, 2)->nullable()->after('proteina_consumida_g');
            $table->decimal('grasa_consumida_g', 6, 2)->nullable()->after('grasa_objetivo_g');
            $table->decimal('carbohidratos_objetivo_g', 7, 2)->nullable()->after('grasa_consumida_g');
            $table->decimal('carbohidratos_consumidos_g', 7, 2)->nullable()->after('carbohidratos_objetivo_g');
        });
    }

    public function down(): void
    {
        Schema::table('registros_diarios', function (Blueprint $table) {
            $table->dropColumn([
                'grasa_objetivo_g',
                'grasa_consumida_g',
                'carbohidratos_objetivo_g',
                'carbohidratos_consumidos_g',
            ]);
        });
    }
};
