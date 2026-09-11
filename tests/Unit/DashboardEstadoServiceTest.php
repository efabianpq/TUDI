<?php

use App\Models\RecomendacionSistema;
use App\Models\RegistroDiario;
use App\Models\User;
use App\Services\DashboardEstadoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// El servicio decide sub-estados leyendo RegistroDiario, así que necesita la
// base de datos explícitamente (tests/Pest.php solo aplica RefreshDatabase al
// Feature suite).
uses(TestCase::class, RefreshDatabase::class);

/**
 * Mismo perfil que tests/Unit/DailyClosureServiceTest.php:
 * objetivo = 80 * 22 * 1.5 * 0.8 = 2112 kcal.
 */
function usuarioParaEstado(array $sobrescribir = []): User
{
    return User::factory()->create(array_merge([
        'peso_kg' => 80,
        'estatura_m' => 1.75,
        'edad' => 35,
        'sexo' => 'masculino',
        'nivel_actividad' => 1.5,
        'tipo_deficit' => 'porcentaje',
        'valor_deficit' => 0.2,
        'proteina_factor' => 2.0,
        'grasa_factor' => 0.8,
    ], $sobrescribir));
}

/*
|--------------------------------------------------------------------------
| Bloque 1 — Bienvenida
|--------------------------------------------------------------------------
*/

it('primer login: sin ningún RegistroDiario y sin parámetros, pide ir a la Calculadora', function () {
    $usuario = User::factory()->create(['peso_kg' => null, 'nivel_actividad' => null]);

    $estado = app(DashboardEstadoService::class)->calcular($usuario);

    expect($estado['primerLogin'])->toBeTrue()
        ->and($estado['ctaCalculadora'])->toBeTrue()
        ->and($estado['hoy'])->toBeNull()
        ->and($estado['tendencia'])->toBeNull()
        ->and($estado['seguimiento'])->toBeNull();
});

it('primer login: con parámetros ya completos, pide crear el primer plan', function () {
    $usuario = usuarioParaEstado();

    $estado = app(DashboardEstadoService::class)->calcular($usuario);

    expect($estado['primerLogin'])->toBeTrue()
        ->and($estado['ctaCalculadora'])->toBeFalse();
});

