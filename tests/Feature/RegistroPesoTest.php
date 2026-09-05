<?php

use App\Models\RegistroDiario;
use App\Models\User;

test('guests cannot access the peso form', function () {
    $response = $this->get('/peso');

    $response->assertRedirect('/login');
});

test('registering a weight creates the registro diario for today if it does not exist', function () {
    $user = User::factory()->create();

    expect(RegistroDiario::where('usuario_id', $user->id)->exists())->toBeFalse();

    $response = $this->actingAs($user)->post('/peso', ['peso_kg' => 79.4]);

    $response->assertSessionHasNoErrors()->assertRedirect(route('peso.create'));

    $registroDiario = RegistroDiario::where('usuario_id', $user->id)->first();

    expect($registroDiario)->not->toBeNull()
        ->and($registroDiario->fecha->toDateString())->toBe(now()->toDateString())
        ->and((float) $registroDiario->peso_kg)->toBe(79.4);
});

test('registering a second weight the same day corrects the first one instead of duplicating the registro', function () {
    $user = User::factory()->create();
    $registroDiario = RegistroDiario::factory()->for($user, 'usuario')->create([
        'fecha' => now()->toDateString(),
    ]);

    $this->actingAs($user)->post('/peso', ['peso_kg' => 80.0]);
    $this->actingAs($user)->post('/peso', ['peso_kg' => 79.8]);

    expect(RegistroDiario::where('usuario_id', $user->id)->count())->toBe(1)
        ->and((float) $registroDiario->fresh()->peso_kg)->toBe(79.8);
});

test('registering a weight does not touch the profile weight used for the TMB', function () {
    $user = User::factory()->create(['peso_kg' => 80.0]);

    $this->actingAs($user)->post('/peso', ['peso_kg' => 79.0]);

    expect((float) $user->fresh()->peso_kg)->toBe(80.0);
});

test('a weight outside the plausible range fails validation', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post('/peso', ['peso_kg' => 5]);

    $response->assertSessionHasErrors('peso_kg');
});

test('a closed day still accepts a weight, since it does not feed any closure figure', function () {
    $user = User::factory()->create();
    $registroDiario = RegistroDiario::factory()->cerrado()->for($user, 'usuario')->create([
        'fecha' => now()->toDateString(),
    ]);

    $response = $this->actingAs($user)->post('/peso', ['peso_kg' => 78.5]);

    $response->assertSessionHasNoErrors();
    expect((float) $registroDiario->fresh()->peso_kg)->toBe(78.5);
});
