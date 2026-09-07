<?php

use App\Models\ComidaReal;
use App\Models\PlanComida;
use App\Models\RecomendacionSistema;
use App\Models\RegistroDiario;
use App\Models\User;
use App\Services\SeguimientoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

// El servicio toca Eloquent, así que este archivo activa TestCase +
// RefreshDatabase explícitamente (tests/Pest.php solo los aplica a Feature).
uses(TestCase::class, RefreshDatabase::class);

/**
 * Fecha de corte fija para que el seguimiento no dependa del día en que se
 * ejecute la suite (mismo criterio que TrendAnalyticsServiceTest).
 */
const CORTE_SEGUIMIENTO = '2026-03-15';

function corteDelSeguimiento(): Carbon
{
    return Carbon::parse(CORTE_SEGUIMIENTO);
}

function usuarioDelSeguimiento(): User
{
    return User::factory()->create(['peso_kg' => 80]);
}

/**
 * Un día cerrado con las cifras que el seguimiento promedia.
 */
function diaDelSeguimiento(User $usuario, int $diasAtras, array $atributos = []): RegistroDiario
{
    return RegistroDiario::factory()->for($usuario, 'usuario')->cerrado()->create(array_merge([
        'fecha' => corteDelSeguimiento()->copy()->subDays($diasAtras)->toDateString(),
        'peso_kg' => 80.0,
        'deficit_diario' => 500.0,
        'calorias_consumidas' => 1800.0,
    ], $atributos));
}

it('resume cada semana con su adherencia, su peso medio y su déficit medio', function () {
    $usuario = usuarioDelSeguimiento();

    // Semana actual (días 0-6): 80.0 kg, déficit 500.
    foreach (range(0, 6) as $dias) {
        diaDelSeguimiento($usuario, $dias);
    }

    // Semana anterior (días 7-13): 81.0 kg, déficit 400.
    foreach (range(7, 13) as $dias) {
        diaDelSeguimiento($usuario, $dias, ['peso_kg' => 81.0, 'deficit_diario' => 400.0]);
    }

    $semanas = app(SeguimientoService::class)->resumenSemanal($usuario, 2, corteDelSeguimiento());

    expect($semanas)->toHaveCount(2);

    // La fila más reciente va primero y cubre exactamente 7 días naturales.
    expect($semanas[0]['fin'])->toBe(CORTE_SEGUIMIENTO)
        ->and($semanas[0]['inicio'])->toBe('2026-03-09')
        ->and($semanas[0]['dias_con_plan'])->toBe(7)
        ->and($semanas[0]['dias_cerrados'])->toBe(7)
        ->and($semanas[0]['adherencia_pct'])->toBe(100.0)
        ->and($semanas[0]['promedio_peso_kg'])->toBe(80.0)
        ->and($semanas[0]['promedio_deficit_kcal'])->toBe(500.0)
        ->and($semanas[0]['promedio_calorias_consumidas'])->toBe(1800.0)
        // Perdió 1 kg de media respecto de la semana anterior.
        ->and($semanas[0]['variacion_peso_kg'])->toBe(-1.0);

    expect($semanas[1]['inicio'])->toBe('2026-03-02')
        ->and($semanas[1]['fin'])->toBe('2026-03-08')
        ->and($semanas[1]['promedio_peso_kg'])->toBe(81.0);
});

it('divide la adherencia entre los 7 días naturales, no entre los días con plan', function () {
    $usuario = usuarioDelSeguimiento();

    // Solo 2 días cerrados de los 7 de la semana.
    diaDelSeguimiento($usuario, 0);
    diaDelSeguimiento($usuario, 1);

    // Y un tercer día abierto, que cuenta como plan pero no como adherencia.
    RegistroDiario::factory()->for($usuario, 'usuario')->create([
        'fecha' => corteDelSeguimiento()->copy()->subDays(2)->toDateString(),
    ]);

    $semanas = app(SeguimientoService::class)->resumenSemanal($usuario, 1, corteDelSeguimiento());

    expect($semanas[0]['dias_con_plan'])->toBe(3)
        ->and($semanas[0]['dias_cerrados'])->toBe(2)
        ->and($semanas[0]['adherencia_pct'])->toBe(28.6);
});

