<?php

use App\Models\RegistroDiario;
use App\Models\User;
use App\Services\DailyClosureService;
use App\Services\MealDistributionService;
use App\Services\ObjetivoDelDiaService;

/**
 * El objetivo con el que se trabajó cada día queda sellado en su propio
 * RegistroDiario (CLAUDE.md sección 5.25).
 *
 * Lo que se protege: cambiar la Calculadora Déficit no puede reescribir hacia
 * atrás los objetivos de días que ya se vivieron. Un día de la semana pasada
 * planificado contra 2.112 kcal sigue midiéndose contra 2.112 kcal aunque hoy
 * el perfil diga otra cosa; solo el día de HOY acompaña al cambio.
 *
 * Perfil base: 80 kg * 22 * 1.5 * 0.8 = 2112 kcal, 160 g de proteína.
 */
function usuarioDelObjetivo(array $sobrescribir = []): User
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
        'calorias_objetivo' => 2112,
    ], $sobrescribir));
}

/**
 * Los parámetros que manda la Calculadora, con el objetivo ya bajado a 1.700.
 *
 * @return array<string, mixed>
 */
function parametrosMasAgresivos(): array
{
    return [
        'peso_kg' => '70',
        'estatura_m' => '1.75',
        'edad' => '35',
        'sexo' => 'masculino',
        'nivel_actividad' => '1.5',
        'tipo_deficit' => 'porcentaje',
        'valor_deficit' => '0.2',
        'proteina_factor' => '2.0',
        'grasa_factor' => '0.8',
    ];
}

it('sella el objetivo del día al crear el plan diario', function () {
    $usuario = usuarioDelObjetivo();

    $this->actingAs($usuario)->post(route('planes.crear'))->assertRedirect();

    $hoy = RegistroDiario::where('usuario_id', $usuario->id)->firstOrFail();

    expect((float) $hoy->calorias_objetivo_dia)->toEqualWithDelta(2112.0, 0.01)
        ->and((float) $hoy->proteina_objetivo_g)->toEqualWithDelta(160.0, 0.01)
        ->and((float) $hoy->grasa_objetivo_g)->toEqualWithDelta(64.0, 0.01)
        ->and($hoy->carbohidratos_objetivo_g)->not->toBeNull();
});

it('cambiar la Calculadora NO reescribe el objetivo de un día pasado abierto', function () {
    $usuario = usuarioDelObjetivo();

    // Un día de la semana pasada, sellado a 2.112 kcal y todavía abierto.
    $anterior = RegistroDiario::factory()->for($usuario, 'usuario')->create([
        'fecha' => now()->subDays(4)->toDateString(),
    ]);
    app(ObjetivoDelDiaService::class)->sellar($anterior);

    expect((float) $anterior->fresh()->calorias_objetivo_dia)->toEqualWithDelta(2112.0, 0.01);

    $this->actingAs($usuario)
        ->put(route('calculadora.update'), parametrosMasAgresivos())
        ->assertSessionHasNoErrors();

    // El perfil bajó, pero aquel día conserva el objetivo con el que se vivió.
    expect((float) $usuario->fresh()->calorias_objetivo)->toEqualWithDelta(1848.0, 0.01)
        ->and((float) $anterior->fresh()->calorias_objetivo_dia)->toEqualWithDelta(2112.0, 0.01);
});

it('cambiar la Calculadora SÍ actualiza el objetivo del día de hoy', function () {
    $usuario = usuarioDelObjetivo();

    $hoy = RegistroDiario::factory()->for($usuario, 'usuario')->create([
        'fecha' => now()->toDateString(),
    ]);
    app(ObjetivoDelDiaService::class)->sellar($hoy);

    $this->actingAs($usuario)
        ->put(route('calculadora.update'), parametrosMasAgresivos())
        ->assertSessionHasNoErrors();

    // 70 * 22 * 1.5 * 0.8 = 1848 kcal, 140 g de proteína.
    expect((float) $hoy->fresh()->calorias_objetivo_dia)->toEqualWithDelta(1848.0, 0.01)
        ->and((float) $hoy->fresh()->proteina_objetivo_g)->toEqualWithDelta(140.0, 0.01);
});

