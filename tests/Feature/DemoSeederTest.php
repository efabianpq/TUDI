<?php

use App\Models\RecomendacionSistema;
use App\Models\RegistroDiario;
use App\Models\User;
use App\Services\TrendAnalyticsService;
use Database\Seeders\DemoSeeder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Datos de demostración (CLAUDE.md sección 5.17).
 *
 * Lo que se prueba no es "que el seeder corra" sino que cada cuenta acabe en el
 * escenario que promete: si "baja-lento" dejara de generar su recomendación, la
 * demo enseñaría una pantalla vacía justo en el momento de contar cómo funciona
 * el motor.
 */
function sembrarDemo(): void
{
    app(DemoSeeder::class)->run();
}

function cuentaDemo(string $slug): User
{
    return User::where('email', $slug.DemoSeeder::DOMINIO)->firstOrFail();
}

/** @return Collection<int, RecomendacionSistema> */
function recomendacionesDe(User $usuario): Collection
{
    return RecomendacionSistema::whereIn(
        'registro_diario_id',
        RegistroDiario::where('usuario_id', $usuario->id)->select('id'),
    )->get();
}

it('crea las seis cuentas de demostración con sus roles y estados', function () {
    sembrarDemo();

    expect(cuentaDemo('admin')->esAdministrador())->toBeTrue()
        ->and(cuentaDemo('admin')->estaActiva())->toBeTrue()
        ->and(cuentaDemo('pendiente')->estado)->toBe(User::ESTADO_PENDIENTE)
        ->and(cuentaDemo('pendiente')->codigo_activacion)->toBe('DEMO2024')
        // Sin Calculadora: es el escenario de "primer paso" del recorrido.
        ->and(cuentaDemo('sin-calculadora')->calorias_objetivo)->toBeNull()
        ->and(cuentaDemo('en-ritmo')->calorias_objetivo)->not->toBeNull();
});

it('deja tres semanas de días cerrados con hoy todavía abierto', function () {
    sembrarDemo();

    $usuario = cuentaDemo('en-ritmo');
    $dias = RegistroDiario::where('usuario_id', $usuario->id)->orderBy('fecha')->get();

    expect($dias)->toHaveCount(21)
        ->and($dias->where('cerrado', true))->toHaveCount(20)
        // Hoy queda abierto a propósito: es el día que se enseña en la demo.
        ->and($dias->last()->cerrado)->toBeFalse()
        ->and($dias->last()->fecha->isToday())->toBeTrue();

    // Y el día abierto está a medias: el desayuno registrado, el resto no.
    $hoy = $dias->last();

    expect($hoy->planesComida()->count())->toBe(3)
        ->and($hoy->planesComida()->has('comidaReal')->count())->toBe(1);
});

it('no se pesa todos los días y aun así calcula la tendencia', function () {
    sembrarDemo();

    $usuario = cuentaDemo('en-ritmo');
    $conPeso = RegistroDiario::where('usuario_id', $usuario->id)->whereNotNull('peso_kg')->count();

    // Día sí, día no (sección 5.7): el promedio móvil ignora los días sin peso.
    expect($conPeso)->toBeLessThan(21)->toBeGreaterThan(5);

    $tendencia = app(TrendAnalyticsService::class)->calcular($usuario, now()->subDay());

    expect($tendencia['porcentaje_perdida_semanal'])->not->toBeNull()
        ->and($tendencia['datos_suficientes'])->toBeTrue();
});

it('pone cada cuenta en el escenario de recomendación que promete', function (
    string $slug,
    string $tendenciaEsperada,
    int $recomendaciones,
    ?string $direccion,
) {
    sembrarDemo();

    $usuario = cuentaDemo($slug);
    $tendencia = app(TrendAnalyticsService::class)->calcular($usuario, now()->subDay());

    expect($tendencia['tendencia'])->toBe($tendenciaEsperada);

    $recs = recomendacionesDe($usuario);

    expect($recs)->toHaveCount($recomendaciones);

    if ($direccion !== null) {
        // Exactamente una pendiente: en uso real cada propuesta se resuelve el
        // mismo día, no se acumulan doce iguales.
        expect($recs->where('estado', 'pendiente'))->toHaveCount(1)
            ->and($recs->firstWhere('estado', 'pendiente')->justificacion)->toContain($direccion);
    }
})->with([
    // Entre 0,5 % y 1 % semanal: nada que ajustar, y el cierre lo dice.
    ['en-ritmo', 'perdida_adecuada', 0, null],
    ['baja-lento', 'perdida_lenta', 3, 'reducir'],
    ['baja-rapido', 'perdida_rapida', 3, 'aumentar'],
]);

it('se puede sembrar dos veces sin duplicar días ni cuentas', function () {
    sembrarDemo();
    sembrarDemo();

    expect(User::where('email', 'like', '%'.DemoSeeder::DOMINIO)->count())->toBe(6)
        ->and(RegistroDiario::where('usuario_id', cuentaDemo('en-ritmo')->id)->count())->toBe(21);
});

it('el comando retira las cuentas de demostración sin tocar las reales', function () {
    sembrarDemo();

    $real = User::factory()->create(['email' => 'persona.real@ejemplo.test']);

    $this->artisan('tudi:demo', ['--limpiar' => true, '--force' => true])->assertSuccessful();

    expect(User::where('email', 'like', '%'.DemoSeeder::DOMINIO)->count())->toBe(0)
        ->and(User::find($real->id))->not->toBeNull();
});
