<?php

use App\Models\ActividadFisica;
use App\Models\ComidaReal;
use App\Models\PlanComida;
use App\Models\RegistroDiario;
use App\Models\User;

/**
 * Same profile as tests/Unit/DailyClosureServiceTest.php:
 * objetivo = 80 * 22 * 1.5 * 0.8 = 2112 kcal, proteína objetivo = 160 g.
 */
function usuarioParaCierre(array $sobrescribir = []): User
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

/**
 * Today's record with breakfast already logged (600 kcal / 45 g de proteína),
 * lunch still pending, and 340 kcal of adjusted activity.
 */
function diaDeHoyConDesayunoRegistrado(User $usuario): RegistroDiario
{
    $registroDiario = RegistroDiario::factory()->for($usuario, 'usuario')->create([
        'fecha' => now()->toDateString(),
    ]);

    $desayuno = PlanComida::factory()->for($registroDiario, 'registroDiario')->create([
        'tipo_comida' => 'desayuno',
        'calorias_estimadas' => 528,
    ]);

    ComidaReal::factory()->for($desayuno, 'planComida')->create([
        'calorias_reales' => 600,
        'proteina_g' => 45,
    ]);

    PlanComida::factory()->for($registroDiario, 'registroDiario')->create([
        'tipo_comida' => 'almuerzo',
        'calorias_estimadas' => 845,
    ]);

    ActividadFisica::factory()->for($registroDiario, 'registroDiario')->create([
        'tipo' => 'caminata',
        'calorias_dispositivo' => 400,
        'factor_correccion' => 0.85,
        'calorias_ajustadas' => 340,
    ]);

    return $registroDiario;
}

test('guests cannot see or trigger the daily closure', function () {
    $registroDiario = RegistroDiario::factory()->create();

    $this->get(route('cierre.index'))->assertRedirect('/login');
    $this->post(route('cierre.cerrar'))->assertRedirect('/login');
    $this->post(route('cierre.reabrir', $registroDiario))->assertRedirect('/login');
});

test('closing the day freezes it and the summary shows the five closure points', function () {
    $usuario = usuarioParaCierre();
    $registroDiario = diaDeHoyConDesayunoRegistrado($usuario);

    $response = $this->actingAs($usuario)->post(route('cierre.cerrar'));

    $response->assertRedirect(route('cierre.index'))->assertSessionHas('status', 'dia-cerrado');

    $registroDiario->refresh();

    // objetivo 2112 · consumidas 600 · actividad 340 · déficit 2112-600+340 = 1852
    // proteína 45 g de 160 g -> 28.1 %
    expect($registroDiario->cerrado)->toBeTrue()
        ->and($registroDiario->cerrado_en)->not->toBeNull()
        ->and((float) $registroDiario->calorias_objetivo_dia)->toBe(2112.0)
        ->and((float) $registroDiario->calorias_consumidas)->toBe(600.0)
        ->and((float) $registroDiario->calorias_actividad_ajustada)->toBe(340.0)
        ->and((float) $registroDiario->deficit_diario)->toBe(1852.0)
        ->and((float) $registroDiario->proteina_objetivo_g)->toBe(160.0)
        ->and((float) $registroDiario->proteina_consumida_g)->toBe(45.0);

    $this->actingAs($usuario)->get(route('cierre.index'))
        ->assertOk()
        ->assertSee('2,112')       // calorías objetivo
        ->assertSee('600')         // calorías consumidas
        ->assertSee('340')         // gasto por actividad ajustado
        ->assertSee('1,852')       // déficit estimado
        ->assertSee('28.1%')       // cumplimiento de proteína
        ->assertSee('Recomendaciones')
        ->assertSee('No hay recomendaciones para este día.');
});

test('closing an already closed day is rejected without touching its figures', function () {
    $usuario = usuarioParaCierre();
    $registroDiario = diaDeHoyConDesayunoRegistrado($usuario);

    $this->actingAs($usuario)->post(route('cierre.cerrar'));

    $cerradoEn = $registroDiario->fresh()->cerrado_en;

    $this->actingAs($usuario)->post(route('cierre.cerrar'))
        ->assertRedirect(route('cierre.index'))
        ->assertSessionHas('error');

    expect($registroDiario->fresh()->cerrado_en->equalTo($cerradoEn))->toBeTrue()
        ->and((float) $registroDiario->fresh()->deficit_diario)->toBe(1852.0);
});

