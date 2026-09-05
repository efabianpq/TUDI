<?php

use App\Models\IngredienteDisponible;
use App\Models\RegistroDiario;
use App\Models\User;
use App\Services\MealPlanGeneratorService;
use App\Services\NutritionCalculatorService;

/**
 * A user whose nutritional parameters are already filled in, so
 * NutritionCalculatorService can produce a target for them.
 */
function usuarioConParametros(array $overrides = []): User
{
    return User::factory()->create(array_merge([
        'peso_kg' => 80,
        'estatura_m' => 1.75,
        'edad' => 35,
        'sexo' => 'masculino',
        'nivel_actividad' => 1.5,
        'tipo_deficit' => NutritionCalculatorService::TIPO_DEFICIT_PORCENTAJE,
        'valor_deficit' => 0.2,
        'proteina_factor' => 1.8,
        'grasa_factor' => 0.8,
    ], $overrides));
}

function registroDeHoyCon(User $usuario, bool $conIngredientes = true): RegistroDiario
{
    $registroDiario = RegistroDiario::factory()->for($usuario, 'usuario')->create([
        'fecha' => now()->toDateString(),
    ]);

    if (! $conIngredientes) {
        return $registroDiario;
    }

    $despensa = [
        ['Pechuga de pollo', 1000, 165, 31.0, 3.6, 0.0],
        ['Arroz integral', 1000, 350, 7.5, 2.7, 72.0],
        ['Aceite de oliva', 200, 884, 0.0, 100.0, 0.0],
    ];

    foreach ($despensa as [$nombre, $cantidad, $calorias, $proteina, $grasa, $carbohidratos]) {
        IngredienteDisponible::factory()->for($registroDiario, 'registroDiario')->create([
            'nombre' => $nombre,
            'cantidad_g' => $cantidad,
            'calorias_por_100g' => $calorias,
            'proteina_por_100g' => $proteina,
            'grasa_por_100g' => $grasa,
            'carbohidratos_por_100g' => $carbohidratos,
        ]);
    }

    return $registroDiario;
}

test('guests cannot see or generate the plan', function () {
    $this->get(route('planes.index'))->assertRedirect('/login');
    $this->post(route('planes.generar'))->assertRedirect('/login');
});

test('generating the plan creates the three meals of the day', function () {
    $usuario = usuarioConParametros();
    $registroDiario = registroDeHoyCon($usuario);

    $response = $this->actingAs($usuario)->post(route('planes.generar'));

    $response->assertRedirect(route('planes.index'))->assertSessionHas('status', 'plan-generado');

    $planes = $registroDiario->planesComida()->orderBy('id')->get();

    expect($planes)->toHaveCount(3)
        ->and($planes->pluck('tipo_comida')->all())->toBe(['desayuno', 'almuerzo', 'cena'])
        ->and($planes->every(fn ($plan) => (float) $plan->calorias_estimadas > 0))->toBeTrue();
});

test('the generated plan is shown on the plan page', function () {
    $usuario = usuarioConParametros();
    registroDeHoyCon($usuario);

    $this->actingAs($usuario)->post(route('planes.generar'));

    $response = $this->actingAs($usuario)->get(route('planes.index'));

    $response->assertOk()
        ->assertSee('desayuno')
        ->assertSee('almuerzo')
        ->assertSee('cena')
        ->assertSee('Pechuga de pollo');
});

test('generating the plan without any registro diario for today fails in a controlled way', function () {
    $usuario = usuarioConParametros();

    $response = $this->actingAs($usuario)->post(route('planes.generar'));

    $response->assertRedirect(route('ingredientes.create'))->assertSessionHas('error');

    expect($usuario->registrosDiarios()->count())->toBe(0);
});

test('generating the plan with no reported ingredients fails in a controlled way, not with a 500', function () {
    $usuario = usuarioConParametros();
    $registroDiario = registroDeHoyCon($usuario, conIngredientes: false);

    $response = $this->actingAs($usuario)->post(route('planes.generar'));

    $response->assertRedirect(route('ingredientes.create'))->assertSessionHas('error');

    expect($registroDiario->planesComida()->count())->toBe(0);
});

test('generating the plan without nutritional parameters redirects to the profile form', function () {
    $usuario = usuarioConParametros(['peso_kg' => null]);
    registroDeHoyCon($usuario);

    $response = $this->actingAs($usuario)->post(route('planes.generar'));

    $response->assertRedirect(route('profile.parametros.edit'))->assertSessionHas('error');
});

test('the plan is built only from the authenticated users ingredients', function () {
    $usuario = usuarioConParametros();
    $otroUsuario = usuarioConParametros();
    registroDeHoyCon($otroUsuario);

    // El otro usuario sí tiene ingredientes hoy, pero el usuario autenticado no:
    // su plan no debe generarse con la despensa ajena.
    $response = $this->actingAs($usuario)->post(route('planes.generar'));

    $response->assertRedirect(route('ingredientes.create'))->assertSessionHas('error');
});

test('the plan page is reachable before any plan exists', function () {
    $usuario = usuarioConParametros();

    $response = $this->actingAs($usuario)->get(route('planes.index'));

    $response->assertOk()->assertSee('Generar mi plan de hoy');
});

test('the whole day adds up to roughly the calorie target', function () {
    $usuario = usuarioConParametros();
    $registroDiario = registroDeHoyCon($usuario);

    $this->actingAs($usuario)->post(route('planes.generar'));

    $objetivo = app(NutritionCalculatorService::class)->calculatePlan(
        80.0,
        1.5,
        NutritionCalculatorService::TIPO_DEFICIT_PORCENTAJE,
        0.2,
        1.8,
        0.8,
    )['calorias_objetivo'];

    $planificadas = $registroDiario->planesComida()->get()
        ->sum(fn ($plan) => (float) $plan->calorias_estimadas);

    expect($planificadas)->toEqualWithDelta($objetivo, $objetivo * 0.05)
        ->and(array_sum(MealPlanGeneratorService::DISTRIBUCION_COMIDAS))->toEqualWithDelta(1.0, 0.000001);
});
