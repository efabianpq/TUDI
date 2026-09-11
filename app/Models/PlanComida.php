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
    'origen',
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

    /** Lo generó "Ajustar mi plan" (o el generador heurístico): había plan. */
    public const ORIGEN_PLAN = 'plan';

    /**
     * Lo creó el reporte de una comida que nunca se planificó (CLAUDE.md
     * sección 5.5). Existe solo para colgar de él la ComidaReal: sus macros
     * estimados son cero porque no se sugirió nada.
     */
    public const ORIGEN_REPORTE = 'reporte';

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

    /**
     * ¿Es el recipiente de un reporte sin plan previo? La pantalla no debe
     * enseñar su "sugerido 0 kcal" como si fuera una sugerencia, y al reabrir
     * la comida se borra entero en vez de volver al estado "planificada".
     */
    public function esReporteSinPlan(): bool
    {
        return $this->origen === self::ORIGEN_REPORTE;
    }
}
