<?php

namespace App\Services;

use App\Models\User;
use App\Notifications\CuentaActivada;
use App\Notifications\CuentaPendienteDeActivacion;
use App\Notifications\NuevoUsuarioPendiente;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Ciclo de vida de una cuenta (CLAUDE.md sección 4.26).
 *
 * Cualquiera puede registrarse, pero la cuenta nace `pendiente` con un código
 * de activación que **no** se le envía al usuario: se lo entrega el
 * administrador por fuera de la aplicación (WhatsApp, en persona, donde sea).
 * Es la validación manual que pide el negocio — el correo que sí recibe el
 * usuario solo le dice que su cuenta está creada y que pida su código.
 *
 * Toda la lógica vive aquí y no en los controladores (regla no negociable de la
 * sección 3): quién puede activar, cómo se genera el código y a quién se avisa.
 */
class CuentaService
{
    /**
     * Alfabeto del código: sin 0/O ni 1/I/L, que se confunden al dictarlo por
     * teléfono o al leerlo de una captura.
     */
    private const ALFABETO_CODIGO = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    private const LONGITUD_CODIGO = 8;

    /**
     * Registra una cuenta nueva, ya pendiente de activación, y avisa al usuario
     * y a los administradores.
     *
     * @param  array{name: string, email: string, password: string}  $datos  con la contraseña ya hasheada
     */
    public function registrar(array $datos): User
    {
        $usuario = DB::transaction(fn (): User => User::create([
            ...$datos,
            'rol' => User::ROL_USUARIO,
            'estado' => User::ESTADO_PENDIENTE,
            'codigo_activacion' => $this->generarCodigo(),
            'activado_en' => null,
        ]));

        // El usuario recibe el aviso, nunca el código.
        $usuario->notify(new CuentaPendienteDeActivacion);

        $administradores = $this->administradores();

        if ($administradores->isNotEmpty()) {
            Notification::send($administradores, new NuevoUsuarioPendiente($usuario));
        }

        return $usuario;
    }

    /**
     * Canjea el código que el usuario introduce. Devuelve false si no coincide,
     * sin dar pistas sobre cuál era el correcto.
     */
    public function activarConCodigo(User $usuario, string $codigo): bool
    {
        $esperado = (string) $usuario->codigo_activacion;

        // Comparación en tiempo constante: el código es una credencial.
        if ($esperado === '' || ! hash_equals($esperado, mb_strtoupper(trim($codigo)))) {
            return false;
        }

        $usuario->activar();
        $usuario->notify(new CuentaActivada);

        return true;
    }

    /**
     * Activación directa desde la consola, sin que el usuario teclee el código.
     */
    public function activar(User $usuario): void
    {
        if ($usuario->estaActiva()) {
            return;
        }

        $usuario->activar();
        $usuario->notify(new CuentaActivada);
    }

    /**
     * Bloquea una cuenta sin borrar sus datos. Reactivarla es volver a
     * `activo`, sin código de por medio: ya estuvo validada una vez.
     */
    public function suspender(User $usuario): void
    {
        $usuario->forceFill(['estado' => User::ESTADO_SUSPENDIDO])->save();
    }

    /**
     * Genera un código nuevo para una cuenta pendiente: el anterior deja de
     * servir, que es lo que hace útil poder regenerarlo si se filtró.
     */
    public function regenerarCodigo(User $usuario): string
    {
        $codigo = $this->generarCodigo();

        $usuario->forceFill([
            'estado' => User::ESTADO_PENDIENTE,
            'codigo_activacion' => $codigo,
            'activado_en' => null,
        ])->save();

        return $codigo;
    }

    /**
     * @return Collection<int, User>
     */
    public function administradores(): Collection
    {
        return User::where('rol', User::ROL_ADMIN)
            ->where('estado', User::ESTADO_ACTIVO)
            ->get();
    }

    private function generarCodigo(): string
    {
        $codigo = '';

        for ($i = 0; $i < self::LONGITUD_CODIGO; $i++) {
            $codigo .= self::ALFABETO_CODIGO[random_int(0, strlen(self::ALFABETO_CODIGO) - 1)];
        }

        // Un choque es improbabilísimo, pero el código identifica a la cuenta
        // durante la activación y duplicarlo confundiría al administrador.
        return User::where('codigo_activacion', $codigo)->exists()
            ? $this->generarCodigo()
            : $codigo;
    }
}
