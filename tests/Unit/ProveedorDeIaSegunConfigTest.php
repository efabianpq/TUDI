<?php

use App\Services\AI\GeminiMealDistributionProvider;
use App\Services\AI\GeminiTranscripcionProvider;
use App\Services\AI\MealDistributionProviderInterface;
use App\Services\AI\OpenAiMealDistributionProvider;
use App\Services\AI\OpenAiTranscripcionProvider;
use App\Services\AI\PremiumGatedMealDistributionProvider;
use App\Services\AI\PremiumGatedTranscripcionProvider;
use App\Services\AI\TranscripcionAudioProviderInterface;
use Tests\TestCase;

/**
 * `AI_PROVEEDOR_DISTRIBUCION` / `AI_PROVEEDOR_TRANSCRIPCION` (CLAUDE.md sección
 * 4.12) dejan elegir el proveedor de IA por `.env`, sin desplegar código, para
 * comparar OpenAI y Gemini durante el piloto.
 *
 * `config()` no recarga bindings ya resueltos: cada test pide una instancia
 * nueva del contenedor de pruebas en vez de reutilizar `app()`, de ahí
 * `$this->app->make(...)` en vez de la función global.
 */
uses(TestCase::class);

function envuelto(object $instancia, string $decorador): object
{
    return (new ReflectionProperty($decorador, 'siguiente'))->getValue($instancia);
}

it('usa OpenAI por defecto para la distribución de comidas', function () {
    $resuelto = $this->app->make(MealDistributionProviderInterface::class);

    expect(envuelto($resuelto, PremiumGatedMealDistributionProvider::class))
        ->toBeInstanceOf(OpenAiMealDistributionProvider::class);
});

it('cambia a Gemini cuando AI_PROVEEDOR_DISTRIBUCION=gemini', function () {
    config(['services.ai_provider.distribucion' => 'gemini']);

    $resuelto = $this->app->make(MealDistributionProviderInterface::class);

    expect(envuelto($resuelto, PremiumGatedMealDistributionProvider::class))
        ->toBeInstanceOf(GeminiMealDistributionProvider::class);
});

it('cae a OpenAI si el valor de AI_PROVEEDOR_DISTRIBUCION no se reconoce', function () {
    // Un typo en el .env de producción no debe tumbar "Generar distribución".
    config(['services.ai_provider.distribucion' => 'clod']);

    $resuelto = $this->app->make(MealDistributionProviderInterface::class);

    expect(envuelto($resuelto, PremiumGatedMealDistributionProvider::class))
        ->toBeInstanceOf(OpenAiMealDistributionProvider::class);
});

it('usa OpenAI por defecto para la transcripción del plan B', function () {
    $resuelto = $this->app->make(TranscripcionAudioProviderInterface::class);

    expect(envuelto($resuelto, PremiumGatedTranscripcionProvider::class))
        ->toBeInstanceOf(OpenAiTranscripcionProvider::class);
});

it('cambia a Gemini cuando AI_PROVEEDOR_TRANSCRIPCION=gemini', function () {
    config(['services.ai_provider.transcripcion' => 'gemini']);

    $resuelto = $this->app->make(TranscripcionAudioProviderInterface::class);

    expect(envuelto($resuelto, PremiumGatedTranscripcionProvider::class))
        ->toBeInstanceOf(GeminiTranscripcionProvider::class);
});

it('los dos proveedores se eligen de forma independiente', function () {
    config([
        'services.ai_provider.distribucion' => 'gemini',
        'services.ai_provider.transcripcion' => 'openai',
    ]);

    $distribucion = envuelto(
        $this->app->make(MealDistributionProviderInterface::class),
        PremiumGatedMealDistributionProvider::class,
    );
    $transcripcion = envuelto(
        $this->app->make(TranscripcionAudioProviderInterface::class),
        PremiumGatedTranscripcionProvider::class,
    );

    expect($distribucion)->toBeInstanceOf(GeminiMealDistributionProvider::class)
        ->and($transcripcion)->toBeInstanceOf(OpenAiTranscripcionProvider::class);
});
