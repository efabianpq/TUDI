<?php

namespace App\Http\Controllers;

use App\Exceptions\TranscripcionNoDisponibleException;
use App\Http\Requests\TranscripcionRequest;
use App\Services\AI\TranscripcionAudioProviderInterface;
use Illuminate\Http\JsonResponse;

/**
 * Dictado por voz cuando el navegador no puede resolverlo solo (CLAUDE.md
 * sección 4.21).
 *
 * El camino preferente sigue siendo la Web Speech API del navegador, que no
 * manda el audio a ningún servidor. Este endpoint es el plan B para Safari de
 * iOS, donde esa API pide el micrófono y nunca emite un resultado: el navegador
 * graba con MediaRecorder y aquí se transcribe.
 *
 * Es el único endpoint del proyecto que responde JSON, porque lo consume fetch
 * desde el campo de texto sin recargar la página. Un fallo del proveedor sale
 * como 422 con mensaje legible, nunca como un 500 (regla 6 de la sección 11).
 */
class TranscripcionController extends Controller
{
    public function __construct(
        private readonly TranscripcionAudioProviderInterface $transcriptor,
    ) {}

    public function __invoke(TranscripcionRequest $request): JsonResponse
    {
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
