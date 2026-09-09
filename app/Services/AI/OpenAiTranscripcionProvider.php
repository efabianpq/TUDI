<?php

namespace App\Services\AI;

use App\Exceptions\TranscripcionNoDisponibleException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Transcripción de audio sobre la API de OpenAI (CLAUDE.md sección 5.9).
 *
 * A diferencia del proveedor anterior (Gemini, que transcribía con el mismo
 * endpoint de texto pasando el audio en base64), OpenAI tiene un endpoint
 * dedicado —`audio/transcriptions`, multipart— y un modelo distinto del de
 * distribución de comidas. De ahí `OPENAI_MODEL_TRANSCRIPCION`.
 *
 * Sigue siendo el **plan B**: el camino normal del dictado es el reconocedor
 * nativo del navegador, gratis y sin salir del dispositivo (regla 13, sección
 * 13). Este endpoint solo responde con `TRANSCRIPCION_FALLBACK_SERVIDOR=true`.
 *
 * Transcribe de forma **literal**: aquí no se interpreta ni se estima nada.
 * Quien decide qué significa ese texto sigue siendo
 * MealDistributionProviderInterface, y quien suma las cifras sigue siendo PHP
 * (regla 7, sección 13).
 */
class OpenAiTranscripcionProvider implements TranscripcionAudioProviderInterface
{
    /**
     * Extensión que se le pone al archivo según su tipo MIME.
     *
     * No es cosmético: la API de OpenAI decide el decodificador por la extensión
     * del nombre de archivo y rechaza con un 400 cualquier cosa que no la traiga
     * (o que la traiga mal). MediaRecorder produce webm/opus en Chrome y
     * Firefox y mp4/aac en Safari de iOS; el resto está por si acaso.
     *
     * @var array<string, string>
     */
    private const EXTENSIONES = [
        'audio/webm' => 'webm',
        'video/webm' => 'webm',
        'audio/ogg' => 'ogg',
        'audio/mp4' => 'mp4',
        'video/mp4' => 'mp4',
        'audio/mpeg' => 'mp3',
        'audio/aac' => 'm4a',
        'audio/x-m4a' => 'm4a',
        'audio/wav' => 'wav',
        'audio/x-wav' => 'wav',
    ];

    /**
     * Muletillas que los modelos de transcripción devuelven cuando el audio está
     * en silencio, en vez de devolver la cadena vacía. Son un artefacto conocido
     * del entrenamiento con subtítulos: si se dejaran pasar, acabarían escritas
     * en el campo de ingredientes del usuario.
     *
     * Se comparan en minúsculas y sin puntuación final.
     *
     * @var array<int, string>
     */
    private const MULETILLAS_DE_SILENCIO = [
        'gracias por ver el video',
        'gracias por ver el vídeo',
        'subtítulos realizados por la comunidad de amara.org',
        'subtitulado por la comunidad de amara.org',
        '¡suscríbete al canal!',
    ];

    /**
     * Transcribir es una tarea literal, no creativa: temperatura al mínimo.
     */
    private const TEMPERATURA = 0.0;

    /**
     * Sesga el vocabulario hacia el dominio: sin esto, "media palta" y "150 g de
     * pechuga" se transcriben peor que con ello. No es una instrucción para el
     * modelo, es una pista de contexto.
     */
    private const PISTA_DE_CONTEXTO = 'Dictado de ingredientes de comida en español: alimentos, cantidades y porciones.';

    public function transcribir(string $audio, string $mimeType): string
    {
        $clave = config('services.openai.key');

        if (blank($clave)) {
            throw TranscripcionNoDisponibleException::sinCredenciales('OPENAI_API_KEY');
        }

        $texto = trim((string) ($this->llamarApi((string) $clave, $audio, $mimeType)['text'] ?? ''));

        if ($texto === '' || $this->esMuletillaDeSilencio($texto)) {
            throw TranscripcionNoDisponibleException::sinVoz();
        }

        return $texto;
    }

    /**
     * @return array<string, mixed>
     */
    private function llamarApi(string $clave, string $audio, string $mimeType): array
    {
        $url = rtrim((string) config('services.openai.endpoint'), '/').'/audio/transcriptions';

        try {
            $respuesta = Http::withHeaders(['authorization' => 'Bearer '.$clave])
                ->connectTimeout((int) config('services.openai.connect_timeout', 5))
                ->timeout((int) config('services.openai.timeout', 20))
                ->attach('file', $audio, 'dictado.'.$this->extensionPara($mimeType), ['Content-Type' => $mimeType])
                ->post($url, [
                    'model' => (string) config('services.openai.model_transcripcion'),
                    // El idioma se fija en vez de dejar que lo detecte: la
                    // aplicación es en español y una detección errónea sobre dos
                    // palabras devuelve una transcripción inservible.
                    'language' => 'es',
                    'prompt' => self::PISTA_DE_CONTEXTO,
                    'response_format' => 'json',
                    'temperature' => (string) self::TEMPERATURA,
                ]);
        } catch (ConnectionException) {
            throw TranscripcionNoDisponibleException::porFalloDelProveedor('sin conexión con el proveedor');
        } catch (Throwable $e) {
            Log::warning('Fallo transcribiendo audio con la API de OpenAI', ['excepcion' => $e->getMessage()]);

            throw TranscripcionNoDisponibleException::porFalloDelProveedor('error inesperado');
        }

        if ($respuesta->failed()) {
            // Solo el código y el mensaje de error: el cuerpo completo puede
            // incluir el eco de la petición (y con ella el audio).
            Log::warning('La API de OpenAI rechazó la transcripción', [
                'status' => $respuesta->status(),
                'mensaje' => $respuesta->json('error.message'),
            ]);

            throw TranscripcionNoDisponibleException::porFalloDelProveedor(
                'el proveedor respondió '.$respuesta->status()
            );
        }

        return (array) $respuesta->json();
    }

    private function extensionPara(string $mimeType): string
    {
        // El tipo puede llegar con parámetros ("audio/webm;codecs=opus").
        $tipo = trim(strtolower(explode(';', $mimeType)[0]));

        return self::EXTENSIONES[$tipo] ?? 'webm';
    }

    private function esMuletillaDeSilencio(string $texto): bool
    {
        $normalizado = rtrim(mb_strtolower($texto), " .!¡\n\r\t");

        foreach (self::MULETILLAS_DE_SILENCIO as $muletilla) {
            if ($normalizado === rtrim($muletilla, ' .!¡')) {
                return true;
            }
        }

        return false;
    }
}
