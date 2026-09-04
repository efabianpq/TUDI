<?php

namespace App\Models;

use Database\Factories\IngredienteDisponibleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'registro_diario_id',
    'nombre',
    'cantidad_g',
    'calorias_por_100g',
    'proteina_por_100g',
    'grasa_por_100g',
    'carbohidratos_por_100g',
])]
class IngredienteDisponible extends Model
{
    /** @use HasFactory<IngredienteDisponibleFactory> */
    use HasFactory;

    protected $table = 'ingredientes_disponibles';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'cantidad_g' => 'decimal:2',
            'calorias_por_100g' => 'decimal:2',
            'proteina_por_100g' => 'decimal:2',
            'grasa_por_100g' => 'decimal:2',
            'carbohidratos_por_100g' => 'decimal:2',
        ];
    }

    public function registroDiario(): BelongsTo
    {
        return $this->belongsTo(RegistroDiario::class, 'registro_diario_id');
    }
}
