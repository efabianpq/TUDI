<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rol y ciclo de vida de la cuenta (CLAUDE.md sección 4.26).
 *
 * Cualquiera puede registrarse, pero la cuenta nace `pendiente` y no entra a la
 * aplicación hasta que el usuario introduce el código de activación que le
 * entrega el administrador. Es la validación manual que pide el negocio.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->enum('rol', ['usuario', 'admin'])->default('usuario')->after('password');

            /*
             * El default es `activo`, no `pendiente`: esta migración corre sobre
             * una base con usuarios que ya entraban con normalidad, y ponerlos
             * en pendiente los dejaría fuera de su propia cuenta. Quien nace
             * pendiente es cada registro nuevo, que lo fija explícitamente en
             * RegisteredUserController.
             */
            $table->enum('estado', ['pendiente', 'activo', 'suspendido'])->default('activo')->after('rol');

            // Lo entrega el administrador por fuera de la aplicación; se borra
            // en cuanto se canjea.
            $table->string('codigo_activacion', 16)->nullable()->after('estado');
            $table->dateTime('activado_en')->nullable()->after('codigo_activacion');

            // El listado de administración filtra por estos dos.
            $table->index(['estado', 'rol']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['estado', 'rol']);
            $table->dropColumn(['rol', 'estado', 'codigo_activacion', 'activado_en']);
        });
    }
};
