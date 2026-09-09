<?php

use App\Exceptions\TranscripcionNoDisponibleException;
use App\Services\AI\GeminiTranscripcionProvider;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

// Sin base de datos: el proveedor solo habla HTTP. Necesita el contenedor para
// `config()` y el facade `Http`, de ahí el TestCase explícito.
uses(TestCase::class);

beforeEach(function () {
    config([
        'services.gemini.key' => 'clave-de-prueba',
        'services.gemini.model' => 'gemini-flash-latest',
        'services.gemini.endpoint' => 'https://generativelanguage.googleapis.com/v1beta/models',
    ]);
});

function respuestaDeTranscripcion(string $texto): array
{
    return [
        'candidates' => [[
            'content' => ['role' => 'model', 'parts' => [['text' => $texto]]],
            'finishReason' => 'STOP',
        ]],
    ];
}

it('sigue construyéndose aunque ya no sea el proveedor vigente', function () {
    // Igual que su gemelo de distribución: sustituido por OpenAI, conservado sin
    // bindear por si hiciera falta volver atrás (CLAUDE.md sección 6).
    expect(app(GeminiTranscripcionProvider::class))
        ->toBeInstanceOf(GeminiTranscripcionProvider::class);
});

it('manda el audio en base64 y devuelve la transcripción', function () {
    Http::fake([
        '*' => Http::response(respuestaDeTranscripcion("  dos huevos y media palta \n")),
    ]);

    $texto = app(GeminiTranscripcionProvider::class)->transcribir('audio-binario', 'audio/webm');

    expect($texto)->toBe('dos huevos y media palta');

    Http::assertSent(function (Request $peticion) {
        $partes = $peticion->data()['contents'][0]['parts'];

        return str_contains($peticion->url(), 'gemini-flash-latest:generateContent')
            && $peticion->hasHeader('x-goog-api-key', 'clave-de-prueba')
            && $partes[1]['inlineData']['mimeType'] === 'audio/webm'
            && $partes[1]['inlineData']['data'] === base64_encode('audio-binario')
            // Transcribir es literal: temperatura al mínimo y sin razonamiento.
            && $peticion->data()['generationConfig']['temperature'] === 0.0
            && $peticion->data()['generationConfig']['thinkingConfig']['thinkingBudget'] === 0;
    });
});

it('trata el marcador de silencio como "no se escuchó nada"', function () {
    Http::fake(['*' => Http::response(respuestaDeTranscripcion('SIN_VOZ'))]);

    expect(fn () => app(GeminiTranscripcionProvider::class)->transcribir('ruido', 'audio/mp4'))
        ->toThrow(TranscripcionNoDisponibleException::class, 'No se escuchó nada');
});

it('trata una respuesta vacía como "no se escuchó nada"', function () {
    Http::fake(['*' => Http::response(respuestaDeTranscripcion('   '))]);

    expect(fn () => app(GeminiTranscripcionProvider::class)->transcribir('silencio', 'audio/webm'))
        ->toThrow(TranscripcionNoDisponibleException::class);
});

it('avisa cuando no hay clave configurada, sin llamar a nadie', function () {
    config(['services.gemini.key' => null]);
    Http::fake();

    expect(fn () => app(GeminiTranscripcionProvider::class)->transcribir('audio', 'audio/webm'))
        ->toThrow(TranscripcionNoDisponibleException::class, 'GEMINI_API_KEY');

    Http::assertNothingSent();
});

it('traduce un error del proveedor a excepción de dominio, no a un 500', function () {
    Http::fake(['*' => Http::response(['error' => ['message' => 'quota']], 429)]);

    expect(fn () => app(GeminiTranscripcionProvider::class)->transcribir('audio', 'audio/webm'))
        ->toThrow(TranscripcionNoDisponibleException::class, 'el proveedor respondió 429');
});

it('traduce un bloqueo del filtro de seguridad a excepción de dominio', function () {
    Http::fake(['*' => Http::response(['promptFeedback' => ['blockReason' => 'SAFETY']])]);

    expect(fn () => app(GeminiTranscripcionProvider::class)->transcribir('audio', 'audio/webm'))
        ->toThrow(TranscripcionNoDisponibleException::class, 'filtro de seguridad');
});
