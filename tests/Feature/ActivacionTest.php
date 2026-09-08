<?php

use App\Models\User;
use App\Notifications\CuentaActivada;
use Illuminate\Support\Facades\Notification;

/**
 * Activación de cuentas por código (CLAUDE.md sección 4.26): cualquiera puede
 * registrarse, pero nadie entra hasta que canjea el código que le entrega el
 * administrador por fuera de la aplicación.
 */
it('deja fuera de la aplicación a una cuenta pendiente', function () {
    $usuario = User::factory()->pendiente()->create();

    foreach (['dashboard', 'calculadora.edit', 'planes.index'] as $ruta) {
        $this->actingAs($usuario)->get(route($ruta))
            ->assertRedirect(route('activacion.create'));
    }
});

it('muestra la pantalla del código a una cuenta pendiente', function () {
    $usuario = User::factory()->pendiente()->create();

    $this->actingAs($usuario)->get(route('activacion.create'))
        ->assertOk()
        ->assertSee('Activa tu cuenta')
        // El código no se le enseña aquí: se lo tiene que dar el administrador.
        ->assertDontSee($usuario->codigo_activacion);
});

it('activa la cuenta con el código correcto y lo quema', function () {
    Notification::fake();

    $usuario = User::factory()->pendiente('QRST7890')->create();

    $this->actingAs($usuario)
        ->post(route('activacion.store'), ['codigo' => 'QRST7890'])
        ->assertRedirect(route('calculadora.edit'));

    $usuario->refresh();

    expect($usuario->estado)->toBe(User::ESTADO_ACTIVO)
        // Un código canjeado no vuelve a servir.
        ->and($usuario->codigo_activacion)->toBeNull()
        ->and($usuario->activado_en)->not->toBeNull();

    Notification::assertSentTo($usuario, CuentaActivada::class);
});

it('acepta el código en minúsculas y con espacios, como se copia y se dicta', function () {
    Notification::fake();

    $usuario = User::factory()->pendiente('QRST7890')->create();

    $this->actingAs($usuario)
        ->post(route('activacion.store'), ['codigo' => ' qrst 7890 '])
        ->assertRedirect(route('calculadora.edit'));

    expect($usuario->fresh()->estado)->toBe(User::ESTADO_ACTIVO);
});

it('rechaza un código equivocado sin activar nada', function () {
    $usuario = User::factory()->pendiente('QRST7890')->create();

    $this->actingAs($usuario)
        ->post(route('activacion.store'), ['codigo' => 'ZZZZ9999'])
        ->assertSessionHasErrors('codigo');

    $usuario->refresh();

    expect($usuario->estado)->toBe(User::ESTADO_PENDIENTE)
        ->and($usuario->codigo_activacion)->toBe('QRST7890');
});

it('cierra la sesión de una cuenta suspendida en vez de dejarla dando vueltas', function () {
    $usuario = User::factory()->suspendida()->create();

    $this->actingAs($usuario)->get(route('dashboard'))
        ->assertRedirect(route('login'))
        ->assertSessionHas('error');

    $this->assertGuest();
});

it('no molesta con la pantalla del código a una cuenta ya activa', function () {
    $usuario = User::factory()->create();

    $this->actingAs($usuario)->get(route('activacion.create'))
        ->assertRedirect(route('dashboard'));
});

it('exige sesión iniciada para activar', function () {
    $this->get(route('activacion.create'))->assertRedirect(route('login'));
    $this->post(route('activacion.store'), ['codigo' => 'QRST7890'])->assertRedirect(route('login'));
});
