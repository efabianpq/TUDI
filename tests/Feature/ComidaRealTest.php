<?php

use App\Models\PlanComida;
use App\Models\RegistroDiario;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

function registroConPlanes(User $usuario, array $desayuno = [], array $almuerzo = [], array $cena = []): RegistroDiario
{
    $registroDiario = RegistroDiario::factory()->for($usuario, 'usuario')->create([
        'fecha' => now()->toDateString(),
        'calorias_consumidas' => 0,
    ]);

    PlanComida::factory()->for($registroDiario, 'registroDiario')->create(array_merge([
        'tipo_comida' => 'desayuno',
        'calorias_estimadas' => 500,
    ], $desayuno));

    PlanComida::factory()->for($registroDiario, 'registroDiario')->create(array_merge([
        'tipo_comida' => 'almuerzo',
        'calorias_estimadas' => 800,
    ], $almuerzo));

    PlanComida::factory()->for($registroDiario, 'registroDiario')->create(array_merge([
        'tipo_comida' => 'cena',
        'calorias_estimadas' => 700,
    ], $cena));

    return $registroDiario;
}

test('guests cannot log a comida real', function () {
    $planComida = PlanComida::factory()->create();

    $this->get(route('comida-real.create', $planComida))->assertRedirect('/login');
    $this->post(route('comida-real.store', $planComida))->assertRedirect('/login');
});

test('a user cannot log a comida real for another users plan', function () {
    $usuario = User::factory()->create();
    $otroUsuario = User::factory()->create();
    $registroDiario = registroConPlanes($otroUsuario);
    $planComida = $registroDiario->planesComida()->where('tipo_comida', 'desayuno')->first();

    $this->actingAs($usuario)->post(route('comida-real.store', $planComida), [
        'calorias_reales' => 500,
        'proteina_g' => 30,
        'grasa_g' => 15,
        'carbohidratos_g' => 50,
    ])->assertForbidden();
});

test('a user cannot see the comida real form for another users plan', function () {
    $usuario = User::factory()->create();
    $otroUsuario = User::factory()->create();
    $registroDiario = registroConPlanes($otroUsuario);
    $planComida = $registroDiario->planesComida()->where('tipo_comida', 'desayuno')->first();

    $this->actingAs($usuario)
        ->get(route('comida-real.create', $planComida))
        ->assertForbidden();
});

test('logging a comida real updates calorias_consumidas on the registro diario', function () {
    $usuario = User::factory()->create();
    $registroDiario = registroConPlanes($usuario);
    $desayuno = $registroDiario->planesComida()->where('tipo_comida', 'desayuno')->first();

    $response = $this->actingAs($usuario)->post(route('comida-real.store', $desayuno), [
        'calorias_reales' => 550,
        'proteina_g' => 35,
        'grasa_g' => 18,
        'carbohidratos_g' => 60,
    ]);

    $response->assertRedirect(route('planes.index'))->assertSessionHas('status', 'comida-real-guardada');

    expect((float) $registroDiario->fresh()->calorias_consumidas)->toBe(550.0);

    // Logging a second meal accumulates on top of the first.
    $almuerzo = $registroDiario->planesComida()->where('tipo_comida', 'almuerzo')->first();

    $this->actingAs($usuario)->post(route('comida-real.store', $almuerzo), [
        'calorias_reales' => 800,
        'proteina_g' => 40,
        'grasa_g' => 20,
        'carbohidratos_g' => 90,
    ]);

    expect((float) $registroDiario->fresh()->calorias_consumidas)->toBe(1350.0);
});

test('a calorie excess at breakfast reduces the calorie budget of the still-pending lunch and dinner', function () {
    $usuario = User::factory()->create();
    $registroDiario = registroConPlanes($usuario, desayuno: ['calorias_estimadas' => 500]);
    $desayuno = $registroDiario->planesComida()->where('tipo_comida', 'desayuno')->first();
    $almuerzo = $registroDiario->planesComida()->where('tipo_comida', 'almuerzo')->first();
    $cena = $registroDiario->planesComida()->where('tipo_comida', 'cena')->first();

    // 200 kcal excess over the 500 kcal planned for breakfast.
    $this->actingAs($usuario)->post(route('comida-real.store', $desayuno), [
        'calorias_reales' => 700,
        'proteina_g' => 30,
        'grasa_g' => 15,
        'carbohidratos_g' => 50,
    ]);

    // Excess is distributed proportionally to each pending meal's planned share
    // (almuerzo 800 of 1500 pending, cena 700 of 1500 pending).
    expect((float) $almuerzo->fresh()->calorias_estimadas)
        ->toEqualWithDelta(800 - 200 * (800 / 1500), 0.01)
        ->and((float) $cena->fresh()->calorias_estimadas)
        ->toEqualWithDelta(700 - 200 * (700 / 1500), 0.01)
        ->and((float) $desayuno->fresh()->calorias_estimadas)->toBe(500.0);
});

test('logging a comida real with an image leaves it accessible at the expected public path', function () {
    Storage::fake('public');

    $usuario = User::factory()->create();
    $registroDiario = registroConPlanes($usuario);
    $desayuno = $registroDiario->planesComida()->where('tipo_comida', 'desayuno')->first();

    $imagen = UploadedFile::fake()->image('desayuno.jpg');

    $this->actingAs($usuario)->post(route('comida-real.store', $desayuno), [
        'calorias_reales' => 550,
        'proteina_g' => 35,
        'grasa_g' => 18,
        'carbohidratos_g' => 60,
        'imagen' => $imagen,
    ]);

    $comidaReal = $desayuno->comidaReal()->first();

    expect($comidaReal->imagen_evidencia)->not->toBeNull();

    Storage::disk('public')->assertExists($comidaReal->imagen_evidencia);

    expect($comidaReal->imagenUrl())->toBe(Storage::disk('public')->url($comidaReal->imagen_evidencia))
        ->and($comidaReal->imagenUrl())->toContain('/storage/comidas-reales/');
});