test('a closed day cannot receive a new comida real', function () {
    $usuario = usuarioParaCierre();
    $registroDiario = diaDeHoyConDesayunoRegistrado($usuario);
    $almuerzo = $registroDiario->planesComida()->where('tipo_comida', 'almuerzo')->first();

    $this->actingAs($usuario)->post(route('cierre.cerrar'));

    $this->actingAs($usuario)->post(route('comida-real.store', $almuerzo), [
        'calorias_reales' => 800,
        'proteina_g' => 60,
        'grasa_g' => 25,
        'carbohidratos_g' => 70,
    ])->assertRedirect(route('cierre.index'))->assertSessionHas('error');

    expect($almuerzo->fresh()->comidaReal)->toBeNull()
        ->and((float) $registroDiario->fresh()->calorias_consumidas)->toBe(600.0);

    // The form is closed too, not just the write endpoint.
    $this->actingAs($usuario)->get(route('comida-real.create', $almuerzo))
        ->assertRedirect(route('cierre.index'))
        ->assertSessionHas('error');
});

test('a closed day cannot receive a new actividad fisica either', function () {
    $usuario = usuarioParaCierre();
    $registroDiario = diaDeHoyConDesayunoRegistrado($usuario);

    $this->actingAs($usuario)->post(route('cierre.cerrar'));

    $this->actingAs($usuario)->post(route('actividades.store'), [
        'tipo_actividad' => 'trote',
        'duracion_min' => 30,
        'calorias_dispositivo' => 300,
        'fuente' => 'dispositivo',
    ])->assertRedirect(route('cierre.index'))->assertSessionHas('error');

    expect($registroDiario->fresh()->actividadesFisicas()->count())->toBe(1)
        ->and((float) $registroDiario->fresh()->calorias_actividad_ajustada)->toBe(340.0);
});

test('after an explicit reopen the day accepts a comida real again', function () {
    $usuario = usuarioParaCierre();
    $registroDiario = diaDeHoyConDesayunoRegistrado($usuario);
    $almuerzo = $registroDiario->planesComida()->where('tipo_comida', 'almuerzo')->first();

    $this->actingAs($usuario)->post(route('cierre.cerrar'));

    $this->actingAs($usuario)->post(route('cierre.reabrir', $registroDiario))
        ->assertRedirect(route('cierre.index'))
        ->assertSessionHas('status', 'dia-reabierto');

    expect($registroDiario->fresh()->cerrado)->toBeFalse();

    $this->actingAs($usuario)->post(route('comida-real.store', $almuerzo), [
        'calorias_reales' => 800,
        'proteina_g' => 60,
        'grasa_g' => 25,
        'carbohidratos_g' => 70,
    ])->assertRedirect(route('planes.index'));

    expect($almuerzo->fresh()->comidaReal)->not->toBeNull()
        ->and((float) $registroDiario->fresh()->calorias_consumidas)->toBe(1400.0);
});

test('a user cannot reopen another users day', function () {
    $usuario = usuarioParaCierre();
    $otroUsuario = usuarioParaCierre();
    $registroDiario = diaDeHoyConDesayunoRegistrado($otroUsuario);

    $this->actingAs($otroUsuario)->post(route('cierre.cerrar'));

    $this->actingAs($usuario)->post(route('cierre.reabrir', $registroDiario))->assertForbidden();

    expect($registroDiario->fresh()->cerrado)->toBeTrue();
});

test('closing the day requires the nutritional profile to be complete', function () {
    $usuario = usuarioParaCierre(['proteina_factor' => null]);
    $registroDiario = diaDeHoyConDesayunoRegistrado($usuario);

    $this->actingAs($usuario)->post(route('cierre.cerrar'))
        ->assertRedirect(route('profile.parametros.edit'))
        ->assertSessionHas('error');

    expect($registroDiario->fresh()->cerrado)->toBeFalse();

    $this->actingAs($usuario)->get(route('cierre.index'))
        ->assertOk()
        ->assertSee('Completa tus parámetros nutricionales para poder cerrar el día.');
});

test('the summary of an open day is a preview that does not close it', function () {
    $usuario = usuarioParaCierre();
    $registroDiario = diaDeHoyConDesayunoRegistrado($usuario);

    $this->actingAs($usuario)->get(route('cierre.index'))
        ->assertOk()
        ->assertSee('Resumen previo (todavía sin cerrar)')
        ->assertSee('1,852');

    expect($registroDiario->fresh()->cerrado)->toBeFalse()
        ->and($registroDiario->fresh()->deficit_diario)->toBeNull();
});
