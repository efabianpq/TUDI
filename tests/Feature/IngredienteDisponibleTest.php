<?php

use App\Models\IngredienteDisponible;
use App\Models\RegistroDiario;
use App\Models\User;

function ingredientePayload(array $overrides = []): array
{
    return array_merge([
        'nombre' => 'Pechuga de pollo',
        'cantidad_g' => 200,
        'calorias_por_100g' => 165,
        'proteina_por_100g' => 31,
        'grasa_por_100g' => 3.6,
        'carbohidratos_por_100g' => 0,
    ], $overrides);
}

test('guests cannot access the ingredientes form', function () {
    $response = $this->get('/ingredientes');

    $response->assertRedirect('/login');
});

test('reporting ingredients creates the registro diario for today if it does not exist', function () {
    $user = User::factory()->create();

    expect(RegistroDiario::where('usuario_id', $user->id)->exists())->toBeFalse();

    $response = $this
        ->actingAs($user)
        ->post('/ingredientes', [
            'ingredientes' => [ingredientePayload()],
        ]);

    $response->assertSessionHasNoErrors()->assertRedirect(route('ingredientes.create'));

    $registroDiario = RegistroDiario::where('usuario_id', $user->id)->first();

    expect($registroDiario)->not->toBeNull()
        ->and($registroDiario->fecha->toDateString())->toBe(now()->toDateString())
        ->and($registroDiario->ingredientesDisponibles)->toHaveCount(1)
        ->and($registroDiario->ingredientesDisponibles->first()->nombre)->toBe('Pechuga de pollo');
});

test('reporting ingredients reuses the existing registro diario for today', function () {
    $user = User::factory()->create();
    $registroDiario = RegistroDiario::factory()->for($user, 'usuario')->create([
        'fecha' => now()->toDateString(),
    ]);

    $this
        ->actingAs($user)
        ->post('/ingredientes', [
            'ingredientes' => [ingredientePayload(), ingredientePayload(['nombre' => 'Arroz integral'])],
        ])
        ->assertSessionHasNoErrors();

    expect(RegistroDiario::where('usuario_id', $user->id)->count())->toBe(1)
        ->and($registroDiario->fresh()->ingredientesDisponibles)->toHaveCount(2);
});

test('reporting an ingredient with a negative amount fails validation', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->post('/ingredientes', [
            'ingredientes' => [ingredientePayload(['cantidad_g' => -50])],
        ]);

    $response->assertSessionHasErrors('ingredientes.0.cantidad_g');

    expect(RegistroDiario::where('usuario_id', $user->id)->exists())->toBeFalse();
});

test('listing ingredients only returns those belonging to the authenticated user', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();

    $registroDiario = RegistroDiario::factory()->for($user, 'usuario')->create();
    $otherRegistroDiario = RegistroDiario::factory()->for($otherUser, 'usuario')->create();

    IngredienteDisponible::factory()->for($registroDiario, 'registroDiario')->create(['nombre' => 'Avena']);
    IngredienteDisponible::factory()->for($otherRegistroDiario, 'registroDiario')->create(['nombre' => 'Huevo']);

    $response = $this
        ->actingAs($user)
        ->get(route('ingredientes.index', $registroDiario));

    $response->assertOk();
    $response->assertSee('Avena');
    $response->assertDontSee('Huevo');
});

test('a user cannot list ingredients from another users registro diario', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();

    $otherRegistroDiario = RegistroDiario::factory()->for($otherUser, 'usuario')->create();

    $response = $this
        ->actingAs($user)
        ->get(route('ingredientes.index', $otherRegistroDiario));

    $response->assertForbidden();
});

test('an ingredient can be updated', function () {
    $user = User::factory()->create();
    $registroDiario = RegistroDiario::factory()->for($user, 'usuario')->create();
    $ingrediente = IngredienteDisponible::factory()->for($registroDiario, 'registroDiario')->create(['nombre' => 'Avena']);

    $response = $this
        ->actingAs($user)
        ->put(route('ingredientes.update', $ingrediente), ingredientePayload(['nombre' => 'Avena integral']));

    $response->assertSessionHasNoErrors()->assertRedirect(route('ingredientes.index', $registroDiario));

    expect($ingrediente->fresh()->nombre)->toBe('Avena integral');
});

test('an ingredient can be deleted', function () {
    $user = User::factory()->create();
    $registroDiario = RegistroDiario::factory()->for($user, 'usuario')->create();
    $ingrediente = IngredienteDisponible::factory()->for($registroDiario, 'registroDiario')->create();

    $response = $this
        ->actingAs($user)
        ->delete(route('ingredientes.destroy', $ingrediente));

    $response->assertRedirect(route('ingredientes.index', $registroDiario));

    expect(IngredienteDisponible::find($ingrediente->id))->toBeNull();
});

test('a user cannot delete another users ingredient', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $otherRegistroDiario = RegistroDiario::factory()->for($otherUser, 'usuario')->create();
    $ingrediente = IngredienteDisponible::factory()->for($otherRegistroDiario, 'registroDiario')->create();

    $response = $this
        ->actingAs($user)
        ->delete(route('ingredientes.destroy', $ingrediente));

    $response->assertForbidden();

    expect(IngredienteDisponible::find($ingrediente->id))->not->toBeNull();
});
