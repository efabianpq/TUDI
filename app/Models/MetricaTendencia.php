<?php

namespace App\Models;

use Database\Factories\MetricaTendenciaFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'usuario_id',
    'fecha',
    'promedio_movil_peso_kg',
    'promedio_movil_calorias',
    'porcentaje_perdida_semanal',
    'tendencia',
])]
class MetricaTendencia extends Model
{
    /** @use HasFactory<MetricaTendenciaFactory> */
    use HasFactory;

    protected $table = 'metricas_tendencia';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'fecha' => 'date',
            'promedio_movil_peso_kg' => 'decimal:2',
            'promedio_movil_calorias' => 'decimal:2',
            'porcentaje_perdida_semanal' => 'decimal:2',
        ];
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }
}
