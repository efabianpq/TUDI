<?php

namespace App\Providers;

use App\Services\AI\CuotaDiariaMealDistributionProvider;
use App\Services\AI\GeminiMealDistributionProvider;
use App\Services\AI\GeminiTranscripcionProvider;
use App\Services\AI\MealDistributionProviderInterface;
use App\Services\AI\NutritionAiProviderInterface;
use App\Services\AI\OpenAiMealDistributionProvider;
use App\Services\AI\OpenAiTranscripcionProvider;
use App\Services\AI\PremiumGatedMealDistributionProvider;
use App\Services\AI\PremiumGatedTranscripcionProvider;
use App\Services\AI\RuleBasedNutritionProvider;
use App\Services\AI\TranscripcionAudioProviderInterface;
use App\Services\CuotaIaService;
use Illuminate\Contracts\Auth\Factory as Auth;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Carbon;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Único punto de acoplamiento entre el dominio y el proveedor de
        // IA/reglas concreto (CLAUDE.md sección 3). Cambiar a un proveedor de
        // IA generativa real en el futuro es cambiar esta línea, no ningún
        // Service de dominio.
        $this->app->bind(NutritionAiProviderInterface::class, RuleBasedNutritionProvider::class);

        // Motor de "Generar distribución" (CLAUDE.md sección 4.12): interpreta
        // el texto libre de ingredientes de cada comida. OpenAI (ChatGPT) es el
        // proveedor por defecto por precisión de las estimaciones; Gemini queda
        // disponible detrás de AI_PROVEEDOR_DISTRIBUCION para comparar los dos
        // durante el piloto sin desplegar código (config('services.ai_provider')
        // arriba). El proveedor vigente va envuelto en el control de acceso por
        // plan (CLAUDE.md sección 5.18): la interfaz es el único camino hacia el
        // proveedor, así que envolverla cubre de una vez la distribución de
        // comidas y la estimación de consumo real, sin un solo `if` en los
        // controladores. Dentro va la cuota diaria de llamadas (sección 5.20),
        // por dentro del control de plan: a quien está en Gratis se le dice qué
        // plan necesita, no cuántas llamadas le quedan de algo que no tiene.
        $this->app->bind(
            MealDistributionProviderInterface::class,
            fn ($app) => new PremiumGatedMealDistributionProvider(
                new CuotaDiariaMealDistributionProvider(
                    $this->motorDeDistribucion($app),
                    $app->make(CuotaIaService::class),
                    $app->make(Auth::class),
                ),
                $app->make(Auth::class),
            ),
        );

        // Dictado por voz cuando la Web Speech API del navegador no funciona
        // (Safari de iOS — CLAUDE.md sección 4.21). Mismo criterio de arriba:
        // el envoltorio gatea solo este camino de servidor, que es el que se
        // factura; dictar con el navegador sigue siendo gratis (sección 5.9).
        $this->app->bind(
            TranscripcionAudioProviderInterface::class,
            fn ($app) => new PremiumGatedTranscripcionProvider(
                $this->motorDeTranscripcion($app),
                $app->make(Auth::class),
            ),
        );
    }

    /**
     * Qué implementación concreta hay detrás de MealDistributionProviderInterface,
     * según AI_PROVEEDOR_DISTRIBUCION. Cualquier valor que no sea "gemini" cae al
     * proveedor por defecto en vez de fallar: un valor mal escrito en `.env` de
     * producción no debe tumbar la generación de planes.
     */
    private function motorDeDistribucion(Container $app): MealDistributionProviderInterface
    {
        return match (config('services.ai_provider.distribucion')) {
            'gemini' => $app->make(GeminiMealDistributionProvider::class),
            default => $app->make(OpenAiMealDistributionProvider::class),
        };
    }

    /**
     * Misma idea que `motorDeDistribucion()`, para TranscripcionAudioProviderInterface.
     */
    private function motorDeTranscripcion(Container $app): TranscripcionAudioProviderInterface
    {
        return match (config('services.ai_provider.transcripcion')) {
            'gemini' => $app->make(GeminiTranscripcionProvider::class),
            default => $app->make(OpenAiTranscripcionProvider::class),
        };
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Las fechas que se muestran al usuario van en español ("lunes 07 de
        // septiembre"). Solo se toca el locale de Carbon, no el de la
        // aplicación: `config('app.locale')` sigue en `en` y con él los
        // mensajes de validación del framework.
        Carbon::setLocale('es');
    }
}
