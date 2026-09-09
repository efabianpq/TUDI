<?php

namespace App\Services;

use App\Models\User;
use App\Notifications\CuentaActivada;
use App\Notifications\CuentaPendienteDeActivacion;
use App\Notifications\NuevoUsuarioPendiente;
use App\Notifications\NuevoUsuarioRegistrado;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Ciclo de vida de una cuenta (CLAUDE.md sección 5.1).
 *
 * **Una cuenta nueva nace activa y con su prueba de Premium corriendo.** Desde
 * que existe la landing pública (sección 5.19) el registro es el embudo de
 * adquisición: hacer esperar a un visitante a que un administrador le pase un
 * código por WhatsApp convertía la landing en un formulario de lista de espera.
 *
 * La validación manual por código no se ha tirado, ha cambiado de sitio: el
 * estado `pendiente`, la pantalla de activación y el middleware siguen ahí, y
 * ahora los usa el administrador cuando quiere devolver una cuenta a revisión
 * (`regenerarCodigo()`), que es cuando esa validación tiene sentido.
 *
 * Toda la lógica vive aquí y no en los controladores (regla no negociable de la
 * sección 3): quién puede activar, cómo se genera el código y a quién se avisa.
 * El plan con el que nace la cuenta lo pone PlanService, que es su dueño.
 */
class CuentaService
{
    /**
     * Alfabeto del código: sin 0/O ni 1/I/L, que se confunden al dictarlo por
     * teléfono o al leerlo de una captura.
     */
    private const ALFABETO_CODIGO = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    private const LONGITUD_CODIGO = 8;

    public function __construct(
        private readonly PlanService $planes,
    ) {}

    /**
     * Registra una cuenta nueva: activa, con la prueba de Premium ya corriendo,
     * y avisa al usuario y a los administradores.
     *
     * La cuenta y su plan se escriben en la misma transacción a propósito: una
     * cuenta creada sin prueba sería una cuenta que perdió los días de prueba que la
     * landing le prometió, y nadie se daría cuenta hasta que reclamara.
     *
     * @param  array{name: string, email: string, password: string}  $datos  con la contraseña ya hasheada
     */
    public function registrar(array $datos): User
    {
        $usuario = DB::transaction(function () use ($datos): User {
            $usuario = User::create([
                ...$datos,
                'rol' => User::ROL_USUARIO,
                'estado' => User::ESTADO_ACTIVO,
                'codigo_activacion' => null,
                'activado_en' => now(),
            ]);

            return $this->planes->iniciarPrueba($usuario);
        });

        $usuario->notify(new CuentaActivada);

        $administradores = $this->administradores();

        if ($administradores->isNotEmpty()) {
            Notification::send($administradores, new NuevoUsuarioRegistrado($usuario));
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
     * Devuelve la cuenta a validación manual con un código nuevo: el anterior
     * deja de servir, que es lo que hace útil poder regenerarlo si se filtró.
     *
     * Desde que el registro ya no crea cuentas pendientes (arriba), esta es la
     * puerta de entrada al estado `pendiente`, así que es aquí donde se avisa:
     * al usuario, que su cuenta espera un código; a los administradores, cuál
     * es —el correo es una comodidad, el código también está en la consola.
     */
    public function regenerarCodigo(User $usuario): string
    {
        $codigo = $this->generarCodigo();

        $usuario->forceFill([
            'estado' => User::ESTADO_PENDIENTE,
            'codigo_activacion' => $codigo,
            'activado_en' => null,
        ])->save();

        $usuario->notify(new CuentaPendienteDeActivacion);

        $administradores = $this->administradores();

        if ($administradores->isNotEmpty()) {
            Notification::send($administradores, new NuevoUsuarioPendiente($usuario));
        }

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
