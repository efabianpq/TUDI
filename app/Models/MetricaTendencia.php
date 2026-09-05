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
    'promedio_movil_deficit_kcal',
    'indice_consistencia_pct',
    'dias_con_datos',
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
            'promedio_movil_deficit_kcal' => 'decimal:2',
            'indice_consistencia_pct' => 'decimal:2',
            'dias_con_datos' => 'integer',
            'porcentaje_perdida_semanal' => 'decimal:2',
        ];
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }
}
