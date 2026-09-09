<?php

use App\Exceptions\TranscripcionNoDisponibleException;
use App\Services\AI\OpenAiTranscripcionProvider;
use App\Services\AI\PremiumGatedTranscripcionProvider;
use App\Services\AI\TranscripcionAudioProviderInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

// Sin base de datos: el proveedor solo habla HTTP. Necesita el contenedor para
// `config()` y el facade `Http`, de ahí el TestCase explícito.
uses(TestCase::class);

beforeEach(function () {
    config([
        'services.openai.key' => 'clave-de-prueba',
        'services.openai.model_transcripcion' => 'gpt-4o-mini-transcribe',
        'services.openai.endpoint' => 'https://api.openai.com/v1',
        'services.openai.timeout' => 20,
        'services.openai.connect_timeout' => 5,
    ]);
});

it('resuelve la interfaz al proveedor de OpenAI, envuelto en el control de plan', function () {
    $resuelto = app(TranscripcionAudioProviderInterface::class);

    // El plan B de servidor se factura, así que su proveedor se entrega dentro
    // del gate de plan (CLAUDE.md sección 5.18). Dictar con el navegador no
    // pasa por aquí y sigue siendo gratis para cualquier plan.
    expect($resuelto)->toBeInstanceOf(PremiumGatedTranscripcionProvider::class);

    $envuelto = (new ReflectionProperty(PremiumGatedTranscripcionProvider::class, 'siguiente'))
        ->getValue($resuelto);

    expect($envuelto)->toBeInstanceOf(OpenAiTranscripcionProvider::class);
});

it('manda el audio al endpoint de transcripción y devuelve el texto', function () {
    Http::fake(['api.openai.com/*' => Http::response(['text' => "  dos huevos y media palta \n"])]);

    $texto = app(OpenAiTranscripcionProvider::class)->transcribir('audio-binario', 'audio/webm');

    expect($texto)->toBe('dos huevos y media palta');

    Http::assertSent(function (Request $peticion) {
        $cuerpo = $peticion->body();

        return $peticion->url() === 'https://api.openai.com/v1/audio/transcriptions'
            && $peticion->hasHeader('authorization', 'Bearer clave-de-prueba')
            && str_contains($cuerpo, 'gpt-4o-mini-transcribe')
            // El idioma se fija: detectarlo mal sobre dos palabras devuelve una
            // transcripción inservible.
            && str_contains($cuerpo, 'name="language"')
            && str_contains($cuerpo, 'audio-binario');
    });
});

it('nombra el archivo con la extensión del formato, que es como la API elige el decodificador', function (string $mimeType, string $esperado) {
    Http::fake(['api.openai.com/*' => Http::response(['text' => 'una manzana'])]);

    app(OpenAiTranscripcionProvider::class)->transcribir('audio-binario', $mimeType);

    Http::assertSent(fn (Request $peticion) => str_contains($peticion->body(), 'filename="dictado.'.$esperado.'"'));
})->with([
    ['audio/webm', 'webm'],
    // Safari de iOS graba en mp4/aac.
    ['audio/mp4', 'mp4'],
    // MediaRecorder puede declarar el códec junto al contenedor.
    ['audio/webm;codecs=opus', 'webm'],
    ['audio/mpeg', 'mp3'],
    // Un tipo desconocido no rompe la llamada: cae al formato más común.
    ['audio/algo-raro', 'webm'],
]);

it('trata una respuesta vacía como "no se escuchó nada"', function () {
    Http::fake(['api.openai.com/*' => Http::response(['text' => '   '])]);

    expect(fn () => app(OpenAiTranscripcionProvider::class)->transcribir('silencio', 'audio/webm'))
        ->toThrow(TranscripcionNoDisponibleException::class, 'No se escuchó nada');
});

it('descarta las muletillas que el modelo inventa cuando el audio está en silencio', function () {
    // Artefacto conocido de los modelos entrenados con subtítulos. Sin este
    // filtro acabaría escrito en el campo de ingredientes del usuario.
    Http::fake(['api.openai.com/*' => Http::response(['text' => 'Gracias por ver el video.'])]);

    expect(fn () => app(OpenAiTranscripcionProvider::class)->transcribir('ruido', 'audio/mp4'))
        ->toThrow(TranscripcionNoDisponibleException::class, 'No se escuchó nada');
});

it('avisa cuando no hay clave configurada, sin llamar a nadie', function () {
    config(['services.openai.key' => null]);
    Http::fake();

    expect(fn () => app(OpenAiTranscripcionProvider::class)->transcribir('audio', 'audio/webm'))
        ->toThrow(TranscripcionNoDisponibleException::class, 'OPENAI_API_KEY');

    Http::assertNothingSent();
});

it('traduce un error del proveedor a excepción de dominio, no a un 500', function () {
    Http::fake(['api.openai.com/*' => Http::response(['error' => ['message' => 'rate limit']], 429)]);

    expect(fn () => app(OpenAiTranscripcionProvider::class)->transcribir('audio', 'audio/webm'))
        ->toThrow(TranscripcionNoDisponibleException::class, 'el proveedor respondió 429');
});
