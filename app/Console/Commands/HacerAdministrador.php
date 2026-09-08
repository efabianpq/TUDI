<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

/**
 * Promueve una cuenta a administrador (CLAUDE.md sección 4.26).
 *
 * Existe porque el primer administrador no puede crearse desde la consola: la
 * consola exige ya ser administrador. Se corre una vez por SSH tras el
 * despliegue y no se necesita más — a partir de ahí los administradores se
 * nombran entre ellos desde /admin/usuarios.
 *
 * Activa la cuenta de paso: un administrador atrapado en la pantalla del código
 * de activación no podría activarse a sí mismo.
 */
class HacerAdministrador extends Command
{
    protected $signature = 'tudi:hacer-admin {email : Correo de la cuenta que se promueve}';

    protected $description = 'Convierte una cuenta existente en administrador y la deja activa';

    public function handle(): int
    {
        $email = (string) $this->argument('email');
        $usuario = User::where('email', $email)->first();

        if ($usuario === null) {
            $this->error("No existe ninguna cuenta con el correo {$email}.");

            return self::FAILURE;
        }

        $usuario->forceFill([
            'rol' => User::ROL_ADMIN,
            'estado' => User::ESTADO_ACTIVO,
            'codigo_activacion' => null,
            'activado_en' => $usuario->activado_en ?? now(),
        ])->save();

        $this->info("{$usuario->name} ({$email}) ya es administrador y su cuenta está activa.");

        return self::SUCCESS;
    }
}
