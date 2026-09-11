<?php

use App\Models\ActividadFisica;
use App\Models\ComidaReal;
use App\Models\PlanComida;
use App\Models\RecomendacionSistema;
use App\Models\RegistroDiario;
use App\Models\User;
use App\Services\MealPlanGeneratorService;
use App\Services\NutritionCalculatorService;
use App\Services\RepartoComidasService;

/**
 * Las acciones del plan diario que no son "ajustar y cerrar" (CLAUDE.md
 * secciones 5.5, 5.14 y 5.16): el reparto automático entre comidas, reabrir una
 * comida ya reportada, reiniciar el día y eliminarlo.
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

// / ── Reparto automático del día (sección 5.14) ──────────────────────────────

test('el plan diario enseña el reparto que se calculó, no un formulario para teclearlo', function () {
    $usuario = usuarioDeAcciones();
    $dia = diaDeAcciones($usuario);

    $this->actingAs($usuario)->get(route('planes.show', $dia))
        ->assertOk()
        ->assertSee('30% desayuno · 40% almuerzo · 30% cena')
        // El panel de porcentajes editables se retiró: repartir el día es una
        // decisión nutricional, no una preferencia de interfaz.
        ->assertDontSee('Reparto del día')
        ->assertDontSee('Aplicar reparto')
        ->assertDontSee('Guardar como mi reparto habitual');
});

test('la ruta del reparto manual ya no existe', function () {
    $usuario = usuarioDeAcciones();
    $dia = diaDeAcciones($usuario);

    $this->actingAs($usuario)->post("/planes/{$dia->id}/reparto", [
        'reparto' => ['desayuno' => 20, 'almuerzo' => 45, 'cena' => 35],
    ])->assertNotFound();
});

test('registrar actividad desplaza el reparto y el plan diario lo explica', function () {
    $usuario = usuarioDeAcciones();
    $dia = diaDeAcciones($usuario);

    $this->actingAs($usuario)->post(route('actividades.store', $dia), [
        'tipo_actividad' => 'trote',
        'duracion_min' => 45,
        'calorias_dispositivo' => 400,
        'fuente' => 'dispositivo',
    ])->assertSessionHas('status', 'actividad-guardada');

    $reparto = app(RepartoComidasService::class)->paraElDia($dia->fresh());

    expect(array_sum($reparto))->toEqualWithDelta(1.0, 0.0001)
        ->and($reparto)->not->toBe(MealPlanGeneratorService::DISTRIBUCION_COMIDAS);

    $this->actingAs($usuario)->get(route('planes.show', $dia))
        ->assertOk()
        ->assertSee('por tu actividad de hoy');
});

// ── Reabrir una comida reportada (sección 5.5) ────────────────────────────

test('la tarjeta de cada comida muestra lo que se contestó en ella', function () {
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
        ->assertSee('Lo que comiste')
        ->assertSee('Me comí un sándwich extra.')
        ->assertSee('Reabrir desayuno');
});

test('reabrir la comida borra lo reportado y devuelve la pregunta', function () {
    $usuario = usuarioDeAcciones();
    $dia = diaDeAcciones($usuario);
    $plan = comidaPlanificada($dia);

    ComidaReal::factory()->for($plan, 'planComida')->create(['calorias_reales' => 640]);

    $this->actingAs($usuario)
        ->post(route('comidas.reabrir', [$dia, 'desayuno']))
        ->assertRedirect(route('planes.show', $dia))
        ->assertSessionHas('status', 'comida-reabierta');

    expect(ComidaReal::where('plan_comida_id', $plan->id)->count())->toBe(0)
        // Y calorias_consumidas del día vuelve a reflejar lo que queda.
        ->and((float) $dia->fresh()->calorias_consumidas)->toBe(0.0);

    // La comida vuelve a estar "planificada", así que se vuelve a preguntar.
    $this->actingAs($usuario)->get(route('planes.show', $dia))
        ->assertSee('Cumplí lo sugerido');
});

test('reabrir el día deja volver a reportar cada comida', function () {
    $usuario = usuarioDeAcciones();
    $dia = diaDeAcciones($usuario, ['cerrado' => true, 'cerrado_en' => now()]);
    $plan = comidaPlanificada($dia);

    ComidaReal::factory()->for($plan, 'planComida')->create(['calorias_reales' => 640]);

    // Con el día cerrado el reporte se ve, pero no se puede reabrir la comida.
    $this->actingAs($usuario)->get(route('planes.show', $dia))
        ->assertSee('Lo que comiste')
        ->assertDontSee('Reabrir desayuno');

    $this->actingAs($usuario)->post(route('cierre.reabrir', $dia))
        ->assertSessionHas('status', 'dia-reabierto');

    $this->actingAs($usuario)->get(route('planes.show', $dia))
        ->assertSee('Reabrir desayuno');
});

test('no se puede reabrir una comida de un día cerrado sin reabrir el día antes', function () {
    $usuario = usuarioDeAcciones();
    $dia = diaDeAcciones($usuario, ['cerrado' => true, 'cerrado_en' => now()]);
    $plan = comidaPlanificada($dia);

    ComidaReal::factory()->for($plan, 'planComida')->create(['calorias_reales' => 640]);

    $this->actingAs($usuario)
        ->post(route('comidas.reabrir', [$dia, 'desayuno']))
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

test('reiniciar deja el día con el reparto balanceado, porque borra la actividad', function () {
    $usuario = usuarioDeAcciones();
    $dia = diaDeAcciones($usuario);

    ActividadFisica::factory()->for($dia, 'registroDiario')->create(['calorias_ajustadas' => 400]);

    $this->actingAs($usuario)->post(route('planes.resetear', $dia));

    // El reparto ya no se persiste: se deriva del día (sección 5.14). Vaciar el
    // día se lleva sus actividades, así que vuelve al balanceado por sí solo.
    expect(app(RepartoComidasService::class)->paraElDia($dia->fresh()))
        ->toBe(MealPlanGeneratorService::DISTRIBUCION_COMIDAS);
});

test('reiniciar solo vale para el día de hoy', function () {
    $usuario = usuarioDeAcciones();
    $ayer = diaDeAcciones($usuario, ['fecha' => now()->subDay()->toDateString()]);
    $plan = comidaPlanificada($ayer);
    ComidaReal::factory()->for($plan, 'planComida')->create();

    // Un día pasado ya no puede volver a vivirse: vaciarlo solo borraría
    // historial, así que ni el botón se pinta ni la ruta lo permite.
    $this->actingAs($usuario)->get(route('planes.show', $ayer))
        ->assertOk()
        ->assertDontSee('Reiniciar este día')
        ->assertSee('Reiniciar solo está disponible en el día de hoy.');

    $this->actingAs($usuario)
        ->post(route('planes.resetear', $ayer))
        ->assertRedirect(route('planes.show', $ayer))
        ->assertSessionHas('error');

    expect($ayer->fresh()->planesComida()->count())->toBe(1)
        ->and(ComidaReal::where('plan_comida_id', $plan->id)->count())->toBe(1);
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
