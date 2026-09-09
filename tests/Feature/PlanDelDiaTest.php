<?php

use App\Models\PlanComida;
use App\Models\RegistroDiario;
use App\Models\User;
use App\Services\NutritionCalculatorService;
use Illuminate\Support\Facades\Http;

/**
 * Planes diarios (CLAUDE.md secciones 4.12 a 4.15): el listado, el detalle de
 * un día, la distribución única de las tres comidas con IA, la actividad
 * física y el cierre.
 */
beforeEach(function () {
    config([
        'services.openai.key' => 'clave-de-prueba',
        'services.openai.model' => 'gpt-4.1',
        'services.openai.endpoint' => 'https://api.openai.com/v1',
        // Aquí se prueba el flujo del controlador, no la corrección de macros
        // del proveedor (que tiene sus propios tests): sin reintentos, cada
        // "Generar distribución" es exactamente una llamada y las cuentas de
        // este archivo no dependen de lo cerca que quede el fake del objetivo.
        'services.openai.reintentos_macros' => 0,
    ]);
});

function usuarioDelPlan(array $overrides = []): User
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
        'calorias_objetivo' => 2112,
    ], $overrides));
}

function planDiarioDeHoy(User $usuario): RegistroDiario
{
    return RegistroDiario::factory()->for($usuario, 'usuario')->create([
        'fecha' => now()->toDateString(),
    ]);
}

/**
 * Modelo de mentira: responde con una entrada por cada comida que el prompt
 * pida resolver (las que llevan su propia cabecera "### tipo"). Así el mismo
 * fake sirve para todas las pasadas de un test sin tener que reprogramarlo —
 * `Http::fake()` fusiona los stubs y el primero registrado gana.
 */
function fingirRespuestaDeLaIa(): void
{
    Http::fake(['api.openai.com/*' => function ($peticion) {
        $prompt = $peticion->data()['messages'][1]['content'];

        $tipos = array_values(array_filter(
            ['desayuno', 'almuerzo', 'cena'],
            fn (string $tipo): bool => str_contains($prompt, "### {$tipo}"),
        ));

        return Http::response([
            'choices' => [[
                'index' => 0,
                'message' => [
                    'role' => 'assistant',
                    'refusal' => null,
                    'content' => json_encode(['comidas' => array_map(fn (string $tipo): array => [
                        'tipo_comida' => $tipo,
                        'descripcion' => "Huevos revueltos con palta ({$tipo})",
                        'preparacion' => 'Revuelve los huevos a fuego bajo.',
                        'notas' => '',
                        'alimentos_reconocidos' => true,
                        'ingredientes' => [
                            ['nombre' => 'Huevo', 'porcion' => '2 unidades', 'cantidad_g' => 100, 'calorias' => 143, 'proteina_g' => 12.6, 'grasa_g' => 9.5, 'carbohidratos_g' => 0.7],
                            ['nombre' => 'Palta', 'porcion' => 'media unidad', 'cantidad_g' => 70, 'calorias' => 112, 'proteina_g' => 1.4, 'grasa_g' => 10.3, 'carbohidratos_g' => 6.0],
                        ],
                    ], $tipos)]),
                ],
                'finish_reason' => 'stop',
            ]],
        ]);
    }]);
}

test('guests cannot reach the daily plans', function () {
    $registroDiario = planDiarioDeHoy(usuarioDelPlan());

    $this->get(route('planes.index'))->assertRedirect('/login');
    $this->get(route('planes.show', $registroDiario))->assertRedirect('/login');
    $this->post(route('planes.crear'))->assertRedirect('/login');
    $this->post(route('planes.distribucion', $registroDiario))->assertRedirect('/login');
});

test('the plan list shows every daily plan of the user, newest first', function () {
    $usuario = usuarioDelPlan();

    RegistroDiario::factory()->for($usuario, 'usuario')->create(['fecha' => now()->subDays(2)->toDateString()]);
    RegistroDiario::factory()->for($usuario, 'usuario')->cerrado()->create(['fecha' => now()->subDay()->toDateString()]);
    planDiarioDeHoy($usuario);

    $response = $this->actingAs($usuario)->get(route('planes.index'));

    $response->assertOk()
        ->assertSee('Planes diarios')
        ->assertSee(now()->format('d/m/Y'))
        ->assertSee(now()->subDay()->format('d/m/Y'))
        ->assertSee(now()->subDays(2)->format('d/m/Y'))
        ->assertSee('Abrir el plan de hoy')
        ->assertSeeInOrder([
            now()->format('d/m/Y'),
            now()->subDay()->format('d/m/Y'),
            now()->subDays(2)->format('d/m/Y'),
        ]);
});

