<?php

use App\Models\User;
use App\Notifications\CuentaActivada;
use App\Notifications\NuevoUsuarioRegistrado;
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

    // La cuenta entra directa a la Calculadora, que es el primer paso del flujo
    // (CLAUDE.md secciones 5.1 y 5.2). Ya no hay pantalla de código de por
    // medio: la validación manual quedó como herramienta del administrador.
    $response->assertRedirect(route('calculadora.edit', absolute: false));

    $usuario = User::where('email', 'test@example.com')->sole();

    expect($usuario->estado)->toBe(User::ESTADO_ACTIVO)
        ->and($usuario->rol)->toBe(User::ROL_USUARIO)
        ->and($usuario->codigo_activacion)->toBeNull()
        ->and($usuario->activado_en)->not->toBeNull();
});

test('registrarse estrena la prueba de Premium, sin pedirla y sin tarjeta', function () {
    Notification::fake();

    $this->post('/register', [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $usuario = User::where('email', 'test@example.com')->sole();

    expect($usuario->plan)->toBe(User::PLAN_TRIAL)
        ->and($usuario->tienePremium())->toBeTrue()
        ->and($usuario->enPrueba())->toBeTrue()
        // Los días salen de config/planes.php, no de un número escrito a mano.
        ->and($usuario->diasDePruebaRestantes())->toBe(config('planes.prueba_dias'))
        ->and($usuario->plan_expira_en->toDateString())
        ->toBe(now()->addDays(config('planes.prueba_dias'))->toDateString());
});

test('el alta avisa al usuario y a los administradores', function () {
    Notification::fake();

    $admin = User::factory()->administradora()->create();

    $this->post('/register', [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $usuario = User::where('email', 'test@example.com')->sole();

    Notification::assertSentTo($usuario, CuentaActivada::class);
    Notification::assertSentTo($admin, NuevoUsuarioRegistrado::class);
});
