<?php

namespace App\Providers;

use App\Services\AI\ClaudeMealDistributionProvider;
use App\Services\AI\MealDistributionProviderInterface;
use App\Services\AI\NutritionAiProviderInterface;
use App\Services\AI\RuleBasedNutritionProvider;
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
        // arriba — cambiar de proveedor es cambiar esta línea.
        $this->app->bind(MealDistributionProviderInterface::class, ClaudeMealDistributionProvider::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