it('un día cerrado no se re-sella ni siquiera siendo el de hoy', function () {
    $usuario = usuarioDelObjetivo();

    $hoy = RegistroDiario::factory()->for($usuario, 'usuario')->cerrado()->create([
        'fecha' => now()->toDateString(),
        'calorias_objetivo_dia' => 2112,
        'proteina_objetivo_g' => 160,
        'grasa_objetivo_g' => 64,
        'carbohidratos_objetivo_g' => 100,
    ]);

    $this->actingAs($usuario)
        ->put(route('calculadora.update'), parametrosMasAgresivos())
        ->assertSessionHasNoErrors();

    // Sus cifras están congeladas: reescribirlas movería un déficit ya contado.
    expect((float) $hoy->fresh()->calorias_objetivo_dia)->toEqualWithDelta(2112.0, 0.01);
});

it('el cierre de un día pasado usa su objetivo sellado, no el perfil de hoy', function () {
    $usuario = usuarioDelObjetivo();

    $anterior = RegistroDiario::factory()->for($usuario, 'usuario')->create([
        'fecha' => now()->subDays(3)->toDateString(),
    ]);
    app(ObjetivoDelDiaService::class)->sellar($anterior);

    // El perfil cambia después de que ese día quedara sellado.
    $usuario->update(['peso_kg' => 70, 'calorias_objetivo' => 1848]);

    $resumen = app(DailyClosureService::class)->resumen($anterior->fresh());

    expect($resumen['calorias_objetivo'])->toEqualWithDelta(2112.0, 0.01)
        ->and($resumen['proteina_objetivo_g'])->toEqualWithDelta(160.0, 0.01);
});

it('el reparto entre comidas de un día pasado parte de su objetivo sellado', function () {
    $usuario = usuarioDelObjetivo();

    $anterior = RegistroDiario::factory()->for($usuario, 'usuario')->create([
        'fecha' => now()->subDays(3)->toDateString(),
    ]);
    app(ObjetivoDelDiaService::class)->sellar($anterior);

    $usuario->update(['peso_kg' => 70, 'calorias_objetivo' => 1848]);

    $objetivos = app(MealDistributionService::class)->objetivosDelRegistro($anterior->fresh());

    expect($objetivos['dia']['calorias_objetivo'])->toEqualWithDelta(2112.0, 0.01);
});

it('un día heredado sin sello sigue resolviéndose contra el perfil vigente', function () {
    $usuario = usuarioDelObjetivo();

    // Creado a mano, como los que ya existían antes de que esto se sellara.
    $sinSello = RegistroDiario::factory()->for($usuario, 'usuario')->create([
        'fecha' => now()->subDays(2)->toDateString(),
        'calorias_objetivo_dia' => null,
        'proteina_objetivo_g' => null,
        'grasa_objetivo_g' => null,
        'carbohidratos_objetivo_g' => null,
    ]);

    expect(app(ObjetivoDelDiaService::class)->estaSellado($sinSello))->toBeFalse()
        ->and(app(DailyClosureService::class)->resumen($sinSello)['calorias_objetivo'])
        ->toEqualWithDelta(2112.0, 0.01);
});

it('reiniciar el día lo vuelve a sellar con el perfil vigente, como recién creado', function () {
    $usuario = usuarioDelObjetivo();

    $this->actingAs($usuario)->post(route('planes.crear'));
    $hoy = RegistroDiario::where('usuario_id', $usuario->id)->firstOrFail();

    $usuario->update(['peso_kg' => 70, 'calorias_objetivo' => 1848]);

    $this->actingAs($usuario)->post(route('planes.resetear', $hoy))->assertRedirect();

    expect((float) $hoy->fresh()->calorias_objetivo_dia)->toEqualWithDelta(1848.0, 0.01);
});

it('no falla al crear el día cuando el perfil todavía no permite calcular un objetivo', function () {
    $usuario = User::factory()->create(['peso_kg' => null, 'nivel_actividad' => null, 'calorias_objetivo' => null]);

    $this->actingAs($usuario)->post(route('planes.crear'))->assertRedirect();

    $hoy = RegistroDiario::where('usuario_id', $usuario->id)->firstOrFail();

    // Sin sello, pero el día existe: sellar es una mejora del historial, nunca
    // un requisito para poder usar la aplicación.
    expect($hoy->calorias_objetivo_dia)->toBeNull();
});
