<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un recurso didáctico publicado por el administrador (CLAUDE.md sección 5.15).
 *
 * Qué claves existen y de qué tipo es cada una lo declara el catálogo de
 * RecursosDidacticosService; el modelo solo guarda la fila. Una clave sin fila
 * significa "todavía no se ha publicado", no "vacío".
 */
#[Fillable([
    'clave',
    'tipo',
    'valor',
    'nombre_original',
    'actualizado_por',
])]
class RecursoDidactico extends Model
{
    protected $table = 'recursos_didacticos';

    /** El valor es una dirección web (un video incrustado, típicamente). */
    public const TIPO_URL = 'url';

    /** El valor es la ruta de un archivo subido al disco público. */
    public const TIPO_ARCHIVO = 'archivo';

    public function actualizadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actualizado_por');
    }
}
