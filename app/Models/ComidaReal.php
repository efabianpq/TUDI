<?php

namespace App\Models;

use Database\Factories\ComidaRealFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'plan_comida_id',
    'calorias_reales',
    'proteina_g',
    'grasa_g',
    'carbohidratos_g',
    'consumido_en',
    'notas',
])]
class ComidaReal extends Model
{
    /** @use HasFactory<ComidaRealFactory> */
    use HasFactory;

    protected $table = 'comidas_reales';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'calorias_reales' => 'decimal:2',
            'proteina_g' => 'decimal:2',
            'grasa_g' => 'decimal:2',
            'carbohidratos_g' => 'decimal:2',
            'consumido_en' => 'datetime',
        ];
    }

    public function planComida(): BelongsTo
    {
        return $this->belongsTo(PlanComida::class, 'plan_comida_id');
    }
}