test('the plan list paginates once the history grows past a page', function () {
    $usuario = usuarioDelPlan();

    foreach (range(0, 34) as $dias) {
        RegistroDiario::factory()->for($usuario, 'usuario')->create([
            'fecha' => now()->subDays($dias)->toDateString(),
        ]);
    }

    $this->actingAs($usuario)->get(route('planes.index'))
        ->assertOk()
        ->assertSee('35 planes')
        // La página 1 llega hasta el día 29; el 34 queda para la siguiente.
        ->assertSee(now()->subDays(29)->format('d/m/Y'))
        ->assertDontSee(now()->subDays(34)->format('d/m/Y'));

    $this->actingAs($usuario)->get(route('planes.index', ['page' => 2]))
        ->assertOk()
        ->assertSee(now()->subDays(34)->format('d/m/Y'));
});

test('the plan list does not leak another users plans', function () {
    $usuario = usuarioDelPlan();
    $otroUsuario = usuarioDelPlan();

    RegistroDiario::factory()->for($otroUsuario, 'usuario')->create(['fecha' => now()->subDays(3)->toDateString()]);

    $this->actingAs($usuario)->get(route('planes.index'))
        ->assertOk()
        ->assertDontSee(now()->subDays(3)->format('d/m/Y'));
});

test('a user cannot open another users daily plan', function () {
    $registroDiario = planDiarioDeHoy(usuarioDelPlan());

    $this->actingAs(usuarioDelPlan())
        ->get(route('planes.show', $registroDiario))
        ->assertForbidden();
});

test('creating the plan opens today\'s registro diario and lands on its detail', function () {
    $usuario = usuarioDelPlan();

    expect(RegistroDiario::where('usuario_id', $usuario->id)->exists())->toBeFalse();

    $response = $this->actingAs($usuario)->post(route('planes.crear'));

    $registroDiario = RegistroDiario::where('usuario_id', $usuario->id)->firstOrFail();

    $response->assertRedirect(route('planes.show', $registroDiario))
        ->assertSessionHas('status', 'plan-creado');

    expect($registroDiario->fecha->toDateString())->toBe(now()->toDateString());

    // Crear de nuevo el mismo día reutiliza el plan existente, no lo duplica.
    $this->actingAs($usuario)->post(route('planes.crear'))
        ->assertRedirect(route('planes.show', $registroDiario))
        ->assertSessionHas('status', 'plan-existente');

    expect(RegistroDiario::where('usuario_id', $usuario->id)->count())->toBe(1);
});

test('the plan detail shows the three sections of the daily flow', function () {
    $usuario = usuarioDelPlan();
    $registroDiario = planDiarioDeHoy($usuario);

    $response = $this->actingAs($usuario)->get(route('planes.show', $registroDiario));

    $response->assertOk()
        ->assertSee('Cálculo alimenticio')
        ->assertSee('Actividad física')
        ->assertSee('Cierre del día')
        ->assertSee('desayuno')
        ->assertSee('almuerzo')
        ->assertSee('cena')
        // Un solo botón para las tres comidas (hallazgo 2).
        ->assertSee('Generar distribución')
        // El objetivo del día, repartido: 2112 kcal.
        ->assertSee('2.112');
});

test('the calorie target is visible on every page next to the user', function () {
    $usuario = usuarioDelPlan();
    $registroDiario = planDiarioDeHoy($usuario);

    foreach ([route('dashboard'), route('planes.index'), route('planes.show', $registroDiario)] as $url) {
        $this->actingAs($usuario)->get($url)
            ->assertOk()
            ->assertSee('2.112')
            ->assertSee('kcal/día');
    }
});

