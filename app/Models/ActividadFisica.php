<?php

namespace App\Models;

use Database\Factories\ActividadFisicaFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'registro_diario_id',
    'tipo',
    'duracion_min',
    'pasos',
    'calorias_dispositivo',
    'factor_correccion',
    'calorias_ajustadas',
    'fuente',
])]
class ActividadFisica extends Model
{
    /** @use HasFactory<ActividadFisicaFactory> */
    use HasFactory;

    protected $table = 'actividades_fisicas';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'duracion_min' => 'integer',
            'pasos' => 'integer',
            'calorias_dispositivo' => 'decimal:2',
            'factor_correccion' => 'decimal:2',
            'calorias_ajustadas' => 'decimal:2',
        ];
    }

    public function registroDiario(): BelongsTo
    {
        return $this->belongsTo(RegistroDiario::class, 'registro_diario_id');
    }
}
