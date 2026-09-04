<?php

use App\Models\ActividadFisica;
use App\Models\ComidaReal;
use App\Models\IngredienteDisponible;
use App\Models\MetricaTendencia;
use App\Models\PlanComida;
use App\Models\RecomendacionSistema;
use App\Models\RegistroDiario;
use App\Models\User;

test('un registro diario pertenece a un usuario', function () {
    $usuario = User::factory()->create();
    $registro = RegistroDiario::factory()->for($usuario, 'usuario')->create();

    expect($registro->usuario)->toBeInstanceOf(User::class);
    expect($registro->usuario->id)->toBe($usuario->id);
    expect($usuario->registrosDiarios)->toHaveCount(1);
});

test('un ingrediente disponible pertenece a un registro diario', function () {
    $registro = RegistroDiario::factory()->create();
    $ingrediente = IngredienteDisponible::factory()->for($registro, 'registroDiario')->create();

    expect($ingrediente->registroDiario->id)->toBe($registro->id);
    expect($registro->ingredientesDisponibles)->toHaveCount(1);
});

test('un plan de comida pertenece a un registro diario y puede tener una comida real asociada', function () {
    $registro = RegistroDiario::factory()->create();
    $plan = PlanComida::factory()->for($registro, 'registroDiario')->create();
    $comidaReal = ComidaReal::factory()->for($plan, 'planComida')->create();

    expect($plan->registroDiario->id)->toBe($registro->id);
    expect($registro->planesComida)->toHaveCount(1);
    expect($plan->comidaReal)->toBeInstanceOf(ComidaReal::class);
    expect($plan->comidaReal->id)->toBe($comidaReal->id);
    expect($comidaReal->planComida->id)->toBe($plan->id);
});

test('una actividad fisica pertenece a un registro diario', function () {
    $registro = RegistroDiario::factory()->create();
    $actividad = ActividadFisica::factory()->for($registro, 'registroDiario')->create();

    expect($actividad->registroDiario->id)->toBe($registro->id);
    expect($registro->actividadesFisicas)->toHaveCount(1);
});

test('una metrica de tendencia pertenece a un usuario', function () {
    $usuario = User::factory()->create();
    $metrica = MetricaTendencia::factory()->for($usuario, 'usuario')->create();

    expect($metrica->usuario->id)->toBe($usuario->id);
    expect($usuario->metricasTendencia)->toHaveCount(1);
});

test('una recomendacion del sistema pertenece a un registro diario', function () {
    $registro = RegistroDiario::factory()->create();
    $recomendacion = RecomendacionSistema::factory()->for($registro, 'registroDiario')->create();

    expect($recomendacion->registroDiario->id)->toBe($registro->id);
    expect($registro->recomendacionesSistema)->toHaveCount(1);
});

test('eliminar un usuario elimina en cascada sus registros diarios', function () {
    $usuario = User::factory()->create();
    $registro = RegistroDiario::factory()->for($usuario, 'usuario')->create();

    $usuario->delete();

    expect(RegistroDiario::find($registro->id))->toBeNull();
});

test('eliminar un plan de comida elimina en cascada la comida real asociada', function () {
    $plan = PlanComida::factory()->create();
    $comidaReal = ComidaReal::factory()->for($plan, 'planComida')->create();

    $plan->delete();

    expect(ComidaReal::find($comidaReal->id))->toBeNull();
});

test('el usuario extendido almacena el perfil nutricional', function () {
    $usuario = User::factory()->create([
        'peso_kg' => 80.5,
        'proteina_factor' => 2.0,
        'grasa_factor' => 0.8,
    ]);

    expect((float) $usuario->peso_kg)->toBe(80.5);
    expect((float) $usuario->proteina_factor)->toBe(2.0);
    expect((float) $usuario->grasa_factor)->toBe(0.8);
});
