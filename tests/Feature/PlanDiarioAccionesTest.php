<?php

use App\Models\ActividadFisica;
use App\Models\ComidaReal;
use App\Models\PlanComida;
use App\Models\RecomendacionSistema;
use App\Models\RegistroDiario;
use App\Models\User;
use App\Services\NutritionCalculatorService;
use App\Services\RepartoComidasService;

/**
 * Las acciones del plan diario que no son "generar y cerrar" (CLAUDE.md
 * secciones 5.5, 5.14 y 5.16): cambiar el reparto entre comidas, cambiar una
 * respuesta ya dada en el cierre, reiniciar el día y eliminarlo.
 */
function usuarioDeAcciones(array $overrides = []): User
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

function diaDeAcciones(User $usuario, array $overrides = []): RegistroDiario
{
    return RegistroDiario::factory()->for($usuario, 'usuario')->create(array_merge([
        'fecha' => now()->toDateString(),
    ], $overrides));
}

function comidaPlanificada(RegistroDiario $dia, string $tipo = 'desayuno'): PlanComida
{
    return PlanComida::factory()->for($dia, 'registroDiario')->create([
        'tipo_comida' => $tipo,
        'descripcion' => "Plan de {$tipo}",
        'calorias_estimadas' => 500,
        'proteina_g' => 30,
        'grasa_g' => 15,
        'carbohidratos_g' => 55,
    ]);
}

// ── Reparto por día (sección 5.14) ─────────────────────────────────────────

test('el reparto del día se guarda y redimensiona los objetivos por comida', function () {
    $usuario = usuarioDeAcciones();
    $dia = diaDeAcciones($usuario);

    $this->actingAs($usuario)
        ->post(route('planes.reparto', $dia), [
            'reparto' => ['desayuno' => 20, 'almuerzo' => 45, 'cena' => 35],
        ])
        ->assertRedirect(route('planes.show', $dia))
        ->assertSessionHas('status', 'reparto-guardado');

    expect($dia->fresh()->reparto_comidas)
        ->toBe(['desayuno' => 0.2, 'almuerzo' => 0.45, 'cena' => 0.35])
        // No se ha adoptado como habitual: no se pidió.
        ->and($usuario->fresh()->reparto_comidas)->toBeNull();

    $this->actingAs($usuario)->get(route('planes.show', $dia))
        ->assertOk()
        ->assertSee('20% · 45% · 35%');
});

test('el reparto se puede adoptar como habitual del usuario', function () {
    $usuario = usuarioDeAcciones();
    $dia = diaDeAcciones($usuario);

    $this->actingAs($usuario)->post(route('planes.reparto', $dia), [
        'reparto' => ['desayuno' => 30, 'almuerzo' => 30, 'cena' => 40],
        'como_habitual' => '1',
    ])->assertSessionHas('status', 'reparto-guardado');

    expect($usuario->fresh()->reparto_comidas)
        ->toBe(['desayuno' => 0.3, 'almuerzo' => 0.3, 'cena' => 0.4]);

    // Y un día nuevo, sin reparto propio, hereda el habitual.
    $otroDia = diaDeAcciones($usuario, ['fecha' => now()->addDay()->toDateString()]);

    expect(app(RepartoComidasService::class)->paraElDia($otroDia)['cena'])->toBe(0.4);
});

test('un reparto que no suma 100 se rechaza con el error junto al campo', function () {
    $usuario = usuarioDeAcciones();
    $dia = diaDeAcciones($usuario);

    $this->actingAs($usuario)
        ->post(route('planes.reparto', $dia), [
            'reparto' => ['desayuno' => 25, 'almuerzo' => 40, 'cena' => 40],
        ])
        ->assertSessionHasErrors('reparto');

    expect($dia->fresh()->reparto_comidas)->toBeNull();
});

test('no se puede tocar el reparto del plan de otra persona', function () {
    $dia = diaDeAcciones(usuarioDeAcciones());

    $this->actingAs(usuarioDeAcciones())
        ->post(route('planes.reparto', $dia), [
            'reparto' => ['desayuno' => 20, 'almuerzo' => 45, 'cena' => 35],
        ])
        ->assertForbidden();
});

// ── Cambiar una respuesta del cierre (sección 5.5) ─────────────────────────

test('el cierre muestra lo que se respondió por cada comida', function () {
    $usuario = usuarioDeAcciones();
    $dia = diaDeAcciones($usuario);
    $plan = comidaPlanificada($dia);

    ComidaReal::factory()->for($plan, 'planComida')->create([
        'calorias_reales' => 640,
        'proteina_g' => 35,
        'grasa_g' => 20,
        'carbohidratos_g' => 60,
        'notas' => 'Me comí un sándwich extra.',
    ]);

    $this->actingAs($usuario)->get(route('planes.show', $dia))
        ->assertOk()
        ->assertSee('Lo que respondiste')
        ->assertSee('Me comí un sándwich extra.')
        ->assertSee('Cambiar mi respuesta');
});

test('cambiar la respuesta borra la comida registrada y devuelve la pregunta', function () {
    $usuario = usuarioDeAcciones();
    $dia = diaDeAcciones($usuario);
    $plan = comidaPlanificada($dia);

    ComidaReal::factory()->for($plan, 'planComida')->create(['calorias_reales' => 640]);

    $this->actingAs($usuario)
        ->delete(route('comida-real.destroy', $plan))
        ->assertRedirect(route('planes.show', $dia))
        ->assertSessionHas('status', 'comida-real-eliminada');

    expect(ComidaReal::where('plan_comida_id', $plan->id)->count())->toBe(0)
        // Y calorias_consumidas del día vuelve a reflejar lo que queda.
        ->and((float) $dia->fresh()->calorias_consumidas)->toBe(0.0);

    // La comida vuelve a estar "planificada", así que el cierre la pregunta.
    $this->actingAs($usuario)->get(route('planes.show', $dia))
        ->assertSee('¿Cumpliste con lo sugerido?');
});

