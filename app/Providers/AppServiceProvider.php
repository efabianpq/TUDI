<?php

namespace App\Providers;

use App\Services\AI\GeminiMealDistributionProvider;
use App\Services\AI\GeminiTranscripcionProvider;
use App\Services\AI\MealDistributionProviderInterface;
use App\Services\AI\NutritionAiProviderInterface;
use App\Services\AI\RuleBasedNutritionProvider;
use App\Services\AI\TranscripcionAudioProviderInterface;
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
        // arriba — cambiar de proveedor es cambiar esta línea. Gemini 2.5
        // Flash reemplazó a Claude Haiku 4.5 como proveedor vigente.
        $this->app->bind(MealDistributionProviderInterface::class, GeminiMealDistributionProvider::class);

        // Dictado por voz cuando la Web Speech API del navegador no funciona
        // (Safari de iOS — CLAUDE.md sección 4.21). Mismo criterio: cambiar de
        // proveedor de transcripción es cambiar esta línea.
        $this->app->bind(TranscripcionAudioProviderInterface::class, GeminiTranscripcionProvider::class);
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
