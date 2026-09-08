<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un parámetro maestro con el valor que le puso el administrador (CLAUDE.md
 * sección 4.27).
 *
 * Modelo deliberadamente tonto: qué claves existen, de qué tipo son y entre qué
 * límites se mueven lo declara el catálogo de ParametrosMaestrosService, que es
 * también el único punto por el que se leen y escriben. Nadie debería consultar
 * esta tabla directamente desde un servicio de dominio.
 */
#[Fillable(['clave', 'valor', 'actualizado_por'])]
class ParametroMaestro extends Model
{
    protected $table = 'parametros_maestros';

    public function actualizadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actualizado_por');
    }
}
