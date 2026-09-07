<?php

use App\Models\ActividadFisica;
use App\Models\ComidaReal;
use App\Models\PlanComida;
use App\Models\RegistroDiario;
use App\Models\User;
use Illuminate\Support\Facades\Http;

/**
 * Cierre del día dentro del plan diario (CLAUDE.md secciones 4.5 y 4.16).
 *
 * Same profile as tests/Unit/DailyClosureServiceTest.php:
 * objetivo = 80 * 22 * 1.5 * 0.8 = 2112 kcal, proteína objetivo = 160 g.
 */
function usuarioParaCierre(array $sobrescribir = []): User
{
    return User::factory()->create(array_merge([
        'peso_kg' => 80,
        'estatura_m' => 1.75,
        'edad' => 35,
        'sexo' => 'masculino',
        'nivel_actividad' => 1.5,
        'tipo_deficit' => 'porcentaje',
        'valor_deficit' => 0.2,
        'proteina_factor' => 2.0,
        'grasa_factor' => 0.8,
    ], $sobrescribir));
}

/**
 * Today's record with breakfast already logged (600 kcal / 45 g de proteína),
 * lunch still pending, and 340 kcal of adjusted activity.
 */
function diaDeHoyConDesayunoRegistrado(User $usuario): RegistroDiario
{
    $registroDiario = RegistroDiario::factory()->for($usuario, 'usuario')->create([
        'fecha' => now()->toDateString(),
    ]);

    $desayuno = PlanComida::factory()->for($registroDiario, 'registroDiario')->create([
        'tipo_comida' => 'desayuno',
        'calorias_estimadas' => 528,
    ]);

    ComidaReal::factory()->for($desayuno, 'planComida')->create([
        'calorias_reales' => 600,
        'proteina_g' => 45,
    ]);

    PlanComida::factory()->for($registroDiario, 'registroDiario')->create([
        'tipo_comida' => 'almuerzo',
        'calorias_estimadas' => 845,
        'proteina_g' => 60,
        'grasa_g' => 25,
        'carbohidratos_g' => 70,
    ]);

    ActividadFisica::factory()->for($registroDiario, 'registroDiario')->create([
        'tipo' => 'caminata',
        'calorias_dispositivo' => 400,
        'factor_correccion' => 0.85,
        'calorias_ajustadas' => 340,
    ]);

    return $registroDiario;
}

test('guests cannot see or trigger the daily closure', function () {
    $registroDiario = RegistroDiario::factory()->create();

    $this->get(route('planes.show', $registroDiario))->assertRedirect('/login');
    $this->post(route('cierre.cerrar', $registroDiario))->assertRedirect('/login');
    $this->post(route('cierre.reabrir', $registroDiario))->assertRedirect('/login');
});

test('closing the day freezes it and the summary shows the five closure points', function () {
    $usuario = usuarioParaCierre();
    $registroDiario = diaDeHoyConDesayunoRegistrado($usuario);

    $response = $this->actingAs($usuario)->post(route('cierre.cerrar', $registroDiario));

    $response->assertRedirect(route('planes.show', $registroDiario))->assertSessionHas('status', 'dia-cerrado');

    $registroDiario->refresh();

    // objetivo 2112 · consumidas 600 · actividad 340 · déficit 2112-600+340 = 1852
    // proteína 45 g de 160 g -> 28.1 %
    expect($registroDiario->cerrado)->toBeTrue()
        ->and($registroDiario->cerrado_en)->not->toBeNull()
        ->and((float) $registroDiario->calorias_objetivo_dia)->toBe(2112.0)
        ->and((float) $registroDiario->calorias_consumidas)->toBe(600.0)
        ->and((float) $registroDiario->calorias_actividad_ajustada)->toBe(340.0)
        ->and((float) $registroDiario->deficit_diario)->toBe(1852.0)
        ->and((float) $registroDiario->proteina_objetivo_g)->toBe(160.0)
        ->and((float) $registroDiario->proteina_consumida_g)->toBe(45.0);

    $this->actingAs($usuario)->get(route('planes.show', $registroDiario))
        ->assertOk()
        ->assertSee('2.112')       // calorías objetivo
        ->assertSee('600')         // calorías consumidas
        ->assertSee('340')         // gasto por actividad ajustado
        ->assertSee('1.852')       // déficit estimado
        ->assertSee('28,1%')       // cumplimiento de proteína
        ->assertSee('Resumen del cierre')
        ->assertSee('Recomendaciones');
});