test('a user without nutritional parameters is sent to the calculator instead of getting a 500', function () {
    $usuario = usuarioDelPlan(['peso_kg' => null, 'calorias_objetivo' => null]);
    $registroDiario = planDiarioDeHoy($usuario);

    $response = $this->actingAs($usuario)->get(route('planes.show', $registroDiario));

    $response->assertOk()
        ->assertSee('Calculadora Déficit')
        ->assertSee('Ir a la Calculadora Déficit')
        ->assertDontSee('Cálculo alimenticio');
});

test('one single button distributes every meal that has text, in one call', function () {
    fingirRespuestaDeLaIa();
    $usuario = usuarioDelPlan();
    $registroDiario = planDiarioDeHoy($usuario);

    $response = $this->actingAs($usuario)->post(route('planes.distribucion', $registroDiario), [
        'ingredientes' => [
            'desayuno' => 'tengo dos huevos y media palta',
            'almuerzo' => 'pollo con arroz',
            'cena' => 'ensalada y atún',
        ],
    ]);

    $response->assertSessionHasNoErrors()
        ->assertRedirect(route('planes.show', $registroDiario))
        ->assertSessionHas('status', 'distribucion-generada');

    // Una sola llamada al proveedor para el día entero.
    Http::assertSentCount(1);

    $registroDiario->refresh();

    // Los textos quedan guardados para que el usuario los vea y los corrija.
    expect($registroDiario->ingredientes_desayuno)->toBe('tengo dos huevos y media palta')
        ->and($registroDiario->ingredientes_cena)->toBe('ensalada y atún')
        ->and($registroDiario->planesComida()->count())->toBe(3);

    $plan = $registroDiario->planesComida()->where('tipo_comida', 'desayuno')->firstOrFail();

    // 143 + 112 = 255 kcal, sumados en PHP a partir de los ingredientes.
    expect((float) $plan->calorias_estimadas)->toBe(255.0)
        ->and((float) $plan->proteina_g)->toBe(14.0);

    $this->actingAs($usuario)->get(route('planes.show', $registroDiario))
        ->assertOk()
        ->assertSee('Huevos revueltos con palta (desayuno)')
        ->assertSee('Huevo')
        ->assertSee('2 unidades');
});

test('starting with breakfast and lunch reserves the dinner budget, and adding dinner later only updates dinner', function () {
    fingirRespuestaDeLaIa();
    $usuario = usuarioDelPlan();
    $registroDiario = planDiarioDeHoy($usuario);

    $this->actingAs($usuario)->post(route('planes.distribucion', $registroDiario), [
        'ingredientes' => [
            'desayuno' => 'tengo dos huevos y media palta',
            'almuerzo' => 'pollo con arroz',
            'cena' => '',
        ],
    ])->assertSessionHasNoErrors();

    expect($registroDiario->planesComida()->count())->toBe(2);

    // El prompt le dice al modelo que la cena tiene su presupuesto reservado.
    Http::assertSent(fn ($peticion) => str_contains($peticion->data()['messages'][1]['content'], 'TODAVÍA NO HA ESCRITO')
        && str_contains($peticion->data()['messages'][1]['content'], 'cena'));

    $idsDeLaManana = $registroDiario->planesComida()->pluck('id', 'tipo_comida')->all();

    // Por la tarde llega la cena: el formulario reenvía los tres textos.
    fingirRespuestaDeLaIa();

    $this->actingAs($usuario)->post(route('planes.distribucion', $registroDiario), [
        'ingredientes' => [
            'desayuno' => 'tengo dos huevos y media palta',
            'almuerzo' => 'pollo con arroz',
            'cena' => 'ensalada y atún',
        ],
    ])->assertSessionHasNoErrors();

    // Solo se pidió la cena, y el desayuno y el almuerzo son los mismos registros.
    Http::assertSent(fn ($peticion) => str_contains($peticion->data()['messages'][1]['content'], '### cena')
        && ! str_contains($peticion->data()['messages'][1]['content'], '### desayuno'));

    expect($registroDiario->planesComida()->count())->toBe(3)
        ->and($registroDiario->planesComida()->pluck('id', 'tipo_comida')->only(['desayuno', 'almuerzo'])->all())
        ->toBe($idsDeLaManana);
});

