<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Aviso a los administradores de que alguien acaba de darse de alta desde la
 * landing pública (CLAUDE.md secciones 5.1 y 5.19).
 *
 * No lleva código de activación porque ya no hay ninguno que entregar: la
 * cuenta entra directa, con su prueba de Premium corriendo. Es información, no
 * una tarea pendiente — para eso está NuevoUsuarioPendiente, que sí se envía
 * cuando un administrador devuelve una cuenta a validación manual.
 */
class NuevoUsuarioRegistrado extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly User $nuevo) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $dias = $this->nuevo->diasDePruebaRestantes() ?? (int) config('planes.prueba_dias', 3);

        return (new MailMessage)
            ->subject('Nueva cuenta en TUDéficit Inteligente')
            ->greeting('Hola')
            ->line("**{$this->nuevo->name}** ({$this->nuevo->email}) acaba de crear su cuenta.")
            ->line("Entró con su prueba de Premium de {$dias} días ya corriendo; al vencer pasa sola al plan Gratis.")
            ->action('Abrir la consola de usuarios', route('admin.usuarios.index'))
            ->salutation('TUDéficit Inteligente');
    }
}
