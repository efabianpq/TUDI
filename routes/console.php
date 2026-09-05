<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Tareas programadas (CLAUDE.md sección 10)
|--------------------------------------------------------------------------
|
| En producción (hosting compartido de Hostinger) hay un único cron job:
|   * * * * * php /home/USER/domains/DOMINIO/public_html/artisan schedule:run
| No se puede asumir que Hostinger permite varios cron jobs de Laravel
| independientes, así que TODA la automatización diaria pasa por el
| Scheduler de Laravel (este archivo), nunca por un cron propio.
|
| Horario: 00:15 el cierre diario (opera sobre los registros de "ayer", ya
| completos a esa hora) y 00:30 el cálculo de tendencias (después, para que
| el cierre ya haya persistido deficit_diario y demás columnas que
| TrendAnalyticsService promedia).
*/
Schedule::command('app:run-daily-closure')->dailyAt('00:15');
Schedule::command('app:calculate-trends')->dailyAt('00:30');
