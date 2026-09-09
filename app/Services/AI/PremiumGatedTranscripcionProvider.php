<?php

namespace App\Services\AI;

use App\Exceptions\TranscripcionNoDisponibleException;
use App\Models\User;
use Illuminate\Contracts\Auth\Factory as Auth;

/**
 * Control de acceso por plan del plan B del dictado por voz (CLAUDE.md
 * secciones 5.9 y 5.18), con el mismo criterio que
 * PremiumGatedMealDistributionProvider: el gate va pegado a la interfaz del
 * proveedor, no repartido por los controladores.
 *
 * ── Qué se gatea aquí, exactamente ──────────────────────────────────────────
 *
 * **Dictar ingredientes es gratis para todo el mundo.** El camino normal es el
 * reconocedor del propio navegador (Web Speech API): lo resuelve el sistema
 * operativo, el audio no sale del dispositivo y no cuesta ninguna llamada. Ese
 * camino ni pasa por aquí ni pasa por el servidor, así que ningún plan lo toca.
 *
 * Lo que sí se gatea es el plan B —grabar y transcribir en el servidor—, que
 * está apagado por defecto (`TRANSCRIPCION_FALLBACK_SERVIDOR`) precisamente
 * porque cada transcripción se factura y ocupa un worker de PHP-FPM. Si un
 * despliegue lo enciende, este decorador es lo que impide que una cuenta del
 * plan Gratis gaste esa cuota.
 */
class PremiumGatedTranscripcionProvider implements TranscripcionAudioProviderInterface
{
    public function __construct(
        private readonly TranscripcionAudioProviderInterface $siguiente,
        private readonly Auth $auth,
    ) {}

    public function transcribir(string $audio, string $mimeType): string
    {
        $usuario = $this->auth->guard()->user();

        if ($usuario instanceof User && ! $usuario->tienePremium()) {
            throw TranscripcionNoDisponibleException::requierePremium($usuario->pruebaTerminada());
        }

        return $this->siguiente->transcribir($audio, $mimeType);
    }
}
