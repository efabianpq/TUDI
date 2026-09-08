<?php

use App\Models\ParametroMaestro;
use App\Models\RegistroDiario;
use App\Models\User;
use App\Notifications\CuentaActivada;
use App\Services\ParametrosMaestrosService;
use App\Services\RulesEngineService;
use Illuminate\Support\Facades\Notification;

/**
 * Consola de administración (CLAUDE.md secciones 4.26 y 4.27): gestión de
 * usuarios y parámetros maestros.
 */

/*
|--------------------------------------------------------------------------
| Acceso
|--------------------------------------------------------------------------
*/

it('cierra la consola a quien no es administrador', function (string $ruta) {
    $this->actingAs(User::factory()->create())->get(route($ruta))->assertForbidden();
})->with(['admin.inicio', 'admin.usuarios.index', 'admin.parametros.edit']);

it('exige sesión iniciada', function () {
    $this->get(route('admin.inicio'))->assertRedirect(route('login'));
});

it('deja entrar al administrador', function () {
    $this->actingAs(User::factory()->administradora()->create())
        ->get(route('admin.inicio'))
        ->assertOk()
        ->assertSee('Administración');
});

it('enseña el enlace a la consola solo a los administradores', function () {
    $this->actingAs(User::factory()->administradora()->create())
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee(route('admin.inicio'));

    $this->actingAs(User::factory()->create())
        ->get(route('dashboard'))
        ->assertOk()
        ->assertDontSee(route('admin.inicio'));
});

/*
|--------------------------------------------------------------------------
| Gestión de usuarios
|--------------------------------------------------------------------------
*/

it('enseña al administrador el código de las cuentas pendientes', function () {
    $admin = User::factory()->administradora()->create();
    $pendiente = User::factory()->pendiente('WXYZ2345')->create(['name' => 'Ana Pendiente']);

    $this->actingAs($admin)->get(route('admin.inicio'))
        ->assertOk()
        ->assertSee('Ana Pendiente')
        // Es lo que tiene que entregarle: aquí sí se muestra.
        ->assertSee('WXYZ2345');
});

it('activa una cuenta desde la consola y avisa al usuario', function () {
    Notification::fake();

    $admin = User::factory()->administradora()->create();
    $pendiente = User::factory()->pendiente()->create();

    $this->actingAs($admin)
        ->patch(route('admin.usuarios.update', $pendiente), ['estado' => User::ESTADO_ACTIVO])
        ->assertSessionHas('status', 'usuario-actualizado');

    expect($pendiente->fresh()->estado)->toBe(User::ESTADO_ACTIVO)
        ->and($pendiente->fresh()->codigo_activacion)->toBeNull();

    Notification::assertSentTo($pendiente, CuentaActivada::class);
});

it('suspende una cuenta sin borrar sus datos', function () {
    $admin = User::factory()->administradora()->create();
    $usuario = User::factory()->create();
    RegistroDiario::factory()->for($usuario, 'usuario')->create();

    $this->actingAs($admin)
        ->patch(route('admin.usuarios.update', $usuario), ['estado' => User::ESTADO_SUSPENDIDO]);

    expect($usuario->fresh()->estado)->toBe(User::ESTADO_SUSPENDIDO)
        ->and($usuario->fresh()->registrosDiarios)->toHaveCount(1);
});

it('regenera el código y deja de servir el anterior', function () {
    $admin = User::factory()->administradora()->create();
    $pendiente = User::factory()->pendiente('WXYZ2345')->create();

    $this->actingAs($admin)
        ->post(route('admin.usuarios.codigo', $pendiente))
        ->assertSessionHas('status', 'codigo-regenerado');

    $nuevo = $pendiente->fresh()->codigo_activacion;

    expect($nuevo)->toHaveLength(8)->not->toBe('WXYZ2345');

    // El anterior ya no activa nada. Se recarga el modelo porque `actingAs`
    // fija esa instancia como usuario de las peticiones siguientes y la de
    // memoria todavía lleva el código viejo (CLAUDE.md sección 4.10).
    $this->actingAs($pendiente->fresh())
        ->post(route('activacion.store'), ['codigo' => 'WXYZ2345'])
        ->assertSessionHasErrors('codigo');
});

it('promueve y degrada administradores', function () {
    $admin = User::factory()->administradora()->create();
    $usuario = User::factory()->create();

    $this->actingAs($admin)->patch(route('admin.usuarios.update', $usuario), ['rol' => User::ROL_ADMIN]);
    expect($usuario->fresh()->esAdministrador())->toBeTrue();

    $this->actingAs($admin)->patch(route('admin.usuarios.update', $usuario), ['rol' => User::ROL_USUARIO]);
    expect($usuario->fresh()->esAdministrador())->toBeFalse();
});

it('impide que un administrador se degrade o se desactive a sí mismo', function () {
    $admin = User::factory()->administradora()->create();

    // Si pudiera, el sistema se quedaría sin ninguna consola accesible.
    $this->actingAs($admin)
        ->patch(route('admin.usuarios.update', $admin), ['rol' => User::ROL_USUARIO])
        ->assertSessionHasErrors('rol');

    $this->actingAs($admin)
        ->patch(route('admin.usuarios.update', $admin), ['estado' => User::ESTADO_SUSPENDIDO])
        ->assertSessionHasErrors('estado');

    $admin->refresh();

    expect($admin->esAdministrador())->toBeTrue()
        ->and($admin->estaActiva())->toBeTrue();
});

