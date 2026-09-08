<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Parámetros maestros del dominio (CLAUDE.md sección 4.27).
 *
 * Una sola tabla clave/valor en vez de una columna por parámetro: el catálogo
 * de qué claves existen, de qué tipo son y entre qué límites se mueven vive en
 * ParametrosMaestrosService, que es también quien valida. La tabla solo guarda
 * las que el administrador ha cambiado respecto del valor por defecto — una
 * clave ausente significa "el valor de fábrica", no "sin configurar".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parametros_maestros', function (Blueprint $table) {
            $table->id();
            $table->string('clave', 64)->unique();
            // Texto: el tipo real lo declara el catálogo del servicio, que es
            // quien convierte al leer. Guardar todo como texto evita una
            // columna por tipo para una tabla de una decena de filas.
            $table->string('valor', 255);
            $table->foreignId('actualizado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('parametros_maestros');
    }
};
