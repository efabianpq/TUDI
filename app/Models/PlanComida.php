<?php

namespace App\Models;

use Database\Factories\PlanComidaFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'registro_diario_id',
    'tipo_comida',
    'descripcion',
    'preparacion',
    'notas_ia',
    'ingredientes_detalle',
    'calorias_estimadas',
    'proteina_g',
    'grasa_g',
    'carbohidratos_g',
])]
class PlanComida extends Model
{
    /** @use HasFactory<PlanComidaFactory> */
    use HasFactory;

    protected $table = 'planes_comida';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'ingredientes_detalle' => 'array',
            'calorias_estimadas' => 'decimal:2',
            'proteina_g' => 'decimal:2',
            'grasa_g' => 'decimal:2',
            'carbohidratos_g' => 'decimal:2',
        ];
    }

    public function registroDiario(): BelongsTo
    {
        return $this->belongsTo(RegistroDiario::class, 'registro_diario_id');
    }

    public function comidaReal(): HasOne
    {
        return $this->hasOne(ComidaReal::class, 'plan_comida_id');
    }
}
