<?php

namespace App\Models;

use Database\Factories\RecomendacionSistemaFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'registro_diario_id',
    'tipo',
    'calorias_objetivo_sugeridas',
    'justificacion',
    'estado',
    'confirmada_en',
])]
class RecomendacionSistema extends Model
{
    /** @use HasFactory<RecomendacionSistemaFactory> */
    use HasFactory;

    protected $table = 'recomendaciones_sistema';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'calorias_objetivo_sugeridas' => 'decimal:2',
            'confirmada_en' => 'datetime',
        ];
    }

    public function registroDiario(): BelongsTo
    {
        return $this->belongsTo(RegistroDiario::class, 'registro_diario_id');
    }
}
