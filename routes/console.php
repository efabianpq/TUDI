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
| Vencimiento de las pruebas de Premium (CLAUDE.md sección 5.18).
|
| A las 00:45, DESPUÉS del cierre y de las tendencias: el último día de prueba
| se cierra —y genera sus recomendaciones, que son Premium— antes de que la
| cuenta caiga a Gratis. Al revés, quien terminaba la prueba se quedaba sin el
| cierre completo de un día que sí había pagado con su tiempo.
|
| El comando solo ordena la tabla: quién tiene Premium se responde contra el
| reloj en User::tienePremium(), así que una prueba vencida deja de valer en el
| acto aunque el cron no haya pasado todavía.
*/
Schedule::command('app:expirar-pruebas')->dailyAt('00:45');

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