it('un solo RegistroDiario, aunque sea de hoy, ya saca al usuario del primer login', function () {
    $usuario = usuarioParaEstado();
    RegistroDiario::factory()->for($usuario, 'usuario')->create(['fecha' => now()->toDateString()]);

    expect(app(DashboardEstadoService::class)->calcular($usuario)['primerLogin'])->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Bloque 2 — Hoy
|--------------------------------------------------------------------------
*/

it('Hoy: sin_plan cuando el usuario tiene historial pero no abrió el día de hoy', function () {
    $usuario = usuarioParaEstado();
    RegistroDiario::factory()->for($usuario, 'usuario')->create(['fecha' => now()->subDay()->toDateString()]);

    $hoy = app(DashboardEstadoService::class)->calcular($usuario)['hoy'];

    expect($hoy['estado'])->toBe('sin_plan')
        ->and($hoy['registroDiario'])->toBeNull();
});

it('Hoy: en_curso expone el resumen en vivo y el saldo del día', function () {
    $usuario = usuarioParaEstado();
    $registroDiario = RegistroDiario::factory()->for($usuario, 'usuario')->create(['fecha' => now()->toDateString()]);

    $hoy = app(DashboardEstadoService::class)->calcular($usuario)['hoy'];

    expect($hoy['estado'])->toBe('en_curso')
        ->and($hoy['registroDiario']->id)->toBe($registroDiario->id)
        ->and($hoy['resumen']['calorias_objetivo'])->toEqualWithDelta(2112.0, 0.01)
        ->and($hoy['saldo']['saldo']['calorias'])->toEqualWithDelta(2112.0, 0.01);
});

it('Hoy: cerrado lee el snapshot congelado, no recalcula desde el perfil actual', function () {
    $usuario = usuarioParaEstado();
    RegistroDiario::factory()->for($usuario, 'usuario')->cerrado()->create([
        'fecha' => now()->toDateString(),
        'calorias_objetivo_dia' => 1800,
        'calorias_consumidas' => 1500,
    ]);

    $hoy = app(DashboardEstadoService::class)->calcular($usuario)['hoy'];

    expect($hoy['estado'])->toBe('cerrado')
        ->and($hoy['resumen']['calorias_objetivo'])->toEqualWithDelta(1800.0, 0.01);
});

it('Hoy: error cuando faltan parámetros nutricionales, con o sin plan de hoy', function () {
    $usuario = usuarioParaEstado(['proteina_factor' => null]);
    RegistroDiario::factory()->for($usuario, 'usuario')->create(['fecha' => now()->subDay()->toDateString()]);

    $hoy = app(DashboardEstadoService::class)->calcular($usuario)['hoy'];

    expect($hoy['estado'])->toBe('error')
        ->and($hoy['error'])->not->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Bloque 3 — Tu tendencia
|--------------------------------------------------------------------------
*/

it('Tu tendencia: insuficiente antes de 7 días, con el diagnóstico del cierre', function () {
    $usuario = usuarioParaEstado();
    RegistroDiario::factory()->for($usuario, 'usuario')->create(['fecha' => now()->toDateString(), 'peso_kg' => 80]);

    $tendencia = app(DashboardEstadoService::class)->calcular($usuario)['tendencia'];

    expect($tendencia['suficiente'])->toBeFalse()
        ->and($tendencia['diagnostico']['listo'])->toBeFalse();
});

it('Tu tendencia: suficiente con 7 días, expone último peso real, racha y serie', function () {
    $usuario = usuarioParaEstado();

    foreach (range(0, 6) as $dias) {
        RegistroDiario::factory()->for($usuario, 'usuario')->cerrado()->create([
            'fecha' => now()->subDays($dias)->toDateString(),
            'peso_kg' => 80.0 - $dias * 0.1,
        ]);
    }

    $tendencia = app(DashboardEstadoService::class)->calcular($usuario)['tendencia'];

    expect($tendencia['suficiente'])->toBeTrue()
        // El pesaje real de hoy (80.0), no el promedio móvil.
        ->and($tendencia['ultimoPeso']['peso_kg'])->toEqualWithDelta(80.0, 0.01)
        ->and($tendencia['racha'])->toBe(7)
        ->and($tendencia['serie'])->not->toBeEmpty()
        ->and($tendencia['serie'][count($tendencia['serie']) - 1]['peso_kg'])->toEqualWithDelta(80.0, 0.01);
});

/*
|--------------------------------------------------------------------------
| Bloque 4 — Tu seguimiento
|--------------------------------------------------------------------------
*/

it('Tu seguimiento: incompleto antes de que pase una semana natural desde el primer día', function () {
    $usuario = usuarioParaEstado();
    RegistroDiario::factory()->for($usuario, 'usuario')->create(['fecha' => now()->subDays(2)->toDateString()]);

    $seguimiento = app(DashboardEstadoService::class)->calcular($usuario)['seguimiento'];

    expect($seguimiento['completo'])->toBeFalse()
        ->and($seguimiento['fechaEstimada'])->not->toBeNull();
});

it('Tu seguimiento: completo con una semana natural cumplida, con semanas y recomendaciones', function () {
    $usuario = usuarioParaEstado();

    foreach (range(0, 6) as $dias) {
        RegistroDiario::factory()->for($usuario, 'usuario')->cerrado()->create([
            'fecha' => now()->subDays($dias)->toDateString(),
            'peso_kg' => 80.0,
        ]);
    }

    $registroDiario = RegistroDiario::where('usuario_id', $usuario->id)->first();
    $recomendacion = RecomendacionSistema::factory()->for($registroDiario, 'registroDiario')->create([
        'tipo' => 'ajuste_calorico',
        'estado' => 'pendiente',
        'confirmada_en' => null,
    ]);

    $seguimiento = app(DashboardEstadoService::class)->calcular($usuario)['seguimiento'];

    expect($seguimiento['completo'])->toBeTrue()
        ->and($seguimiento['semanas'])->not->toBeEmpty()
        ->and($seguimiento['recomendacionesPendientes']->pluck('id'))->toContain($recomendacion->id);
});

it('Tu seguimiento: sin Premium no expone recomendaciones, aunque la semana esté completa', function () {
    $usuario = usuarioParaEstado(['plan' => 'gratis']);

    foreach (range(0, 6) as $dias) {
        RegistroDiario::factory()->for($usuario, 'usuario')->cerrado()->create([
            'fecha' => now()->subDays($dias)->toDateString(),
            'peso_kg' => 80.0,
        ]);
    }

    $seguimiento = app(DashboardEstadoService::class)->calcular($usuario)['seguimiento'];

    expect($seguimiento['completo'])->toBeTrue()
        ->and($seguimiento['recomendacionesPendientes'])->toBeEmpty()
        ->and($seguimiento['historialRecomendaciones'])->toBeEmpty();
});
