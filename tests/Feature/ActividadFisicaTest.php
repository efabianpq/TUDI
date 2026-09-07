<?php

use App\Models\RegistroDiario;
use App\Models\User;
use App\Services\ActivityCorrectionService;

/**
 * La actividad física se registra dentro de un plan diario concreto (CLAUDE.md
 * sección 4.13): ya no hay una pantalla suelta que asuma "hoy".
 */
function actividadPayload(array $overrides = []): array
{
    return array_merge([
        'tipo_actividad' => 'pesas',
        'duracion_min' => 45,
        'calorias_dispositivo' => 400,
        'pasos' => 2000,
        'fuente' => 'dispositivo',
    ], $overrides);
}

function planDeHoyDe(User $usuario, array $overrides = []): RegistroDiario
{
    return RegistroDiario::factory()->for($usuario, 'usuario')->create(array_merge([
        'fecha' => now()->toDateString(),
    ], $overrides));
}

test('guests cannot register an activity', function () {
    $registroDiario = planDeHoyDe(User::factory()->create());

    $this->post(route('actividades.store', $registroDiario), actividadPayload())
        ->assertRedirect('/login');
});

test('registering an activity applies the correction factor for its tipo', function () {
    $user = User::factory()->create();
    $registroDiario = planDeHoyDe($user);

    $response = $this
        ->actingAs($user)
        ->post(route('actividades.store', $registroDiario), actividadPayload());

    $response->assertSessionHasNoErrors()->assertRedirect(route('planes.show', $registroDiario));

    $actividad = $registroDiario->actividadesFisicas()->first();

    expect((float) $actividad->factor_correccion)->toBe(ActivityCorrectionService::FACTORES_POR_TIPO['pesas'])
        ->and((float) $actividad->calorias_ajustadas)->toBe(400 * ActivityCorrectionService::FACTORES_POR_TIPO['pesas'])
        ->and($actividad->pasos)->toBe(2000)
        ->and($actividad->fuente)->toBe('dispositivo');
});

test('a user cannot register an activity on another users plan', function () {
    $registroDiario = planDeHoyDe(User::factory()->create());

    $this->actingAs(User::factory()->create())
        ->post(route('actividades.store', $registroDiario), actividadPayload())
        ->assertForbidden();

    expect($registroDiario->actividadesFisicas()->count())->toBe(0);
});

test('the registro diario sums the adjusted calories of several activities in the same day', function () {
    $user = User::factory()->create();
    $registroDiario = planDeHoyDe($user);

    $this->actingAs($user)->post(route('actividades.store', $registroDiario), actividadPayload([
        'tipo_actividad' => 'pesas',
        'calorias_dispositivo' => 400,
    ]));

    $this->actingAs($user)->post(route('actividades.store', $registroDiario), actividadPayload([
        'tipo_actividad' => 'caminata',
        'calorias_dispositivo' => 300,
    ]));

    $esperado = (400 * ActivityCorrectionService::FACTORES_POR_TIPO['pesas'])
        + (300 * ActivityCorrectionService::FACTORES_POR_TIPO['caminata']);

    expect((float) $registroDiario->fresh()->calorias_actividad_ajustada)->toBe($esperado)
        ->and($registroDiario->actividadesFisicas)->toHaveCount(2);
});

test('a duracion_min of zero fails validation', function () {
    $user = User::factory()->create();
    $registroDiario = planDeHoyDe($user);

    $response = $this
        ->actingAs($user)
        ->post(route('actividades.store', $registroDiario), actividadPayload(['duracion_min' => 0]));

    $response->assertSessionHasErrors('duracion_min');

    expect($registroDiario->actividadesFisicas()->count())->toBe(0);
});

test('an unknown fuente fails validation', function () {
    $user = User::factory()->create();
    $registroDiario = planDeHoyDe($user);

    $response = $this
        ->actingAs($user)
        ->post(route('actividades.store', $registroDiario), actividadPayload(['fuente' => 'estimacion']));

    $response->assertSessionHasErrors('fuente');
});
