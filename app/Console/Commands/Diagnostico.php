<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Diagnóstico de un despliegue (CLAUDE.md sección 4.22).
 *
 * Nace de un 504 en producción: un `Gateway Time-out` de nginx solo dice que
 * PHP no contestó a tiempo, nunca por qué. Este comando comprueba, por SSH y en
 * segundos, las causas que de verdad producen ese síntoma en un hosting
 * compartido — empezando por las dos que ocupan un worker de PHP-FPM durante
 * decenas de segundos: una base de datos lenta y una salida HTTPS bloqueada.
 *
 * Es solo lectura: no escribe nada ni cambia ninguna configuración.
 */
class Diagnostico extends Command
{
    protected $signature = 'tudi:diagnostico {--sin-red : No comprobar la salida HTTPS al proveedor de IA}';

    protected $description = 'Comprueba la configuración del despliegue y las causas habituales de un 504';

    /** Fallos que hacen que la aplicación no funcione, no solo que funcione peor. */
    private int $criticos = 0;

    public function handle(): int
    {
        $this->newLine();
        $this->line('  <options=bold>TUDéficit Inteligente · diagnóstico del despliegue</>');

        $this->entorno();
        $this->limitesDePhp();
        $this->baseDeDatos();
        $this->sesionesYCola();
        $this->almacenamiento();

        if (! $this->option('sin-red')) {
            $this->salidaHttps();
        }

        $this->newLine();

        if ($this->criticos > 0) {
            $this->error("  {$this->criticos} comprobación(es) crítica(s) fallida(s). Revisa DEPLOY.md sección 8.");

            return self::FAILURE;
        }

        $this->info('  Sin fallos críticos.');

        return self::SUCCESS;
    }

    private function entorno(): void
    {
        $this->seccion('Entorno');

        $this->dato('Versión de PHP', PHP_VERSION, version_compare(PHP_VERSION, '8.3', '>='));
        $this->dato('APP_ENV', (string) config('app.env'), config('app.env') === 'production');

        // APP_DEBUG en producción enseña trazas con detalles internos a
        // cualquier visitante (DEPLOY.md sección 6).
        $this->dato(
            'APP_DEBUG',
            config('app.debug') ? 'true' : 'false',
            ! (config('app.debug') && config('app.env') === 'production'),
            critico: config('app.debug') && config('app.env') === 'production',
        );

        $timezone = (string) config('app.timezone');

        // De la timezone depende dónde cae la medianoche que decide "hoy" en
        // todo el dominio (sección 7).
        $this->dato('APP_TIMEZONE', $timezone.'  ·  ahora son las '.now()->format('H:i'), $timezone !== 'UTC');
        $this->dato('APP_URL', (string) config('app.url'), filled(config('app.url')));
    }

    private function limitesDePhp(): void
    {
        $this->seccion('Límites de PHP');

        $ejecucion = (int) ini_get('max_execution_time');

        // 0 = sin límite (habitual en CLI). En FPM, un max_execution_time por
        // debajo del timeout del proveedor de IA corta la petición a medias.
        $this->dato(
            'max_execution_time',
            $ejecucion === 0 ? 'sin límite' : $ejecucion.' s',
            $ejecucion === 0 || $ejecucion >= (int) config('services.openai.presupuesto_total') + 10,
        );

        $this->dato('memory_limit', (string) ini_get('memory_limit'));

        // La subida de la foto de evidencia la gobierna la validación de
        // aplicación (4 MB), pero PHP la puede rechazar antes (DEPLOY.md 5).
        $subida = $this->aBytes((string) ini_get('upload_max_filesize'));
        $post = $this->aBytes((string) ini_get('post_max_size'));

        $this->dato('upload_max_filesize', (string) ini_get('upload_max_filesize'), $subida >= 4 * 1024 * 1024);
        $this->dato('post_max_size', (string) ini_get('post_max_size'), $post >= $subida);
    }

