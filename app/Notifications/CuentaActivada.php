<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Confirmación de que la cuenta quedó activa (CLAUDE.md sección 4.26), tanto si
 * la activó el usuario con su código como si lo hizo un administrador desde la
 * consola.
 */
class CuentaActivada extends Notification implements ShouldQueue
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
        $correo = (new MailMessage)
            ->subject('Tu cuenta de TUDéficit Inteligente ya está activa')
            ->greeting("¡Hola, {$notifiable->name}!")
            ->line('Tu cuenta ya está activa. El primer paso es la Calculadora Déficit: con tu peso, tu estatura y cuán activo eres calculamos las calorías que debes consumir cada día.');

        // Quien acaba de registrarse estrena su prueba (sección 5.18); quien
        // canjeó un código de reactivación puede no tenerla, y entonces esta
        // línea sobra.
        if ($dias = $notifiable->diasDePruebaRestantes()) {
            $correo->line("Tienes {$dias} días de Premium por delante, sin tarjeta: la distribución de tus comidas con IA y la estimación de lo que comes están incluidas. Al terminar pasas solo al plan Gratis y conservas todo tu historial.");
        }

        return $correo
            ->action('Calcular mi objetivo', route('calculadora.edit'))
            ->salutation('Un saludo, el equipo de TUDéficit Inteligente');
    }
}