test('closing an already closed day is rejected without touching its figures', function () {
    $usuario = usuarioParaCierre();
    $registroDiario = diaDeHoyConDesayunoRegistrado($usuario);

    $this->actingAs($usuario)->post(route('cierre.cerrar', $registroDiario));

    $cerradoEn = $registroDiario->fresh()->cerrado_en;

    $this->actingAs($usuario)->post(route('cierre.cerrar', $registroDiario))
        ->assertRedirect(route('planes.show', $registroDiario))
        ->assertSessionHas('error');

    expect($registroDiario->fresh()->cerrado_en->equalTo($cerradoEn))->toBeTrue()
        ->and((float) $registroDiario->fresh()->deficit_diario)->toBe(1852.0);
});

test('a closed day cannot receive a new comida real', function () {
    $usuario = usuarioParaCierre();
    $registroDiario = diaDeHoyConDesayunoRegistrado($usuario);
    $almuerzo = $registroDiario->planesComida()->where('tipo_comida', 'almuerzo')->first();

    $this->actingAs($usuario)->post(route('cierre.cerrar', $registroDiario));

    $this->actingAs($usuario)->post(route('comida-real.store', $almuerzo), [
        'calorias_reales' => 800,
        'proteina_g' => 60,
        'grasa_g' => 25,
        'carbohidratos_g' => 70,
    ])->assertRedirect(route('planes.show', $registroDiario))->assertSessionHas('error');

    expect($almuerzo->fresh()->comidaReal)->toBeNull()
        ->and((float) $registroDiario->fresh()->calorias_consumidas)->toBe(600.0);

    // The form is closed too, not just the write endpoint.
    $this->actingAs($usuario)->get(route('comida-real.create', $almuerzo))
        ->assertRedirect(route('planes.show', $registroDiario))
        ->assertSessionHas('error');
});

test('a closed day cannot receive a new actividad fisica either', function () {
    $usuario = usuarioParaCierre();
    $registroDiario = diaDeHoyConDesayunoRegistrado($usuario);

    $this->actingAs($usuario)->post(route('cierre.cerrar', $registroDiario));

    $this->actingAs($usuario)->post(route('actividades.store', $registroDiario), [
        'tipo_actividad' => 'trote',
        'duracion_min' => 30,
        'calorias_dispositivo' => 300,
        'fuente' => 'dispositivo',
    ])->assertRedirect(route('planes.show', $registroDiario))->assertSessionHas('error');

    expect($registroDiario->fresh()->actividadesFisicas()->count())->toBe(1)
        ->and((float) $registroDiario->fresh()->calorias_actividad_ajustada)->toBe(340.0);
});

test('after an explicit reopen the day accepts a comida real again', function () {
    $usuario = usuarioParaCierre();
    $registroDiario = diaDeHoyConDesayunoRegistrado($usuario);
    $almuerzo = $registroDiario->planesComida()->where('tipo_comida', 'almuerzo')->first();

    $this->actingAs($usuario)->post(route('cierre.cerrar', $registroDiario));

    $this->actingAs($usuario)->post(route('cierre.reabrir', $registroDiario))
        ->assertRedirect(route('planes.show', $registroDiario))
        ->assertSessionHas('status', 'dia-reabierto');

    expect($registroDiario->fresh()->cerrado)->toBeFalse();

    $this->actingAs($usuario)->post(route('comida-real.store', $almuerzo), [
        'calorias_reales' => 800,
        'proteina_g' => 60,
        'grasa_g' => 25,
        'carbohidratos_g' => 70,
    ])->assertRedirect(route('planes.show', $registroDiario));

    expect($almuerzo->fresh()->comidaReal)->not->toBeNull()
        ->and((float) $registroDiario->fresh()->calorias_consumidas)->toBe(1400.0);
});