test('pressing generate with nothing new comes back with a message instead of calling the provider', function () {
    fingirRespuestaDeLaIa();
    $usuario = usuarioDelPlan();
    $registroDiario = planDiarioDeHoy($usuario);

    $this->actingAs($usuario)->post(route('planes.distribucion', $registroDiario), [
        'ingredientes' => ['desayuno' => 'tengo dos huevos y media palta'],
    ]);

    Http::assertSentCount(1);

    $this->actingAs($usuario)->post(route('planes.distribucion', $registroDiario), [
        'ingredientes' => ['desayuno' => 'tengo dos huevos y media palta'],
    ])
        ->assertRedirect(route('planes.show', $registroDiario))
        ->assertSessionHas('error');

    Http::assertSentCount(1);
});

test('rehacer regenerates just that meal', function () {
    fingirRespuestaDeLaIa();
    $usuario = usuarioDelPlan();
    $registroDiario = planDiarioDeHoy($usuario);

    $this->actingAs($usuario)->post(route('planes.distribucion', $registroDiario), [
        'ingredientes' => ['desayuno' => 'dos huevos', 'almuerzo' => 'pollo con arroz'],
    ]);

    $idAlmuerzo = $registroDiario->planesComida()->where('tipo_comida', 'almuerzo')->value('id');

    fingirRespuestaDeLaIa();

    $this->actingAs($usuario)->post(route('planes.distribucion', $registroDiario), [
        'ingredientes' => ['desayuno' => 'dos huevos', 'almuerzo' => 'pollo con arroz'],
        'rehacer' => 'desayuno',
    ])->assertSessionHas('status', 'distribucion-generada');

    expect($registroDiario->planesComida()->count())->toBe(2)
        ->and($registroDiario->planesComida()->where('tipo_comida', 'almuerzo')->value('id'))->toBe($idAlmuerzo);
});

test('an empty ingredients payload is rejected by validation', function () {
    Http::fake();
    $usuario = usuarioDelPlan();
    $registroDiario = planDiarioDeHoy($usuario);

    $this->actingAs($usuario)->post(route('planes.distribucion', $registroDiario), [
        'ingredientes' => ['desayuno' => '', 'almuerzo' => '', 'cena' => ''],
    ])->assertSessionHasErrors('ingredientes');

    Http::assertNothingSent();
    expect(PlanComida::count())->toBe(0);
});

test('an unknown meal type in the payload is simply ignored', function () {
    fingirRespuestaDeLaIa();
    $usuario = usuarioDelPlan();
    $registroDiario = planDiarioDeHoy($usuario);

    $this->actingAs($usuario)->post(route('planes.distribucion', $registroDiario), [
        'ingredientes' => ['desayuno' => 'dos huevos', 'merienda' => 'unas galletas'],
    ])->assertSessionHasNoErrors();

    expect($registroDiario->planesComida()->pluck('tipo_comida')->all())->toBe(['desayuno']);
});

test('a provider failure comes back as a message, not a 500', function () {
    Http::fake(['api.openai.com/*' => Http::response(['error' => ['message' => 'overloaded']], 529)]);
    $usuario = usuarioDelPlan();
    $registroDiario = planDiarioDeHoy($usuario);

    $response = $this->actingAs($usuario)->post(route('planes.distribucion', $registroDiario), [
        'ingredientes' => ['cena' => 'lentejas y verduras'],
    ]);

    $response->assertRedirect(route('planes.show', $registroDiario))->assertSessionHas('error');

    // El texto sí se guarda aunque la generación falle: no se pierde lo escrito.
    expect($registroDiario->fresh()->ingredientes_cena)->toBe('lentejas y verduras')
        ->and($registroDiario->planesComida()->count())->toBe(0);
});

test('generating a distribution without a calculator result redirects to the calculator', function () {
    Http::fake();
    $usuario = usuarioDelPlan(['grasa_factor' => null]);
    $registroDiario = planDiarioDeHoy($usuario);

    $response = $this->actingAs($usuario)->post(route('planes.distribucion', $registroDiario), [
        'ingredientes' => ['desayuno' => 'pollo con ensalada'],
    ]);

    $response->assertRedirect(route('calculadora.edit'))->assertSessionHas('error');

    Http::assertNothingSent();
});