it('impide que un administrador se borre a sí mismo', function () {
    $admin = User::factory()->administradora()->create();

    $this->actingAs($admin)->delete(route('admin.usuarios.destroy', $admin))->assertForbidden();

    expect(User::find($admin->id))->not->toBeNull();
});

it('elimina una cuenta con todo su historial', function () {
    $admin = User::factory()->administradora()->create();
    $usuario = User::factory()->create();
    $registro = RegistroDiario::factory()->for($usuario, 'usuario')->create();

    $this->actingAs($admin)
        ->delete(route('admin.usuarios.destroy', $usuario))
        ->assertRedirect(route('admin.usuarios.index'));

    // Las FKs del dominio son cascade (sección 4).
    expect(User::find($usuario->id))->toBeNull()
        ->and(RegistroDiario::find($registro->id))->toBeNull();
});

it('busca por nombre, correo y código', function () {
    $admin = User::factory()->administradora()->create();
    User::factory()->pendiente('MNPQ3456')->create(['name' => 'Bruno Buscado', 'email' => 'bruno@example.com']);
    User::factory()->create(['name' => 'Carla Otra', 'email' => 'carla@example.com']);

    foreach (['Bruno', 'bruno@example.com', 'MNPQ3456'] as $termino) {
        $this->actingAs($admin)->get(route('admin.usuarios.index', ['q' => $termino]))
            ->assertOk()
            ->assertSee('Bruno Buscado')
            ->assertDontSee('Carla Otra');
    }
});

it('filtra por estado', function () {
    $admin = User::factory()->administradora()->create();
    User::factory()->pendiente()->create(['name' => 'Ana Pendiente']);
    User::factory()->create(['name' => 'Carla Activa']);

    $this->actingAs($admin)->get(route('admin.usuarios.index', ['estado' => User::ESTADO_PENDIENTE]))
        ->assertOk()
        ->assertSee('Ana Pendiente')
        ->assertDontSee('Carla Activa');
});

/*
|--------------------------------------------------------------------------
| Parámetros maestros
|--------------------------------------------------------------------------
*/

it('muestra los parámetros con su valor de fábrica cuando la tabla está vacía', function () {
    $this->actingAs(User::factory()->administradora()->create())
        ->get(route('admin.parametros.edit'))
        ->assertOk()
        ->assertSee('Motor de recomendaciones')
        ->assertSee('Pérdida semanal lenta');

    expect(app(ParametrosMaestrosService::class)->valor('recomendaciones_ajuste_kcal'))->toBe(150.0);
});

it('guarda un parámetro y el motor de reglas lo usa de inmediato', function () {
    $admin = User::factory()->administradora()->create();

    $valores = app(ParametrosMaestrosService::class)->todos();
    $valores['recomendaciones_ajuste_kcal'] = 200;

    $this->actingAs($admin)
        ->put(route('admin.parametros.update'), ['parametros' => $valores])
        ->assertSessionHas('status', 'parametros-guardados');

    expect(ParametroMaestro::where('clave', 'recomendaciones_ajuste_kcal')->value('valor'))->toBe('200')
        // Y lo lee el servicio que lo consume, no solo la tabla.
        ->and(app(RulesEngineService::class)->ajusteKcalSugerido())->toBe(200.0);
});

it('rechaza un parámetro fuera de su rango', function () {
    $admin = User::factory()->administradora()->create();

    $valores = app(ParametrosMaestrosService::class)->todos();
    $valores['recomendaciones_ajuste_kcal'] = 5000;

    $this->actingAs($admin)
        ->put(route('admin.parametros.update'), ['parametros' => $valores])
        ->assertSessionHasErrors('parametros.recomendaciones_ajuste_kcal');

    expect(ParametroMaestro::count())->toBe(0);
});

it('acepta decimales escritos con coma', function () {
    $admin = User::factory()->administradora()->create();

    $valores = app(ParametrosMaestrosService::class)->todos();
    $valores['recomendaciones_umbral_perdida_lenta_pct'] = '0,7';

    $this->actingAs($admin)
        ->put(route('admin.parametros.update'), ['parametros' => $valores])
        ->assertSessionHasNoErrors();

    expect(app(ParametrosMaestrosService::class)->valor('recomendaciones_umbral_perdida_lenta_pct'))->toBe(0.7);
});

it('devuelve todo a los valores de fábrica', function () {
    $admin = User::factory()->administradora()->create();

    app(ParametrosMaestrosService::class)->guardar(['recomendaciones_ajuste_kcal' => 200]);

    $this->actingAs($admin)
        ->post(route('admin.parametros.restablecer'))
        ->assertSessionHas('status', 'parametros-restablecidos');

    expect(ParametroMaestro::count())->toBe(0)
        ->and(app(ParametrosMaestrosService::class)->valor('recomendaciones_ajuste_kcal'))->toBe(150.0);
});
