<?php

namespace App\Models;

use Database\Factories\RegistroDiarioFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'usuario_id',
    'fecha',
    'peso_kg',
    'calorias_objetivo_dia',
    'calorias_consumidas',
    'calorias_actividad_ajustada',
    'deficit_diario',
    'proteina_objetivo_g',
    'proteina_consumida_g',
    'cerrado',
    'cerrado_en',
])]
class RegistroDiario extends Model
{
    /** @use HasFactory<RegistroDiarioFactory> */
    use HasFactory;

    protected $table = 'registros_diarios';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'fecha' => 'date',
            'peso_kg' => 'decimal:2',
            'calorias_objetivo_dia' => 'decimal:2',
            'calorias_consumidas' => 'decimal:2',
            'calorias_actividad_ajustada' => 'decimal:2',
            'deficit_diario' => 'decimal:2',
            'proteina_objetivo_g' => 'decimal:2',
            'proteina_consumida_g' => 'decimal:2',
            'cerrado' => 'boolean',
            'cerrado_en' => 'datetime',
        ];
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_id');
    }

    /**
     * @return HasMany<IngredienteDisponible>
     */
    public function ingredientesDisponibles(): HasMany
    {
        return $this->hasMany(IngredienteDisponible::class, 'registro_diario_id');
    }

    /**
     * @return HasMany<PlanComida>
     */
    public function planesComida(): HasMany
    {
        return $this->hasMany(PlanComida::class, 'registro_diario_id');
    }

    /**
     * @return HasMany<ActividadFisica>
     */
    public function actividadesFisicas(): HasMany
    {
        return $this->hasMany(ActividadFisica::class, 'registro_diario_id');
    }

    /**
     * @return HasMany<RecomendacionSistema>
     */
    public function recomendacionesSistema(): HasMany
    {
        return $this->hasMany(RecomendacionSistema::class, 'registro_diario_id');
    }
}
