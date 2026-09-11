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
    'plan',
    'plan_expira_en',
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

    public const ROL_USUARIO = 'usuario';

    public const ROL_ADMIN = 'admin';

    /** Registrada pero todavía sin canjear su código de activación. */
    public const ESTADO_PENDIENTE = 'pendiente';

    public const ESTADO_ACTIVO = 'activo';

    /** Bloqueada por un administrador: conserva sus datos pero no puede entrar. */
    public const ESTADO_SUSPENDIDO = 'suspendido';

    /*
     * Plan del usuario (CLAUDE.md sección 5.18). Es una dimensión distinta de
     * `estado`: el plan decide QUÉ funciones tiene, no SI entra. Ningún plan
     * deja a nadie fuera de la aplicación.
     */

    /** Sin las funciones que dependen del proveedor de IA. Nunca caduca. */
    public const PLAN_GRATIS = 'gratis';

    /** Premium completo durante los días de prueba; al vencer, cae a `gratis`. */
    public const PLAN_TRIAL = 'trial';

    public const PLAN_PREMIUM = 'premium';

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
            'plan_expira_en' => 'datetime',
            'peso_kg' => 'decimal:2',
            'estatura_m' => 'decimal:2',
            'nivel_actividad' => 'decimal:3',
            'valor_deficit' => 'decimal:2',
            'proteina_factor' => 'decimal:2',
            'grasa_factor' => 'decimal:2',
            'calorias_objetivo' => 'decimal:2',
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
     * ¿Tiene ahora mismo derecho a las funciones Premium?
     *
     * Es la única pregunta que hace el control de acceso, y se responde siempre
     * contra el reloj: un `trial` cuya fecha ya pasó vale como `gratis` aunque
     * el cron nocturno todavía no lo haya degradado en la base de datos
     * (`app:expirar-pruebas`, sección 5.18). Así el vencimiento se nota en el
     * instante en que ocurre y no a la mañana siguiente, y el comando diario es
     * solo el que ordena la tabla, no el que decide.
     */
    public function tienePremium(): bool
    {
        if ($this->plan === self::PLAN_PREMIUM) {
            // Premium sin fecha es premium indefinido (hoy solo lo pone un
            // administrador; con pasarela de pago la pondrá la suscripción).
            return $this->plan_expira_en === null || $this->plan_expira_en->isFuture();
        }

        return $this->plan === self::PLAN_TRIAL
            && $this->plan_expira_en !== null
            && $this->plan_expira_en->isFuture();
    }

    /**
     * Dentro de la prueba gratuita. Un Premium pagante no está "de prueba".
     */
    public function enPrueba(): bool
    {
        return $this->plan === self::PLAN_TRIAL && $this->tienePremium();
    }

    /**
     * Días de prueba que le quedan, redondeando hacia arriba: al usuario se le
     * dice "te quedan 3 días", nunca "te quedan 2,4". Null si no está en prueba.
     */
    public function diasDePruebaRestantes(): ?int
    {
        if (! $this->enPrueba()) {
            return null;
        }

        return max(1, (int) ceil(now()->diffInDays($this->plan_expira_en, absolute: false)));
    }

    /**
     * Tuvo prueba y se le acabó. Distingue "se te terminó" de "nunca la
     * tuviste", que son dos mensajes distintos de cara al usuario.
     */
    public function pruebaTerminada(): bool
    {
        return $this->plan === self::PLAN_TRIAL && ! $this->tienePremium();
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
