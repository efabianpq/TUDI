<?php

use App\Models\ActividadFisica;
use App\Models\ComidaReal;
use App\Models\PlanComida;
use App\Models\RegistroDiario;
use App\Models\User;
use App\Services\MealPlanGeneratorService;
use App\Services\RepartoComidasService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * El reparto entre comidas ya no lo teclea nadie (CLAUDE.md sección 5.14): se
 * deriva de un reparto balanceado y de la actividad física del día. Estos tests
 * fijan las dos cosas que eso tiene que cumplir siempre — que el día siga
 * sumando 1.0, y que el desplazamiento vaya a la comida correcta.
 */
uses(TestCase::class, RefreshDatabase::class);

function usuarioConObjetivo(int $objetivo = 2000): User
{
    return User::factory()->create([
        'peso_kg' => 80,
        'nivel_actividad' => 1.2,
        'tipo_deficit' => 'porcentaje',
        'valor_deficit' => 0.20,
        'proteina_factor' => 1.8,
        'grasa_factor' => 0.8,
        'calorias_objetivo' => $objetivo,
    ]);
}

function diaDe(User $usuario): RegistroDiario
{
    return RegistroDiario::factory()->for($usuario, 'usuario')->create([
        'fecha' => now()->toDateString(),
    ]);
}

/**
 * `created_at` no es asignable en masa, y es lo que dice a qué hora se entrenó.
 */
function registrarActividad(RegistroDiario $dia, int $hora, float $caloriasAjustadas = 300): ActividadFisica
{
    $actividad = $dia->actividadesFisicas()->create([
        'tipo' => 'trote',
        'duracion_min' => 40,
        'calorias_dispositivo' => $caloriasAjustadas / 0.85,
        'factor_correccion' => 0.85,
        'calorias_ajustadas' => $caloriasAjustadas,
    ]);

    $actividad->forceFill(['created_at' => $dia->fecha->copy()->setTime($hora, 0)])->save();

    return $actividad;
}

function cerrarComida(RegistroDiario $dia, string $tipoComida): void
{
    $plan = $dia->planesComida()->create([
        'tipo_comida' => $tipoComida,
        'descripcion' => ucfirst($tipoComida),
        'calorias_estimadas' => 500,
        'proteina_g' => 30,
        'grasa_g' => 15,
        'carbohidratos_g' => 50,
    ]);

    ComidaReal::factory()->for($plan, 'planComida')->create(['calorias_reales' => 500]);
}

it('parte del reparto balanceado declarado en DISTRIBUCION_COMIDAS', function () {
    expect(app(RepartoComidasService::class)->balanceado())
        ->toBe(MealPlanGeneratorService::DISTRIBUCION_COMIDAS)
        ->and(array_sum(MealPlanGeneratorService::DISTRIBUCION_COMIDAS))
        ->toEqualWithDelta(1.0, 0.0001);
});

it('usa el reparto balanceado en un día sin actividad', function () {
    $dia = diaDe(usuarioConObjetivo());

    expect(app(RepartoComidasService::class)->paraElDia($dia))
        ->toBe(MealPlanGeneratorService::DISTRIBUCION_COMIDAS);
});

it('desplaza calorías hacia la comida que viene después del entrenamiento', function () {
    $dia = diaDe(usuarioConObjetivo());
    registrarActividad($dia, 10);

    $reparto = app(RepartoComidasService::class)->paraElDia($dia->refresh());

    expect($reparto['almuerzo'])->toBeGreaterThan(0.40)
        ->and($reparto['desayuno'])->toBeLessThan(0.30)
        ->and($reparto['cena'])->toBeLessThan(0.30)
        ->and(array_sum($reparto))->toEqualWithDelta(1.0, 0.0001);
});

it('entrenar de noche desplaza hacia la cena', function () {
    $dia = diaDe(usuarioConObjetivo());
    registrarActividad($dia, 21);

    $reparto = app(RepartoComidasService::class)->paraElDia($dia->refresh());

    expect($reparto['cena'])->toBeGreaterThan(0.30)
        ->and(array_sum($reparto))->toEqualWithDelta(1.0, 0.0001);
});

