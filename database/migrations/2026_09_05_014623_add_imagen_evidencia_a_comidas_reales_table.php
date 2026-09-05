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
        Schema::table('comidas_reales', function (Blueprint $table) {
            $table->string('imagen_evidencia')->nullable()->after('notas');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('comidas_reales', function (Blueprint $table) {
            $table->dropColumn('imagen_evidencia');
        });
    }
};
