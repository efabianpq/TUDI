<?php

namespace App\Providers;

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
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