test('a user cannot generate a distribution on another users plan', function () {
    Http::fake();
    $registroDiario = planDiarioDeHoy(usuarioDelPlan());

    $this->actingAs(usuarioDelPlan())
        ->post(route('planes.distribucion', $registroDiario), [
            'ingredientes' => ['desayuno' => 'pollo del otro usuario'],
        ])
        ->assertForbidden();

    Http::assertNothingSent();
    expect($registroDiario->fresh()->ingredientes_desayuno)->toBeNull();
});

test('the weight of the day is captured inside the daily plan', function () {
    $usuario = usuarioDelPlan();
    $registroDiario = planDiarioDeHoy($usuario);

    $this->actingAs($usuario)->get(route('planes.show', $registroDiario))
        ->assertOk()
        ->assertSee('Tu peso hoy (kg)');

    $this->actingAs($usuario)->post(route('planes.peso', $registroDiario), ['peso_kg' => 79.4])
        ->assertRedirect(route('planes.show', $registroDiario));

    expect((float) $registroDiario->fresh()->peso_kg)->toBe(79.4);
});

test('reporting activity from the plan page comes back to the plan page', function () {
    $usuario = usuarioDelPlan();
    $registroDiario = planDiarioDeHoy($usuario);

    $response = $this->actingAs($usuario)
        ->from(route('planes.show', $registroDiario))
        ->post(route('actividades.store', $registroDiario), [
            'tipo_actividad' => 'trote',
            'duracion_min' => 30,
            'calorias_dispositivo' => 300,
            'fuente' => 'dispositivo',
        ]);

    $response->assertRedirect(route('planes.show', $registroDiario))->assertSessionHas('status', 'actividad-guardada');

    // 300 × 0.85 (factor de la tabla para trote) = 255 kcal ajustadas.
    expect((float) $registroDiario->fresh()->calorias_actividad_ajustada)->toBe(255.0);

    $this->actingAs($usuario)->get(route('planes.show', $registroDiario))
        ->assertOk()
        ->assertSee('trote');
});

test('the suggested activity is derived from the calculator result', function () {
    $usuario = usuarioDelPlan();
    $registroDiario = planDiarioDeHoy($usuario);

    // Mantenimiento 2640 − objetivo 2112 = 528 de déficit; el 40% son 211 kcal.
    $this->actingAs($usuario)->get(route('planes.show', $registroDiario))
        ->assertOk()
        ->assertSee('211')
        ->assertSee('caminata')
        ->assertSee('trote');
});

test('closing the day from the plan page comes back to the plan page and freezes it', function () {
    fingirRespuestaDeLaIa();
    $usuario = usuarioDelPlan();
    $registroDiario = planDiarioDeHoy($usuario);

    $this->actingAs($usuario)->post(route('planes.distribucion', $registroDiario), [
        'ingredientes' => ['desayuno' => 'dos huevos y media palta'],
    ]);

    $response = $this->actingAs($usuario)
        ->from(route('planes.show', $registroDiario))
        ->post(route('cierre.cerrar', $registroDiario));

    $response->assertRedirect(route('planes.show', $registroDiario))->assertSessionHas('status', 'dia-cerrado');

    $registroDiario->refresh();

    expect($registroDiario->cerrado)->toBeTrue()
        ->and($registroDiario->cerrado_en)->not->toBeNull();

    $this->actingAs($usuario)->get(route('planes.show', $registroDiario))
        ->assertOk()
        ->assertSee('Resumen del cierre')
        ->assertSee('Reabrir mi día');
});

test('reopening the day from the plan page comes back to the plan page', function () {
    $usuario = usuarioDelPlan();
    $registroDiario = RegistroDiario::factory()->for($usuario, 'usuario')->cerrado()->create([
        'fecha' => now()->toDateString(),
    ]);

    $response = $this->actingAs($usuario)
        ->from(route('planes.show', $registroDiario))
        ->post(route('cierre.reabrir', $registroDiario));

    $response->assertRedirect(route('planes.show', $registroDiario))->assertSessionHas('status', 'dia-reabierto');

    expect($registroDiario->fresh()->cerrado)->toBeFalse();
});