    private function baseDeDatos(): void
    {
        $this->seccion('Base de datos');

        try {
            $inicio = microtime(true);
            DB::connection()->getPdo();
            DB::select('select 1');
            $ms = round((microtime(true) - $inicio) * 1000);

            // Una base de datos lenta ocupa el worker de PHP-FPM durante toda la
            // consulta: es una de las dos causas típicas del 504.
            $this->dato('Conexión', "{$ms} ms", $ms < 500, critico: false);
            $this->dato('Motor', DB::connection()->getDriverName().' · '.DB::connection()->getDatabaseName());
        } catch (Throwable $e) {
            $this->dato('Conexión', $e->getMessage(), false, critico: true);

            return;
        }

        foreach (['users', 'sessions', 'cache', 'jobs', 'registros_diarios', 'parametros_maestros'] as $tabla) {
            $existe = Schema::hasTable($tabla);
            $this->dato("Tabla {$tabla}", $existe ? 'existe' : 'NO EXISTE', $existe, critico: ! $existe);
        }

        $pendientes = $this->migracionesPendientes();

        $this->dato(
            'Migraciones pendientes',
            $pendientes === 0 ? 'ninguna' : (string) $pendientes,
            $pendientes === 0,
            critico: $pendientes > 0,
        );

        $administradores = DB::table('users')->where('rol', 'admin')->count();

        // Sin ningún administrador la consola es inalcanzable: hay que correr
        // `tudi:hacer-admin` (sección 4.26).
        $this->dato('Administradores', (string) $administradores, $administradores > 0);
    }

    private function sesionesYCola(): void
    {
        $this->seccion('Sesiones y cola');

        $driver = (string) config('session.driver');

        /*
         * El driver `file` serializa las peticiones de una misma sesión: cada
         * una bloquea el archivo hasta terminar, así que dos pestañas del mismo
         * usuario se esperan la una a la otra. Con llamadas a la IA de segundos
         * eso se convierte en 504 con muy poca concurrencia.
         */
        $this->dato('SESSION_DRIVER', $driver, $driver !== 'file');

        if ($driver === 'database' && Schema::hasTable('sessions')) {
            $total = DB::table('sessions')->count();
            $caducadas = DB::table('sessions')
                ->where('last_activity', '<', now()->subMinutes((int) config('session.lifetime'))->getTimestamp())
                ->count();

            $this->dato('Sesiones', "{$total} en total, {$caducadas} caducadas", $total < 50000);
        }

        $this->dato('QUEUE_CONNECTION', (string) config('queue.default'));

        if (config('queue.default') === 'database' && Schema::hasTable('jobs')) {
            $enCola = DB::table('jobs')->count();

            // Trabajos que se acumulan = el cron del scheduler no está corriendo
            // y los correos del alta no salen (sección 4.26).
            $this->dato('Trabajos en cola', (string) $enCola, $enCola < 100);
        }

        if (Schema::hasTable('failed_jobs')) {
            $fallidos = DB::table('failed_jobs')->count();
            $this->dato('Trabajos fallidos', (string) $fallidos, $fallidos === 0);
        }
    }

    private function almacenamiento(): void
    {
        $this->seccion('Almacenamiento');

        foreach (['storage/framework', 'storage/logs', 'bootstrap/cache'] as $ruta) {
            $completa = base_path($ruta);
            $escribible = is_dir($completa) && is_writable($completa);

            $this->dato($ruta, $escribible ? 'escribible' : 'NO ESCRIBIBLE', $escribible, critico: ! $escribible);
        }

        // `storage:link` es lo que hace visibles las fotos de evidencia.
        $enlace = is_link(public_path('storage')) || is_dir(public_path('storage'));
        $this->dato('public/storage', $enlace ? 'enlazado' : 'sin enlazar (php artisan storage:link)', $enlace);

        // Sin el manifest de Vite compilado, TODA vista falla con 500
        // (DEPLOY.md sección 1): los assets se compilan en local y se commitean.
        $manifest = file_exists(public_path('build/manifest.json'));
        $this->dato('public/build/manifest.json', $manifest ? 'presente' : 'FALTA', $manifest, critico: ! $manifest);

        try {
            Storage::disk('public')->exists('.');
            $this->dato('Disco "public"', 'accesible', true);
        } catch (Throwable $e) {
            $this->dato('Disco "public"', $e->getMessage(), false, critico: true);
        }
    }