test('a user cannot close or reopen another users day', function () {
    $usuario = usuarioParaCierre();
    $otroUsuario = usuarioParaCierre();
    $registroDiario = diaDeHoyConDesayunoRegistrado($otroUsuario);

    $this->actingAs($usuario)->post(route('cierre.cerrar', $registroDiario))->assertForbidden();

    $this->actingAs($otroUsuario)->post(route('cierre.cerrar', $registroDiario));

    $this->actingAs($usuario)->post(route('cierre.reabrir', $registroDiario))->assertForbidden();

    expect($registroDiario->fresh()->cerrado)->toBeTrue();
});

test('closing the day requires the nutritional profile to be complete', function () {
    $usuario = usuarioParaCierre(['proteina_factor' => null]);
    $registroDiario = diaDeHoyConDesayunoRegistrado($usuario);

    $this->actingAs($usuario)->post(route('cierre.cerrar', $registroDiario))
        ->assertRedirect(route('calculadora.edit'))
        ->assertSessionHas('error');

    expect($registroDiario->fresh()->cerrado)->toBeFalse();

    $this->actingAs($usuario)->get(route('planes.show', $registroDiario))
        ->assertOk()
        ->assertSee('Completa tu Calculadora Déficit');
});

test('the summary of an open day is a preview that does not close it', function () {
    $usuario = usuarioParaCierre();
    $registroDiario = diaDeHoyConDesayunoRegistrado($usuario);

    $this->actingAs($usuario)->get(route('planes.show', $registroDiario))
        ->assertOk()
        ->assertSee('Vista previa (todavía sin cerrar)')
        ->assertSee('1.852');

    expect($registroDiario->fresh()->cerrado)->toBeFalse()
        ->and($registroDiario->fresh()->deficit_diario)->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Feedback de cumplimiento (CLAUDE.md sección 4.16)
|--------------------------------------------------------------------------
*/

test('the closure form asks whether each planned meal was fulfilled', function () {
    $usuario = usuarioParaCierre();
    $registroDiario = diaDeHoyConDesayunoRegistrado($usuario);

    $this->actingAs($usuario)->get(route('planes.show', $registroDiario))
        ->assertOk()
        ->assertSee('¿Cumpliste con lo sugerido?')
        ->assertSee('Sí, comí lo que se sugirió')
        // El desayuno ya está registrado: no se vuelve a preguntar por él.
        ->assertSee('feedback[almuerzo][texto]', escape: false)
        ->assertDontSee('feedback[desayuno][texto]', escape: false);
});

test('marking a meal as fulfilled registers it with the planned macros', function () {
    Http::fake();
    $usuario = usuarioParaCierre();
    $registroDiario = diaDeHoyConDesayunoRegistrado($usuario);
    $almuerzo = $registroDiario->planesComida()->where('tipo_comida', 'almuerzo')->first();

    $this->actingAs($usuario)->post(route('cierre.cerrar', $registroDiario), [
        'feedback' => ['almuerzo' => ['cumplio' => '1']],
    ])->assertSessionHas('status', 'dia-cerrado');

    $comidaReal = $almuerzo->fresh()->comidaReal;

    expect($comidaReal)->not->toBeNull()
        ->and((float) $comidaReal->calorias_reales)->toBe(845.0)
        ->and((float) $comidaReal->proteina_g)->toBe(60.0);

    // 600 del desayuno + 845 del almuerzo confirmado.
    expect((float) $registroDiario->fresh()->calorias_consumidas)->toBe(1445.0);

    // Confirmar con la casilla no cuesta ninguna llamada al proveedor.
    Http::assertNothingSent();
});

test('describing what was actually eaten is interpreted by the AI and consolidated into the closure', function () {
    config([
        'services.gemini.key' => 'clave-de-prueba',
        'services.gemini.model' => 'gemini-2.5-flash',
        'services.gemini.endpoint' => 'https://generativelanguage.googleapis.com/v1beta/models',
    ]);

    Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
        'candidates' => [[
            'content' => ['parts' => [['text' => json_encode(['comidas' => [[
                'tipo_comida' => 'almuerzo',
                'descripcion' => 'Sándwich de pollo y gaseosa',
                'preparacion' => '',
                'notas' => 'Asumí una lata de 350 ml.',
                'ingredientes' => [
                    ['nombre' => 'Sándwich de pollo', 'porcion' => '1 unidad', 'cantidad_g' => 220, 'calorias' => 480, 'proteina_g' => 28, 'grasa_g' => 18, 'carbohidratos_g' => 50],
                    ['nombre' => 'Gaseosa', 'porcion' => '1 lata', 'cantidad_g' => 350, 'calorias' => 140, 'proteina_g' => 0, 'grasa_g' => 0, 'carbohidratos_g' => 37],
                ],
            ]]])]]],
            'finishReason' => 'STOP',
        ]],
    ])]);

    $usuario = usuarioParaCierre();
    $registroDiario = diaDeHoyConDesayunoRegistrado($usuario);
    $almuerzo = $registroDiario->planesComida()->where('tipo_comida', 'almuerzo')->first();

    $this->actingAs($usuario)->post(route('cierre.cerrar', $registroDiario), [
        'feedback' => ['almuerzo' => ['texto' => 'al final me comí un sándwich de pollo y una gaseosa']],
    ])->assertSessionHas('status', 'dia-cerrado');

    $comidaReal = $almuerzo->fresh()->comidaReal;

    // 480 + 140 = 620 kcal, sumados en PHP a partir de los ingredientes.
    expect($comidaReal)->not->toBeNull()
        ->and((float) $comidaReal->calorias_reales)->toBe(620.0)
        ->and((float) $comidaReal->proteina_g)->toBe(28.0)
        ->and($comidaReal->notas)->toContain('sándwich de pollo');

    // 600 del desayuno + 620 del almuerzo real: es lo que congela el cierre.
    expect((float) $registroDiario->fresh()->calorias_consumidas)->toBe(1220.0)
        ->and((float) $registroDiario->fresh()->deficit_diario)->toBe(2112.0 - 1220.0 + 340.0);
});

