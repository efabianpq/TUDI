<?php

namespace App\Console\Commands;

use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Console\Command;

/**
 * Siembra (o retira) las cuentas de demostración (CLAUDE.md sección 5.17).
 *
 * Existe como comando propio y no solo como seeder porque en producción se
 * ejecuta a mano por SSH y conviene que pida confirmación: crea cuentas con una
 * contraseña conocida, y una de ellas es administradora. `db:seed` a secas no
 * distingue eso de sembrar datos de desarrollo.
 */
class SembrarDemo extends Command
{
    protected $signature = 'tudi:demo
                            {--limpiar : Borra las cuentas de demostración y todo su historial}
                            {--force : No preguntar (para scripts de despliegue)}';

    protected $description = 'Crea las cuentas de demostración con su historial, o las retira con --limpiar';

    public function handle(): int
    {
        return $this->option('limpiar') ? $this->limpiar() : $this->sembrar();
    }

    private function sembrar(): int
    {
        $this->warn('Las cuentas de demostración usan una contraseña conocida ('.DemoSeeder::CLAVE.') y una de ellas es ADMINISTRADORA.');
        $this->line('Úsalas solo para enseñar la aplicación, y retíralas con --limpiar cuando termines.');

        if (! $this->option('force') && ! $this->confirm('¿Sembrar los datos de demostración?', false)) {
            $this->line('Cancelado. No se creó nada.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->line('Sembrando…');

        // Vía el contenedor: el seeder recibe DailyClosureService y
        // TrendAnalyticsService por constructor.
        $seeder = app(DemoSeeder::class);
        $seeder->setCommand($this);
        $seeder->run();

        return self::SUCCESS;
    }

    private function limpiar(): int
    {
        $cuentas = User::where('email', 'like', '%'.DemoSeeder::DOMINIO)->get();

        if ($cuentas->isEmpty()) {
            $this->info('No hay cuentas de demostración que borrar.');

            return self::SUCCESS;
        }

        $this->warn('Se van a borrar '.$cuentas->count().' cuenta(s) de demostración y TODO su historial (en cascada).');

        foreach ($cuentas as $cuenta) {
            $this->line('  · '.$cuenta->email);
        }

        if (! $this->option('force') && ! $this->confirm('¿Continuar?', false)) {
            $this->line('Cancelado. No se borró nada.');

            return self::SUCCESS;
        }

        // El filtro por dominio es lo que garantiza que esto nunca toque una
        // cuenta real, aunque se ejecute sobre la base de producción.
        User::where('email', 'like', '%'.DemoSeeder::DOMINIO)->delete();

        $this->info('Cuentas de demostración retiradas.');

        return self::SUCCESS;
    }
}
