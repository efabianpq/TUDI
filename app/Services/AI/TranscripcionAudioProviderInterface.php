<?php

namespace App\Services\AI;

use App\Exceptions\TranscripcionNoDisponibleException;

/**
 * Convierte a texto un audio dictado por el usuario (CLAUDE.md sección 4.21).
 *
 * Existe porque la Web Speech API del navegador —el camino barato, que no manda
 * el audio a ningún servidor— no funciona en Safari de iOS: pide el permiso del
 * micrófono, parece grabar y nunca emite un resultado. Este contrato es el plan
 * B: el navegador graba con MediaRecorder y el servidor transcribe.
 *
 * Es una interfaz aparte de MealDistributionProviderInterface a propósito: son
 * problemas distintos (audio → texto, frente a texto → macros) y podrían
 * resolverse con proveedores distintos sin tocar el otro.
 */
interface TranscripcionAudioProviderInterface
{
    /**
     * @param  string  $audio  contenido binario del audio grabado
     * @param  string  $mimeType  tipo MIME real del audio (audio/webm, audio/mp4, …)
     * @return string el texto dictado, ya recortado
     *
     * @throws TranscripcionNoDisponibleException cuando el proveedor no está configurado, falla, o el audio no traía voz
     */
    public function transcribir(string $audio, string $mimeType): string;
}
