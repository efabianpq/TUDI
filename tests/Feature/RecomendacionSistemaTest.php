<?php

use App\Models\RecomendacionSistema;
use App\Models\RegistroDiario;
use App\Models\User;

function crearRecomendacionPendiente(User $usuario, float $caloriasSugeridas = 1850.0): RecomendacionSistema
{
    $registro = RegistroDiario::factory()->for($usuario, 'usuario')->create();

    return RecomendacionSistema::factory()->for($registro, 'registroDiario')->create([
        'tipo' => 'ajuste_calorico',
        'calorias_objetivo_sugeridas' => $caloriasSugeridas,
        'estado' => 'pendiente',
        'confirmada_en' => null,
    ]);
}

it('requiere autenticación para confirmar o rechazar una recomendación', function () {
    $usuario = User::factory()->create();
    $recomendacion = crearRecomendacionPendiente($usuario);

    $this->post(route('recomendaciones.confirmar', $recomendacion))->assertRedirect(route('login'));
    $this->post(route('recomendaciones.rechazar', $recomendacion))->assertRedirect(route('login'));
});

it('rechaza con 403 confirmar la recomendación de otro usuario', function () {
    $usuario = User::factory()->create();
    $otro = User::factory()->create();
    $recomendacion = crearRecomendacionPendiente($usuario);

    $this->actingAs($otro)
        ->post(route('recomendaciones.confirmar', $recomendacion))
        ->assertForbidden();
});

it('rechaza con 403 rechazar la recomendación de otro usuario', function () {
    $usuario = User::factory()->create();
    $otro = User::factory()->create();
    $recomendacion = crearRecomendacionPendiente($usuario);

    $this->actingAs($otro)
        ->post(route('recomendaciones.rechazar', $recomendacion))
        ->assertForbidden();

    expect($recomendacion->fresh()->estado)->toBe('pendiente');
});

it('confirmar una recomendación de ajuste calórico actualiza calorias_objetivo del usuario', function () {
    $usuario = User::factory()->create(['calorias_objetivo' => 2000]);
    $recomendacion = crearRecomendacionPendiente($usuario, 1850.0);

    $this->actingAs($usuario)
        ->post(route('recomendaciones.confirmar', $recomendacion))
        ->assertRedirect(route('cierre.index'));

    expect((float) $usuario->fresh()->calorias_objetivo)->toEqualWithDelta(1850.0, 0.01);
    expect($recomendacion->fresh()->estado)->toBe('confirmada');
});

it('rechazar una recomendación no modifica calorias_objetivo del usuario', function () {
    $usuario = User::factory()->create(['calorias_objetivo' => 2000]);
    $recomendacion = crearRecomendacionPendiente($usuario, 1850.0);

    $this->actingAs($usuario)
        ->post(route('recomendaciones.rechazar', $recomendacion))
        ->assertRedirect(route('cierre.index'));

    expect((float) $usuario->fresh()->calorias_objetivo)->toEqualWithDelta(2000.0, 0.01);
    expect($recomendacion->fresh()->estado)->toBe('rechazada');
});

it('no permite confirmar una recomendación que ya fue rechazada', function () {
    $usuario = User::factory()->create(['calorias_objetivo' => 2000]);
    $recomendacion = crearRecomendacionPendiente($usuario, 1850.0);

    $this->actingAs($usuario)->post(route('recomendaciones.rechazar', $recomendacion));

    $this->actingAs($usuario)
        ->post(route('recomendaciones.confirmar', $recomendacion))
        ->assertRedirect(route('cierre.index'))
        ->assertSessionHas('error');

    expect((float) $usuario->fresh()->calorias_objetivo)->toEqualWithDelta(2000.0, 0.01);
});
