<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * El peso del día. Hasta ahora el peso vivía solo en users.peso_kg, un único
     * valor "actual" que se pisa cada vez que el usuario lo actualiza: sin
     * historial no hay promedio móvil de 7 días que calcular (sección 6).
     * RegistroDiario ya es el registro único por usuario+fecha, así que la serie
     * de peso vive aquí y no en una tabla nueva.
     */
    public function up(): void
    {
        Schema::table('registros_diarios', function (Blueprint $table) {
            $table->decimal('peso_kg', 5, 2)->nullable()->after('fecha');
        });
    }

    public function down(): void
    {
        Schema::table('registros_diarios', function (Blueprint $table) {
            $table->dropColumn('peso_kg');
        });
    }
};
