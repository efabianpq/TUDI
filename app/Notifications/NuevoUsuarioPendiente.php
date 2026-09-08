<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Aviso a los administradores de que hay una cuenta esperando activación
 * (CLAUDE.md sección 4.26).
 *
 * Este sí lleva el código, porque el administrador es quien lo entrega. También
 * está en la consola, así que el correo es una comodidad, no el único camino:
 * si el envío falla, la activación sigue siendo posible desde /admin/usuarios.
 */
class NuevoUsuarioPendiente extends Notification implements ShouldQueue
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
        return (new MailMessage)
            ->subject('Nueva cuenta pendiente de activación en TUDéficit Inteligente')
            ->greeting('Hola')
            ->line("**{$this->nuevo->name}** ({$this->nuevo->email}) acaba de registrarse y espera su código de activación.")
            ->line("Código de activación: **{$this->nuevo->codigo_activacion}**")
            ->line('Entrégaselo por el canal que uses habitualmente. También puedes activarle la cuenta directamente desde la consola.')
            ->action('Abrir la consola de usuarios', route('admin.usuarios.index'))
            ->salutation('TUDéficit Inteligente');
    }
}
