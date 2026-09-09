<?php

namespace App\Console\Commands;

use App\Services\PlanService;
use Illuminate\Console\Command;

/**
 * Baja a `gratis` las pruebas de Premium ya vencidas (CLAUDE.md sección 5.18).
 *
 * Toda la lógica vive en PlanService::expirarVencidos(); este comando solo lo
 * llama desde el scheduler, igual que RunDailyClosure y CalculateTrends con los
 * suyos. Va montado en el mismo cron único de Hostinger, sin ningún mecanismo
 * paralelo (sección 12).
 *
 * **Nadie pierde acceso a la aplicación por esto**: expirar una prueba cambia
 * qué funciones ve el usuario, no si entra ni qué datos conserva.
 *
 * No es el que decide, es el que ordena la tabla: `User::tienePremium()` ya
 * compara contra el reloj, así que una prueba vencida deja de valer en el mismo
 * instante en que caduca, corra o no corra el cron esa noche.
 */
class ExpirarPruebas extends Command
{
    protected $signature = 'app:expirar-pruebas';

    protected $description = 'Pasa al plan Gratis las pruebas de Premium que ya vencieron';

    public function handle(PlanService $planes): int
    {
        $expiradas = $planes->expirarVencidos();

        $this->info(sprintf('Pruebas de Premium vencidas y pasadas a Gratis: %d.', $expiradas));

        return self::SUCCESS;
    }
}
