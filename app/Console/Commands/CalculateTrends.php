<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\TrendAnalyticsService;
use Illuminate\Console\Command;

/**
 * Calcula y persiste el snapshot diario de MetricaTendencia para cada usuario.
 *
 * Reutiliza TrendAnalyticsService::calcularYPersistir() (sección 4.7 de
 * CLAUDE.md): toda la lógica de promedios móviles ya vive ahí, este comando
 * solo recorre los usuarios. No existe una columna "activo" en `users` en el
 * MVP, así que "usuarios activos" es todo usuario registrado; el propio
 * servicio no falla si un usuario no tiene RegistroDiario todavía.
 */
class CalculateTrends extends Command
{
    protected $signature = 'app:calculate-trends';

    protected $description = 'Calcula y persiste las métricas de tendencia (promedios móviles) de todos los usuarios';

    public function handle(TrendAnalyticsService $trendAnalyticsService): int
    {
        $fechaCorte = now()->startOfDay();
        $procesados = 0;

        User::query()->each(function (User $usuario) use ($trendAnalyticsService, $fechaCorte, &$procesados) {
            $trendAnalyticsService->calcularYPersistir($usuario, $fechaCorte);
            $procesados++;
        });

        $this->info(sprintf('Métricas de tendencia calculadas para %d usuario(s).', $procesados));

        return self::SUCCESS;
    }
}
