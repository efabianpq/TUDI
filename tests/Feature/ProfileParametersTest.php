<?php

use App\Models\User;

test('profile parameters page is displayed', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->get('/profile/parametros');

    $response->assertOk();
});

test('guests cannot access the profile parameters page', function () {
    $response = $this->get('/profile/parametros');

    $response->assertRedirect('/login');
});

test('profile parameters can be updated with valid values', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->put('/profile/parametros', [
            'peso_kg' => 80.5,
            'estatura_m' => 1.78,
            'edad' => 30,
            'sexo' => 'masculino',
            'nivel_actividad' => 1.55,
            'tipo_deficit' => 'porcentaje',
            'valor_deficit' => 0.2,
            'proteina_factor' => 2.0,
            'grasa_factor' => 0.8,
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect('/profile/parametros');

    $user->refresh();

    expect((float) $user->peso_kg)->toBe(80.5)
        ->and((float) $user->estatura_m)->toBe(1.78)
        ->and($user->edad)->toBe(30)
        ->and($user->sexo)->toBe('masculino')
        ->and((float) $user->nivel_actividad)->toBe(1.55)
        ->and($user->tipo_deficit)->toBe('porcentaje')
        ->and((float) $user->valor_deficit)->toBe(0.2)
        ->and((float) $user->proteina_factor)->toBe(2.0)
        ->and((float) $user->grasa_factor)->toBe(0.8);
});

test('profile parameters fail validation when out of range', function (string $field, mixed $value) {
    $user = User::factory()->create();

    $payload = [
        'peso_kg' => 80.5,
        'estatura_m' => 1.78,
        'edad' => 30,
        'sexo' => 'masculino',
        'nivel_actividad' => 1.55,
        'tipo_deficit' => 'porcentaje',
        'valor_deficit' => 0.2,
        'proteina_factor' => 2.0,
        'grasa_factor' => 0.8,
    ];

    $payload[$field] = $value;

    $response = $this
        ->actingAs($user)
        ->put('/profile/parametros', $payload);

    $response->assertSessionHasErrors($field);
})->with([
    'nivel_actividad below range' => ['nivel_actividad', 1.0],
    'nivel_actividad above range' => ['nivel_actividad', 2.0],
    'proteina_factor below range' => ['proteina_factor', 1.0],
    'proteina_factor above range' => ['proteina_factor', 3.0],
    'grasa_factor below range' => ['grasa_factor', 0.3],
    'grasa_factor above range' => ['grasa_factor', 1.5],
    'sexo invalid' => ['sexo', 'otro'],
    'tipo_deficit invalid' => ['tipo_deficit', 'otro'],
]);
