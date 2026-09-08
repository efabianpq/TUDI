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

/**
 * Los cuatro campos de administración (`rol`, `estado`, `codigo_activacion`,
 * `activado_en`) son asignables en masa porque las factories y los servicios de
 * administración los escriben así; ningún formulario de cara al usuario los
 * acepta — todos los controladores parten de `$request->validated()` de un Form
 * Request con reglas explícitas (CLAUDE.md sección 10).
 */
#[Fillable([
    'name',
    'email',
    'password',
    'rol',
    'estado',
    'codigo_activacion',
    'activado_en',
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
    'reparto_comidas',
])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    public const ROL_USUARIO = 'usuario';

    public const ROL_ADMIN = 'admin';

    /** Registrada pero todavía sin canjear su código de activación. */
    public const ESTADO_PENDIENTE = 'pendiente';

    public const ESTADO_ACTIVO = 'activo';

    /** Bloqueada por un administrador: conserva sus datos pero no puede entrar. */
    public const ESTADO_SUSPENDIDO = 'suspendido';

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
            'activado_en' => 'datetime',
            'peso_kg' => 'decimal:2',
            'estatura_m' => 'decimal:2',
            'nivel_actividad' => 'decimal:3',
            'valor_deficit' => 'decimal:2',
            'proteina_factor' => 'decimal:2',
            'grasa_factor' => 'decimal:2',
            'calorias_objetivo' => 'decimal:2',
            'reparto_comidas' => 'array',
        ];
    }

    public function esAdministrador(): bool
    {
        return $this->rol === self::ROL_ADMIN;
    }

    /**
     * Solo una cuenta activa entra a la aplicación. Una `pendiente` va a la
     * pantalla del código de activación y una `suspendida` no pasa del login
     * (CLAUDE.md sección 4.26).
     */
    public function estaActiva(): bool
    {
        return $this->estado === self::ESTADO_ACTIVO;
    }

    /**
     * Marca la cuenta como activa y quema el código: un código canjeado no
     * vuelve a servir.
     */
    public function activar(): void
    {
        $this->forceFill([
            'estado' => self::ESTADO_ACTIVO,
            'codigo_activacion' => null,
            'activado_en' => now(),
        ])->save();
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
