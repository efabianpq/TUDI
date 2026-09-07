<?php

use App\Models\RegistroDiario;
use App\Models\User;

/**
 * El peso del día se captura dentro del plan diario, no en una pantalla propia
 * (CLAUDE.md sección 4.11): "Registrar peso" dejó de ser un menú porque por sí
 * solo no daba ninguna funcionalidad.
 */
function planDiarioDe(User $usuario, array $overrides = []): RegistroDiario
{
    return RegistroDiario::factory()->for($usuario, 'usuario')->create(array_merge([
        'fecha' => now()->toDateString(),
    ], $overrides));
}

test('guests cannot register a weight', function () {
    $registroDiario = planDiarioDe(User::factory()->create());

    $this->post(route('planes.peso', $registroDiario), ['peso_kg' => 79.4])
        ->assertRedirect('/login');
});

test('registering a weight stores it on that daily plan', function () {
    $user = User::factory()->create();
    $registroDiario = planDiarioDe($user);

    $response = $this->actingAs($user)
        ->post(route('planes.peso', $registroDiario), ['peso_kg' => 79.4]);

    $response->assertSessionHasNoErrors()
        ->assertRedirect(route('planes.show', $registroDiario))
        ->assertSessionHas('status', 'peso-guardado');

    expect((float) $registroDiario->fresh()->peso_kg)->toBe(79.4);
});

test('registering a second weight the same day corrects the first one instead of duplicating the registro', function () {
    $user = User::factory()->create();
    $registroDiario = planDiarioDe($user);

    $this->actingAs($user)->post(route('planes.peso', $registroDiario), ['peso_kg' => 80.0]);
    $this->actingAs($user)->post(route('planes.peso', $registroDiario), ['peso_kg' => 79.8]);

    expect(RegistroDiario::where('usuario_id', $user->id)->count())->toBe(1)
        ->and((float) $registroDiario->fresh()->peso_kg)->toBe(79.8);
});

test('registering a weight does not touch the profile weight used for the TMB', function () {
    $user = User::factory()->create(['peso_kg' => 80.0]);
    $registroDiario = planDiarioDe($user);

    $this->actingAs($user)->post(route('planes.peso', $registroDiario), ['peso_kg' => 79.0]);

    expect((float) $user->fresh()->peso_kg)->toBe(80.0);
});

test('a weight outside the plausible range fails validation', function () {
    $user = User::factory()->create();
    $registroDiario = planDiarioDe($user);

    $this->actingAs($user)
        ->post(route('planes.peso', $registroDiario), ['peso_kg' => 5])
        ->assertSessionHasErrors('peso_kg');
});

test('a user cannot register a weight on another users plan', function () {
    $registroDiario = planDiarioDe(User::factory()->create());

    $this->actingAs(User::factory()->create())
        ->post(route('planes.peso', $registroDiario), ['peso_kg' => 79.0])
        ->assertForbidden();

    expect($registroDiario->fresh()->peso_kg)->toBeNull();
});

test('a closed day still accepts a weight, since it does not feed any closure figure', function () {
    $user = User::factory()->create();
    $registroDiario = RegistroDiario::factory()->cerrado()->for($user, 'usuario')->create([
        'fecha' => now()->toDateString(),
    ]);

    $response = $this->actingAs($user)
        ->post(route('planes.peso', $registroDiario), ['peso_kg' => 78.5]);

    $response->assertSessionHasNoErrors();
    expect((float) $registroDiario->fresh()->peso_kg)->toBe(78.5);
});
