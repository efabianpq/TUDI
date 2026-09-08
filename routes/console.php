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

/*
| Vaciado de la cola de correos (CLAUDE.md sección 4.26).
|
| Las notificaciones del alta de cuenta van en cola para que el registro no se
| quede esperando al servidor SMTP: esa espera bloquearía un worker de PHP-FPM,
| que es justo el mecanismo del 504 bajo concurrencia (sección 4.22). En
| hosting compartido no hay un demonio de cola, así que el vaciado va montado
| sobre el mismo cron de un minuto.
|
| --stop-when-empty para que el proceso termine en cuanto no queda trabajo, y
| --max-time=50 para que nunca se solape con la ejecución del minuto siguiente
| (withoutOverlapping es el cinturón, esto es los tirantes).
*/
Schedule::command('queue:work --stop-when-empty --max-time=50 --tries=3')
    ->everyMinute()
    ->withoutOverlapping();
