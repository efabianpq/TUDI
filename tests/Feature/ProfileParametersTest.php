<?php

use App\Models\User;

test('profile parameters page is displayed', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->get('/calculadora');

    $response->assertOk();
});

test('guests cannot access the profile parameters page', function () {
    $response = $this->get('/calculadora');

    $response->assertRedirect('/login');
});

test('profile parameters can be updated with valid values', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->put('/calculadora', [
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
        ->assertRedirect('/calculadora');

    $user->refresh();

    expect((float) $user->peso_kg)->toBe(80.5)
        ->and((float) $user->estatura_m)->toBe(1.78)
        ->and($user->edad)->toBe(30)
        ->and($user->sexo)->toBe('masculino')
        ->and((float) $user->nivel_actividad)->toBe(1.55)
        ->and($user->tipo_deficit)->toBe('porcentaje')
        ->and((float) $user->valor_deficit)->toBe(0.2)
        ->and((float) $user->proteina_factor)->toBe(2.0)
        ->and((float) $user->grasa_factor)->toBe(0.8)
        // 80.5 * 22 * 1.55 * (1 - 0.2) = 2196.04 kcal
        ->and((float) $user->calorias_objetivo)->toBe(2196.04);
});

test('saving the parameters derives the calorie target in force', function () {
    $user = User::factory()->create(['calorias_objetivo' => null]);

    $this->actingAs($user)->put('/calculadora', [
        'peso_kg' => 80,
        'estatura_m' => 1.75,
        'edad' => 35,
        'sexo' => 'masculino',
        'nivel_actividad' => 1.5,
        'tipo_deficit' => 'porcentaje',
        'valor_deficit' => 0.2,
        'proteina_factor' => 2.0,
        'grasa_factor' => 0.8,
    ])->assertSessionHasNoErrors();

    // 80 * 22 * 1.5 * (1 - 0.2) = 2112 kcal. Sin esto la columna quedaba null
    // para todo usuario registrado desde la app y RulesEngineService la leía
    // como 0 al dimensionar un ajuste (CLAUDE.md sección 4.10).
    expect((float) $user->fresh()->calorias_objetivo)->toBe(2112.0);
});

test('a profile whose macros do not fit its own calorie target is not saved', function () {
    $user = User::factory()->create();
    $parametrosPrevios = $user->only(['peso_kg', 'valor_deficit', 'proteina_factor', 'grasa_factor']);

    // 80 * 22 * 1.2 * (1 - 0.5) = 1056 kcal, pero proteína (176 g = 704 kcal) y
    // grasa (80 g = 720 kcal) ya suman 1424: carbohidratos negativos.
    $this->actingAs($user)->put('/calculadora', [
        'peso_kg' => 80,
        'estatura_m' => 1.75,
        'edad' => 35,
        'sexo' => 'masculino',
        'nivel_actividad' => 1.2,
        'tipo_deficit' => 'porcentaje',
        'valor_deficit' => 0.5,
        'proteina_factor' => 2.2,
        'grasa_factor' => 1.0,
    ])->assertSessionHasErrors('valor_deficit');

    expect($user->fresh()->only(['peso_kg', 'valor_deficit', 'proteina_factor', 'grasa_factor']))
        ->toBe($parametrosPrevios);
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
        ->put('/calculadora', $payload);

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

/*
|--------------------------------------------------------------------------
| Calculadora orientada a objetivo (CLAUDE.md sección 4.25)
|--------------------------------------------------------------------------
*/

test('la calculadora explica cada nivel de actividad y cada objetivo', function () {
    // La explicación que se ve es la del escalón guardado, así que el perfil se
    // fija en vez de dejarlo al azar de la factory.
    $usuario = User::factory()->create([
        'nivel_actividad' => 1.375,
        'tipo_deficit' => 'porcentaje',
        'valor_deficit' => 0.2,
    ]);

    $this->actingAs($usuario)
        ->get('/calculadora')
        ->assertOk()
        ->assertSee('¿Qué tan activo eres?')
        // La explicación es lo que evita que el usuario se sobreestime, así que
        // está en la pantalla, no detrás de un enlace.
        ->assertSee('Trabajo de escritorio con algo de ejercicio')
        ->assertSee('Ligeramente activo · 1-3 veces por semana')
        // El objetivo sustituye al par "tipo de déficit + valor".
        ->assertSee('Tu objetivo')
        ->assertSee('Perder rápido · −30%')
        ->assertSee('Ronda los 0,5–0,75 kg por semana.');
});

test('proteína y grasa salen del camino principal y se explican en el resultado', function () {
    $this->actingAs(User::factory()->create())
        ->get('/calculadora')
        ->assertOk()
        // Ya no se piden en el flujo normal: viven en un desplegable opcional.
        ->assertSee('Ajustes avanzados')
        // El resultado explica el rango recomendado en gramos, como hace la
        // calculadora de referencia, en vez de pedir un factor.
        ->assertSee('Proteína recomendada:')
        ->assertSee('Grasa mínima:');
});

test('los campos decimales aceptan coma y no son type=number', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/calculadora')
        ->assertOk()
        // type="number" hacía imposible teclear "1.72" desde el móvil
        // (CLAUDE.md sección 4.24).
        ->assertSee('id="peso_kg" name="peso_kg" type="text" inputmode="decimal"', escape: false)
        ->assertSee('id="estatura_m" name="estatura_m" type="text" inputmode="decimal"', escape: false);

    $this->actingAs($user)->put('/calculadora', [
        'peso_kg' => '80,5',
        'estatura_m' => '1,72',
        'edad' => 30,
        'sexo' => 'masculino',
        'nivel_actividad' => '1,55',
        'tipo_deficit' => 'porcentaje',
        'valor_deficit' => '0,2',
        'proteina_factor' => '2,0',
        'grasa_factor' => '0,8',
    ])->assertSessionHasNoErrors();

    $user->refresh();

    expect((float) $user->peso_kg)->toBe(80.5)
        ->and((float) $user->estatura_m)->toBe(1.72)
        ->and((float) $user->proteina_factor)->toBe(2.0)
        // 80,5 × 22 × 1,55 × 0,8 = 2196,04
        ->and((float) $user->calorias_objetivo)->toEqualWithDelta(2196.04, 0.01);
});

test('acepta el objetivo de mantener peso, sin déficit', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->put('/calculadora', [
        'peso_kg' => 80,
        'estatura_m' => 1.78,
        'edad' => 30,
        'sexo' => 'masculino',
        'nivel_actividad' => 1.55,
        'tipo_deficit' => 'porcentaje',
        'valor_deficit' => 0,
        'proteina_factor' => 1.6,
        'grasa_factor' => 0.8,
    ])->assertSessionHasNoErrors();

    // 80 * 22 * 1.55 = 2728, sin recorte.
    expect((float) $user->fresh()->calorias_objetivo)->toEqualWithDelta(2728.0, 0.01);
});