it('no desplaza hacia una comida ya cerrada, sino hacia la siguiente abierta', function () {
    $dia = diaDe(usuarioConObjetivo());
    cerrarComida($dia, 'almuerzo');
    registrarActividad($dia, 10);

    $reparto = app(RepartoComidasService::class)->paraElDia($dia->refresh());

    // El almuerzo ya está cerrado y sus macros no se tocan (sección 5.5), así
    // que lo que queda por comer es lo que absorbe el desplazamiento.
    expect($reparto['cena'])->toBeGreaterThan(0.30)
        ->and($reparto['almuerzo'])->toBeLessThan(0.40);
});

it('vuelve al reparto balanceado cuando ya están todas las comidas cerradas', function () {
    $dia = diaDe(usuarioConObjetivo());

    foreach (array_keys(MealPlanGeneratorService::DISTRIBUCION_COMIDAS) as $tipoComida) {
        cerrarComida($dia, $tipoComida);
    }

    registrarActividad($dia, 10);

    expect(app(RepartoComidasService::class)->paraElDia($dia->refresh()))
        ->toBe(MealPlanGeneratorService::DISTRIBUCION_COMIDAS);
});

it('acota el desplazamiento por muchas calorías que se quemen', function () {
    $dia = diaDe(usuarioConObjetivo(2000));
    // 1500 kcal de actividad sobre un objetivo de 2000 serían 75 puntos.
    registrarActividad($dia, 10, 1500);

    $reparto = app(RepartoComidasService::class)->paraElDia($dia->refresh());

    expect($reparto['almuerzo'])
        ->toEqualWithDelta(0.40 + RepartoComidasService::PUNTOS_MAXIMOS_ACTIVIDAD, 0.0001)
        ->and(array_sum($reparto))->toEqualWithDelta(1.0, 0.0001);
});

it('ignora la actividad si el usuario todavía no tiene objetivo calórico', function () {
    $usuario = usuarioConObjetivo();
    $usuario->update(['calorias_objetivo' => null]);

    $dia = diaDe($usuario);
    registrarActividad($dia, 10);

    expect(app(RepartoComidasService::class)->paraElDia($dia->refresh()))
        ->toBe(MealPlanGeneratorService::DISTRIBUCION_COMIDAS);
});

it('explica qué comida recibió el desplazamiento y de cuánto fue', function () {
    $dia = diaDe(usuarioConObjetivo(2000));
    registrarActividad($dia, 10, 200);

    $explicacion = app(RepartoComidasService::class)->explicacion($dia->refresh());

    expect($explicacion['comida'])->toBe('almuerzo')
        ->and($explicacion['calorias_actividad'])->toEqualWithDelta(200.0, 0.01)
        ->and($explicacion['puntos'])->toEqualWithDelta(0.10, 0.0001)
        ->and($explicacion['reparto'])->toBe(app(RepartoComidasService::class)->paraElDia($dia));
});

it('un día sin actividad no tiene nada que explicar', function () {
    $explicacion = app(RepartoComidasService::class)->explicacion(diaDe(usuarioConObjetivo()));

    expect($explicacion['comida'])->toBeNull()
        ->and($explicacion['puntos'])->toBe(0.0)
        ->and($explicacion['reparto'])->toBe(MealPlanGeneratorService::DISTRIBUCION_COMIDAS);
});

it('ninguna comida baja del mínimo por el desplazamiento', function () {
    $dia = diaDe(usuarioConObjetivo(2000));
    registrarActividad($dia, 21, 5000);

    $reparto = app(RepartoComidasService::class)->paraElDia($dia->refresh());

    foreach ($reparto as $proporcion) {
        expect($proporcion)->toBeGreaterThanOrEqual(RepartoComidasService::PROPORCION_MINIMA);
    }

    expect(PlanComida::count())->toBe(0);
});
