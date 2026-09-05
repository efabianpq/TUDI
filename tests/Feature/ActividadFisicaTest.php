<?php

use App\Models\RegistroDiario;
use App\Models\User;
use App\Services\ActivityCorrectionService;

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

test('guests cannot access the actividades form', function () {
    $response = $this->get('/actividades');

    $response->assertRedirect('/login');
});

test('registering an activity applies the correction factor for its tipo', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->post('/actividades', actividadPayload());

    $response->assertSessionHasNoErrors()->assertRedirect(route('actividades.create'));

    $registroDiario = RegistroDiario::where('usuario_id', $user->id)->first();
    $actividad = $registroDiario->actividadesFisicas()->first();

    expect((float) $actividad->factor_correccion)->toBe(ActivityCorrectionService::FACTORES_POR_TIPO['pesas'])
        ->and((float) $actividad->calorias_ajustadas)->toBe(400 * ActivityCorrectionService::FACTORES_POR_TIPO['pesas'])
        ->and($actividad->pasos)->toBe(2000)
        ->and($actividad->fuente)->toBe('dispositivo');
});

test('registering an activity creates the registro diario for today if it does not exist', function () {
    $user = User::factory()->create();

    expect(RegistroDiario::where('usuario_id', $user->id)->exists())->toBeFalse();

    $this->actingAs($user)->post('/actividades', actividadPayload());

    $registroDiario = RegistroDiario::where('usuario_id', $user->id)->first();

    expect($registroDiario)->not->toBeNull()
        ->and($registroDiario->fecha->toDateString())->toBe(now()->toDateString());
});

test('the registro diario sums the adjusted calories of several activities in the same day', function () {
    $user = User::factory()->create();
    $registroDiario = RegistroDiario::factory()->for($user, 'usuario')->create([
        'fecha' => now()->toDateString(),
    ]);

    $this->actingAs($user)->post('/actividades', actividadPayload([
        'tipo_actividad' => 'pesas',
        'calorias_dispositivo' => 400,
    ]));

    $this->actingAs($user)->post('/actividades', actividadPayload([
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

    $response = $this
        ->actingAs($user)
        ->post('/actividades', actividadPayload(['duracion_min' => 0]));

    $response->assertSessionHasErrors('duracion_min');

    expect(RegistroDiario::where('usuario_id', $user->id)->exists())->toBeFalse();
});

test('an unknown fuente fails validation', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->post('/actividades', actividadPayload(['fuente' => 'estimacion']));

    $response->assertSessionHasErrors('fuente');
});
