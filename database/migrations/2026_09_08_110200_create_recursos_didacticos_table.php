<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Recursos didácticos que el administrador publica desde la consola (CLAUDE.md
 * sección 5.15): hoy, el video explicativo y la guía en PDF de la Calculadora.
 *
 * Misma forma que parametros_maestros —clave/valor, y una fila solo cuando hay
 * algo publicado— pero tabla aparte a propósito: aquello son umbrales de
 * criterio con tipo, mínimo y máximo; esto es contenido, y uno de los dos
 * valores es un archivo subido. Mezclarlos obligaría a que el catálogo de
 * parámetros supiera de `Storage`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recursos_didacticos', function (Blueprint $table) {
            $table->id();
            $table->string('clave', 64)->unique();
            // 'url' → $valor es una dirección; 'archivo' → $valor es la ruta en
            // el disco público. Lo declara el catálogo del servicio.
            $table->string('tipo', 16);
            $table->string('valor', 2048);
            // Nombre con el que el usuario subió el archivo, para el enlace de
            // descarga. Null cuando el recurso es una URL.
            $table->string('nombre_original')->nullable();
            $table->foreignId('actualizado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recursos_didacticos');
    }
};
