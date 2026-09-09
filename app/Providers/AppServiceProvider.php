<?php

namespace App\Providers;

use App\Services\AI\MealDistributionProviderInterface;
use App\Services\AI\NutritionAiProviderInterface;
use App\Services\AI\OpenAiMealDistributionProvider;
use App\Services\AI\OpenAiTranscripcionProvider;
use App\Services\AI\PremiumGatedMealDistributionProvider;
use App\Services\AI\PremiumGatedTranscripcionProvider;
use App\Services\AI\RuleBasedNutritionProvider;
use App\Services\AI\TranscripcionAudioProviderInterface;
use Illuminate\Contracts\Auth\Factory as Auth;
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
        // el texto libre de ingredientes de cada comida. Mismo criterio que
        // arriba — cambiar de proveedor es cambiar esta línea. OpenAI (ChatGPT)
        // reemplazó a Gemini como proveedor vigente por precisión de las
        // estimaciones; Gemini y Claude siguen en el repo, sin bindear.
        // El proveedor vigente va envuelto en el control de acceso por plan
        // (CLAUDE.md sección 5.18): la interfaz es el único camino hacia el
        // proveedor, así que envolverla cubre de una vez la distribución de
        // comidas y la estimación de consumo real, sin un solo `if` en los
        // controladores. Para cambiar de proveedor se cambia la clase de dentro,
        // no el envoltorio.
        $this->app->bind(
            MealDistributionProviderInterface::class,
            fn ($app) => new PremiumGatedMealDistributionProvider(
                $app->make(OpenAiMealDistributionProvider::class),
                $app->make(Auth::class),
            ),
        );

        // Dictado por voz cuando la Web Speech API del navegador no funciona
        // (Safari de iOS — CLAUDE.md sección 4.21). Mismo criterio: cambiar de
        // proveedor de transcripción es cambiar la clase de dentro. El
        // envoltorio gatea solo este camino de servidor, que es el que se
        // factura; dictar con el navegador sigue siendo gratis (sección 5.9).
        $this->app->bind(
            TranscripcionAudioProviderInterface::class,
            fn ($app) => new PremiumGatedTranscripcionProvider(
                $app->make(OpenAiTranscripcionProvider::class),
                $app->make(Auth::class),
            ),
        );
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