    private function salidaHttps(): void
    {
        $this->seccion('Proveedor de IA');

        $clave = config('services.openai.key');

        // Sin clave la aplicación funciona igual: solo se desactiva "Generar
        // distribución" (sección 10). No es crítico.
        $this->dato('OPENAI_API_KEY', filled($clave) ? 'configurada' : 'sin configurar', filled($clave));
        $this->dato('OPENAI_MODEL', (string) config('services.openai.model'));
        $this->dato('Timeout', config('services.openai.timeout').' s', (int) config('services.openai.timeout') <= 25);

        // Techo del conjunto de intentos, incluida la corrección de macros. Si
        // se sube por encima del timeout del gateway, el 504 lo da el gateway.
        $this->dato(
            'Presupuesto total',
            config('services.openai.presupuesto_total').' s',
            (int) config('services.openai.presupuesto_total') <= 25,
        );

        $endpoint = (string) config('services.openai.endpoint');
        $host = parse_url($endpoint, PHP_URL_HOST) ?: 'api.openai.com';

        try {
            $inicio = microtime(true);
            // Da igual qué responda (200 con clave válida, 401 sin ella): lo que
            // se comprueba es que la petición SALE del servidor. Si el hosting
            // bloquea la salida, aquí se ve en vez de descubrirlo cuando un
            // usuario pulsa el botón y consume el timeout entero.
            $respuesta = Http::withHeaders(['authorization' => 'Bearer '.((string) $clave)])
                ->connectTimeout(5)
                ->timeout(10)
                ->get(rtrim($endpoint, '/').'/models');

            $ms = round((microtime(true) - $inicio) * 1000);

            $this->dato("Salida HTTPS a {$host}", "responde en {$ms} ms (HTTP {$respuesta->status()})", true);
        } catch (Throwable $e) {
            $this->dato(
                "Salida HTTPS a {$host}",
                'SIN SALIDA — '.$e->getMessage(),
                false,
                critico: false,
            );
            $this->line('      <fg=yellow>El hosting parece bloquear las conexiones salientes. "Generar');
            $this->line('      distribución" consumirá el timeout entero en cada intento y ocupará');
            $this->line('      un worker de PHP-FPM mientras tanto (DEPLOY.md sección 8).</>');
        }
    }

    private function seccion(string $titulo): void
    {
        $this->newLine();
        $this->line("  <options=bold;fg=cyan>{$titulo}</>");
    }

    private function dato(string $etiqueta, string $valor, ?bool $correcto = null, bool $critico = false): void
    {
        $marca = match ($correcto) {
            true => '<fg=green>✓</>',
            false => $critico ? '<fg=red>✗</>' : '<fg=yellow>!</>',
            null => '<fg=gray>·</>',
        };

        if ($critico && $correcto === false) {
            $this->criticos++;
        }

        $this->line(sprintf('   %s %-28s %s', $marca, $etiqueta, $valor));
    }

    private function migracionesPendientes(): int
    {
        if (! Schema::hasTable('migrations')) {
            return count($this->archivosDeMigracion());
        }

        $aplicadas = DB::table('migrations')->pluck('migration')->all();

        return count(array_diff($this->archivosDeMigracion(), $aplicadas));
    }

    /**
     * @return array<int, string>
     */
    private function archivosDeMigracion(): array
    {
        return array_map(
            fn (string $ruta): string => basename($ruta, '.php'),
            glob(database_path('migrations/*.php')) ?: [],
        );
    }

    private function aBytes(string $valor): int
    {
        $valor = trim($valor);
        $numero = (int) $valor;

        return match (mb_strtoupper(mb_substr($valor, -1))) {
            'G' => $numero * 1024 * 1024 * 1024,
            'M' => $numero * 1024 * 1024,
            'K' => $numero * 1024,
            default => $numero,
        };
    }
}
