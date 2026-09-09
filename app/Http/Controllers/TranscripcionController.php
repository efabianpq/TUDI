<?php

namespace App\Http\Controllers;

use App\Exceptions\TranscripcionNoDisponibleException;
use App\Http\Requests\TranscripcionRequest;
use App\Services\AI\TranscripcionAudioProviderInterface;
use Illuminate\Http\JsonResponse;

/**
 * Plan B del dictado por voz (CLAUDE.md sección 5.9), **apagado por defecto**.
 *
 * El camino normal es el reconocimiento nativo del navegador: lo resuelve el
 * sistema operativo, el audio no sale del dispositivo y no cuesta nada. Este
 * endpoint graba y transcribe con el proveedor, así que cada llamada se
 * factura y ocupa un worker de PHP-FPM mientras dura (sección 5.13). Por eso
 * solo responde si el despliegue lo encendió con
 * `TRANSCRIPCION_FALLBACK_SERVIDOR=true`; apagado, ni el HTML anuncia la ruta
 * ni el controlador acepta audio.
 *
 * Es el único endpoint del proyecto que responde JSON, porque lo consume fetch
 * desde el campo de texto sin recargar la página. Un fallo del proveedor sale
 * como 422 con mensaje legible, nunca como un 500 (regla 6 de la sección 13).
 */
class TranscripcionController extends Controller
{
    public function __construct(
        private readonly TranscripcionAudioProviderInterface $transcriptor,
    ) {}

    public function __invoke(TranscripcionRequest $request): JsonResponse
    {
        if (! config('services.transcripcion.fallback_servidor')) {
            return response()->json([
                'error' => __('El dictado por voz lo resuelve tu navegador. Si el micrófono no funciona aquí, escríbelo a mano.'),
            ], 422);
        }

        $audio = $request->file('audio');

        try {
            $texto = $this->transcriptor->transcribir(
                (string) $audio->get(),
                (string) $audio->getMimeType(),
            );
        } catch (TranscripcionNoDisponibleException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json(['texto' => $texto]);
    }
}
