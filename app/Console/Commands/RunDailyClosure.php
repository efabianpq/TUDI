<?php

namespace App\Console\Commands;

use App\Exceptions\DayAlreadyClosedException;
use App\Exceptions\InvalidNutritionParameterException;
use App\Exceptions\NegativeCarbohydrateException;
use App\Models\RegistroDiario;
use App\Services\DailyClosureService;
use Illuminate\Console\Command;

/**
 * Cierra automáticamente todos los RegistroDiario de ayer que sigan abiertos.
 *
 * Reutiliza DailyClosureService::cerrar() (sección 4.5 de CLAUDE.md): no
 * reimplementa ningún cálculo, solo recorre los registros pendientes. Un
 * registro con perfil incompleto o con un cálculo inválido se salta con un
 * aviso en vez de interrumpir el resto del lote.
 */
class RunDailyClosure extends Command
{
    protected $signature = 'app:run-daily-closure';

    protected $description = 'Cierra automáticamente los registros diarios de ayer que no estén cerrados';

    public function handle(DailyClosureService $dailyClosureService): int
    {
        $ayer = now()->subDay()->startOfDay();

        $registros = RegistroDiario::whereDate('fecha', $ayer->toDateString())
            ->where('cerrado', false)
            ->get();

        $cerrados = 0;

        foreach ($registros as $registroDiario) {
            try {
                $dailyClosureService->cerrar($registroDiario);
                $cerrados++;
            } catch (DayAlreadyClosedException) {
                // Ya se cerró entre la consulta y este punto: no es un error.
                continue;
            } catch (NegativeCarbohydrateException|InvalidNutritionParameterException $e) {
                $this->warn(sprintf(
                    'Registro diario #%d no se pudo cerrar: %s',
                    $registroDiario->id,
                    $e->getMessage(),
                ));
            }
        }

        $this->info(sprintf('%d registro(s) diario(s) de %s cerrados.', $cerrados, $ayer->toDateString()));

        return self::SUCCESS;
    }
}
