<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Correo al usuario recién registrado (CLAUDE.md sección 4.26).
 *
 * **No lleva el código de activación, y no es un descuido**: el código lo
 * entrega el administrador por fuera de la aplicación, y ese paso manual es
 * justamente la validación que pide el negocio. Si el código viajara en este
 * correo, cualquiera con un email válido se activaría solo.
 *
 * Va en cola (`ShouldQueue`) para que el registro no se quede esperando al
 * servidor SMTP: en hosting compartido esa espera bloquea un worker de PHP-FPM
 * (ver sección 4.22). El cron del scheduler vacía la cola cada minuto.
 */
class CuentaPendienteDeActivacion extends Notification implements ShouldQueue
{
    use Queueable;

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
            ->subject('Tu cuenta de TUDéficit Inteligente está creada')
            ->greeting("¡Hola, {$notifiable->name}!")
            ->line('Tu cuenta ya está creada, pero todavía falta un paso: necesita un código de activación.')
            ->line('Ese código te lo entrega el administrador de TUDéficit Inteligente. Escríbele para pedírselo y, cuando lo tengas, introdúcelo al entrar.')
            ->action('Introducir mi código', route('activacion.create'))
            ->line('Si no has sido tú quien creó esta cuenta, puedes ignorar este correo.')
            ->salutation('Un saludo, el equipo de TUDéficit Inteligente');
    }
}