test('a provider failure during the closure feedback leaves the day open instead of half closed', function () {
    config([
        'services.gemini.key' => 'clave-de-prueba',
        'services.gemini.endpoint' => 'https://generativelanguage.googleapis.com/v1beta/models',
    ]);

    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['error' => ['message' => 'overloaded']], 529)]);

    $usuario = usuarioParaCierre();
    $registroDiario = diaDeHoyConDesayunoRegistrado($usuario);
    $almuerzo = $registroDiario->planesComida()->where('tipo_comida', 'almuerzo')->first();

    $this->actingAs($usuario)->post(route('cierre.cerrar', $registroDiario), [
        'feedback' => ['almuerzo' => ['cumplio' => '1', 'texto' => 'no me acuerdo bien']],
    ])
        ->assertRedirect(route('planes.show', $registroDiario))
        ->assertSessionHas('error');

    expect($registroDiario->fresh()->cerrado)->toBeFalse()
        ->and($almuerzo->fresh()->comidaReal)->toBeNull();
});

test('closing without any feedback still works and leaves the unlogged meals out', function () {
    Http::fake();
    $usuario = usuarioParaCierre();
    $registroDiario = diaDeHoyConDesayunoRegistrado($usuario);

    $this->actingAs($usuario)->post(route('cierre.cerrar', $registroDiario))
        ->assertSessionHas('status', 'dia-cerrado');

    expect((float) $registroDiario->fresh()->calorias_consumidas)->toBe(600.0);

    Http::assertNothingSent();
});