it('cuenta las comidas planificadas y las realmente registradas de la semana', function () {
    $usuario = usuarioDelSeguimiento();
    $dia = diaDelSeguimiento($usuario, 0);

    $desayuno = PlanComida::factory()->for($dia, 'registroDiario')->create(['tipo_comida' => 'desayuno']);
    PlanComida::factory()->for($dia, 'registroDiario')->create(['tipo_comida' => 'almuerzo']);
    PlanComida::factory()->for($dia, 'registroDiario')->create(['tipo_comida' => 'cena']);

    ComidaReal::factory()->for($desayuno, 'planComida')->create();

    $semanas = app(SeguimientoService::class)->resumenSemanal($usuario, 1, corteDelSeguimiento());

    expect($semanas[0]['comidas_planificadas'])->toBe(3)
        ->and($semanas[0]['comidas_registradas'])->toBe(1);
});

it('ignora los días sin peso en vez de contarlos como cero', function () {
    $usuario = usuarioDelSeguimiento();

    diaDelSeguimiento($usuario, 0, ['peso_kg' => 80.0]);
    diaDelSeguimiento($usuario, 1, ['peso_kg' => null]);
    diaDelSeguimiento($usuario, 2, ['peso_kg' => 82.0]);

    $semanas = app(SeguimientoService::class)->resumenSemanal($usuario, 1, corteDelSeguimiento());

    // (80 + 82) / 2 = 81, no (80 + 0 + 82) / 3.
    expect($semanas[0]['promedio_peso_kg'])->toBe(81.0);
});

it('deja la variación en null cuando falta el peso de alguna de las dos semanas', function () {
    $usuario = usuarioDelSeguimiento();

    diaDelSeguimiento($usuario, 0, ['peso_kg' => 80.0]);
    // La semana anterior existe pero nadie se pesó.
    diaDelSeguimiento($usuario, 8, ['peso_kg' => null]);

    $semanas = app(SeguimientoService::class)->resumenSemanal($usuario, 1, corteDelSeguimiento());

    expect($semanas[0]['promedio_peso_kg'])->toBe(80.0)
        ->and($semanas[0]['variacion_peso_kg'])->toBeNull();
});

it('devuelve todas las semanas pedidas aunque el usuario no tenga ningún dato', function () {
    $semanas = app(SeguimientoService::class)->resumenSemanal(usuarioDelSeguimiento(), 4, corteDelSeguimiento());

    expect($semanas)->toHaveCount(4)
        ->and($semanas[0]['dias_con_plan'])->toBe(0)
        ->and($semanas[0]['adherencia_pct'])->toBe(0.0)
        ->and($semanas[0]['promedio_peso_kg'])->toBeNull()
        ->and($semanas[0]['promedio_deficit_kcal'])->toBeNull();
});

it('no mezcla los planes de otro usuario', function () {
    $usuario = usuarioDelSeguimiento();
    $otro = usuarioDelSeguimiento();

    diaDelSeguimiento($usuario, 0, ['peso_kg' => 80.0]);
    diaDelSeguimiento($otro, 0, ['peso_kg' => 60.0]);

    $semanas = app(SeguimientoService::class)->resumenSemanal($usuario, 1, corteDelSeguimiento());

    expect($semanas[0]['dias_con_plan'])->toBe(1)
        ->and($semanas[0]['promedio_peso_kg'])->toBe(80.0);
});

it('lista el historial de recomendaciones de la más reciente a la más antigua', function () {
    $usuario = usuarioDelSeguimiento();
    $otro = usuarioDelSeguimiento();

    $antigua = RecomendacionSistema::factory()
        ->for(diaDelSeguimiento($usuario, 10), 'registroDiario')
        ->create(['justificacion' => 'La antigua', 'estado' => 'confirmada', 'created_at' => now()->subDays(10)]);

    $reciente = RecomendacionSistema::factory()
        ->for(diaDelSeguimiento($usuario, 1), 'registroDiario')
        ->create(['justificacion' => 'La reciente', 'estado' => 'pendiente', 'created_at' => now()]);

    RecomendacionSistema::factory()
        ->for(diaDelSeguimiento($otro, 1), 'registroDiario')
        ->create(['justificacion' => 'La del otro usuario']);

    $historial = app(SeguimientoService::class)->historialRecomendaciones($usuario);

    expect($historial->pluck('id')->all())->toBe([$reciente->id, $antigua->id])
        ->and($historial->pluck('justificacion')->all())->not->toContain('La del otro usuario');
});

it('acota el historial al límite pedido', function () {
    $usuario = usuarioDelSeguimiento();
    $dia = diaDelSeguimiento($usuario, 0);

    RecomendacionSistema::factory()->count(5)->for($dia, 'registroDiario')->create();

    expect(app(SeguimientoService::class)->historialRecomendaciones($usuario, 3))->toHaveCount(3);
});
