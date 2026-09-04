<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable([
    'name',
    'email',
    'password',
    'peso_kg',
    'estatura_m',
    'edad',
    'sexo',
    'nivel_actividad',
    'tipo_deficit',
    'valor_deficit',
    'proteina_factor',
    'grasa_factor',
    'calorias_objetivo',
])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'peso_kg' => 'decimal:2',
            'estatura_m' => 'decimal:2',
            'nivel_actividad' => 'decimal:3',
            'valor_deficit' => 'decimal:2',
            'proteina_factor' => 'decimal:2',
            'grasa_factor' => 'decimal:2',
            'calorias_objetivo' => 'decimal:2',
        ];
    }

    /**
     * @return HasMany<RegistroDiario>
     */
    public function registrosDiarios(): HasMany
    {
        return $this->hasMany(RegistroDiario::class, 'usuario_id');
    }

    /**
     * @return HasMany<MetricaTendencia>
     */
    public function metricasTendencia(): HasMany
    {
        return $this->hasMany(MetricaTendencia::class, 'usuario_id');
    }
}