test('reabrir el día deja volver a responder por cada comida', function () {
    $usuario = usuarioDeAcciones();
    $dia = diaDeAcciones($usuario, ['cerrado' => true, 'cerrado_en' => now()]);
    $plan = comidaPlanificada($dia);

    ComidaReal::factory()->for($plan, 'planComida')->create(['calorias_reales' => 640]);

    // Con el día cerrado la respuesta se ve, pero no se puede cambiar.
    $this->actingAs($usuario)->get(route('planes.show', $dia))
        ->assertSee('Lo que respondiste')
        ->assertDontSee('Cambiar mi respuesta');

    $this->actingAs($usuario)->post(route('cierre.reabrir', $dia))
        ->assertSessionHas('status', 'dia-reabierto');

    $this->actingAs($usuario)->get(route('planes.show', $dia))
        ->assertSee('Cambiar mi respuesta');
});

test('no se puede borrar lo registrado de un día cerrado sin reabrirlo antes', function () {
    $usuario = usuarioDeAcciones();
    $dia = diaDeAcciones($usuario, ['cerrado' => true, 'cerrado_en' => now()]);
    $plan = comidaPlanificada($dia);

    ComidaReal::factory()->for($plan, 'planComida')->create(['calorias_reales' => 640]);

    $this->actingAs($usuario)
        ->delete(route('comida-real.destroy', $plan))
        ->assertSessionHas('error');

    expect(ComidaReal::where('plan_comida_id', $plan->id)->count())->toBe(1);
});

// ── Reiniciar y eliminar el día (sección 5.16) ─────────────────────────────

test('reiniciar el día lo vacía entero y lo deja abierto', function () {
    $usuario = usuarioDeAcciones();
    $dia = diaDeAcciones($usuario, [
        'ingredientes_desayuno' => 'huevos y arepa',
        'peso_kg' => 80.4,
        'cerrado' => true,
        'cerrado_en' => now(),
        'deficit_diario' => 300,
    ]);

    $plan = comidaPlanificada($dia);
    ComidaReal::factory()->for($plan, 'planComida')->create();
    ActividadFisica::factory()->for($dia, 'registroDiario')->create();
    RecomendacionSistema::factory()->for($dia, 'registroDiario')->create();

    $this->actingAs($usuario)
        ->post(route('planes.resetear', $dia))
        ->assertRedirect(route('planes.show', $dia))
        ->assertSessionHas('status', 'plan-reiniciado');

    $dia->refresh();

    expect($dia->exists)->toBeTrue()
        ->and($dia->planesComida()->count())->toBe(0)
        ->and($dia->actividadesFisicas()->count())->toBe(0)
        ->and($dia->recomendacionesSistema()->count())->toBe(0)
        ->and(ComidaReal::where('plan_comida_id', $plan->id)->count())->toBe(0)
        ->and($dia->ingredientes_desayuno)->toBeNull()
        ->and($dia->peso_kg)->toBeNull()
        ->and($dia->deficit_diario)->toBeNull()
        ->and($dia->cerrado)->toBeFalse();
});

test('reiniciar conserva el reparto propio del día', function () {
    $usuario = usuarioDeAcciones();
    $dia = diaDeAcciones($usuario, [
        'reparto_comidas' => ['desayuno' => 0.2, 'almuerzo' => 0.45, 'cena' => 0.35],
    ]);

    $this->actingAs($usuario)->post(route('planes.resetear', $dia));

    expect($dia->fresh()->reparto_comidas['almuerzo'])->toBe(0.45);
});

test('eliminar el plan diario se lleva el día entero en cascada', function () {
    $usuario = usuarioDeAcciones();
    $dia = diaDeAcciones($usuario);
    $plan = comidaPlanificada($dia);
    ComidaReal::factory()->for($plan, 'planComida')->create();

    $this->actingAs($usuario)
        ->delete(route('planes.destroy', $dia))
        ->assertRedirect(route('planes.index'))
        ->assertSessionHas('status', 'plan-eliminado');

    expect(RegistroDiario::find($dia->id))->toBeNull()
        ->and(PlanComida::find($plan->id))->toBeNull()
        ->and(ComidaReal::where('plan_comida_id', $plan->id)->count())->toBe(0);
});

test('no se puede reiniciar ni eliminar el plan de otra persona', function () {
    $dia = diaDeAcciones(usuarioDeAcciones());
    $intruso = usuarioDeAcciones();

    $this->actingAs($intruso)->post(route('planes.resetear', $dia))->assertForbidden();
    $this->actingAs($intruso)->delete(route('planes.destroy', $dia))->assertForbidden();

    expect(RegistroDiario::find($dia->id))->not->toBeNull();
});

test('el listado ofrece eliminar cada plan', function () {
    $usuario = usuarioDeAcciones();
    $dia = diaDeAcciones($usuario);

    $this->actingAs($usuario)->get(route('planes.index'))
        ->assertOk()
        ->assertSee('Eliminar el plan del '.$dia->fecha->format('d/m/Y'));
});
