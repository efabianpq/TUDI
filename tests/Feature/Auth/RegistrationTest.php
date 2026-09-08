<?php

use App\Models\User;
use App\Notifications\CuentaPendienteDeActivacion;
use App\Notifications\NuevoUsuarioPendiente;
use Illuminate\Support\Facades\Notification;

test('registration screen can be rendered', function () {
    $response = $this->get('/register');

    $response->assertStatus(200);
});

test('new users can register', function () {
    Notification::fake();

    $response = $this->post('/register', [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $this->assertAuthenticated();

    // La cuenta nace pendiente y va a la pantalla del código, no a la
    // calculadora (CLAUDE.md sección 4.26).
    $response->assertRedirect(route('activacion.create', absolute: false));

    $usuario = User::where('email', 'test@example.com')->sole();

    expect($usuario->estado)->toBe(User::ESTADO_PENDIENTE)
        ->and($usuario->rol)->toBe(User::ROL_USUARIO)
        ->and($usuario->codigo_activacion)->toHaveLength(8)
        ->and($usuario->activado_en)->toBeNull();
});

test('el correo al usuario nuevo no lleva el código: lo entrega el administrador', function () {
    Notification::fake();

    $admin = User::factory()->administradora()->create();

    $this->post('/register', [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $usuario = User::where('email', 'test@example.com')->sole();

    // Al usuario: "pide tu código". El código, solo al administrador.
    Notification::assertSentTo($usuario, CuentaPendienteDeActivacion::class);
    Notification::assertSentTo($admin, NuevoUsuarioPendiente::class);

    $cuerpo = (new CuentaPendienteDeActivacion)->toMail($usuario)->render();

    expect((string) $cuerpo)->not->toContain($usuario->codigo_activacion);
});
