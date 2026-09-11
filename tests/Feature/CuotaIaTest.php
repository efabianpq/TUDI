<?php

use App\Models\ParametroMaestro;
use App\Models\RegistroDiario;
use App\Models\User;
use App\Services\CuotaIaService;
use App\Services\ParametrosMaestrosService;
use Illuminate\Support\Facades\Http;

/**
 * Cuota diaria de llamadas al proveedor de IA (CLAUDE.md sección 5.20).
 *
 * Premium es ilimitado en funciones, no en llamadas: cada "Calcular mi plan" y
 * cada reporte contado por escrito cuestan dinero y ocupan un worker de PHP-FPM
 * mientras duran. El límite acota el bucle de pruebas sin quitarle a nadie la
 * posibilidad de registrar su día.
 */
function usuarioConCuota(): User
{
    return User::factory()->create([
        'peso_kg' => 80,
        'estatura_m' => 1.75,
        'edad' => 35,
        'sexo' => 'masculino',
        'nivel_actividad' => 1.5,
        'tipo_deficit' => 'porcentaje',
        'valor_deficit' => 0.2,
        'proteina_factor' => 2.0,
        'grasa_factor' => 0.8,
    ]);
}

function fingirDistribucionDeOpenAi(): void
{
    config([
        'services.openai.key' => 'clave-de-prueba',
        'services.openai.endpoint' => 'https://api.openai.com/v1',
    ]);

    Http::fake(['api.openai.com/*' => Http::response([
        'choices' => [[
            'index' => 0,
            'message' => [
                'role' => 'assistant',
                'refusal' => null,
                'content' => json_encode(['comidas' => [[
                    'tipo_comida' => 'desayuno',
                    'descripcion' => 'Huevos revueltos con palta',
                    'preparacion' => '',
                    'notas' => '',
                    'alimentos_reconocidos' => true,
                    'ingredientes' => [
                        ['nombre' => 'Huevo', 'porcion' => '100 g', 'cantidad_g' => 100, 'calorias' => 143, 'proteina_g' => 12.6, 'grasa_g' => 9.5, 'carbohidratos_g' => 0.7],
                    ],
                ]]]),
            ],
            'finish_reason' => 'stop',
        ]],
    ])]);
}

test('ajustar el plan gasta una unidad de cuota por pulsación', function () {
    fingirDistribucionDeOpenAi();

    $usuario = usuarioConCuota();
    $registroDiario = RegistroDiario::factory()->for($usuario, 'usuario')->create([
        'fecha' => now()->toDateString(),
    ]);

    $cuotas = app(CuotaIaService::class);
    $limite = $cuotas->limite(CuotaIaService::CONCEPTO_DISTRIBUCION);

    $this->actingAs($usuario)->post(route('planes.distribucion', $registroDiario), [
        'ingredientes' => ['desayuno' => 'dos huevos y media palta'],
    ])->assertSessionHas('status', 'plan-ajustado');

    expect($cuotas->restantes($usuario, CuotaIaService::CONCEPTO_DISTRIBUCION))->toBe($limite - 1);
});

test('agotada la cuota, ajustar el plan se rechaza con un mensaje y sin llamar al proveedor', function () {
    fingirDistribucionDeOpenAi();

    $usuario = usuarioConCuota();
    $registroDiario = RegistroDiario::factory()->for($usuario, 'usuario')->create([
        'fecha' => now()->toDateString(),
    ]);

    $cuotas = app(CuotaIaService::class);

    foreach (range(1, $cuotas->limite(CuotaIaService::CONCEPTO_DISTRIBUCION)) as $ignorado) {
        $cuotas->consumir($usuario, CuotaIaService::CONCEPTO_DISTRIBUCION);
    }

    $this->actingAs($usuario)->post(route('planes.distribucion', $registroDiario), [
        'ingredientes' => ['desayuno' => 'dos huevos y media palta'],
    ])
        ->assertRedirect(route('planes.show', $registroDiario))
        ->assertSessionHas('error', fn (string $error) => str_contains($error, 'ajustes de plan de hoy'));

    // El texto del usuario no se pierde aunque no se genere nada.
    expect($registroDiario->fresh()->ingredientes_desayuno)->toBe('dos huevos y media palta')
        ->and($registroDiario->planesComida()->count())->toBe(0);

    Http::assertNothingSent();
});

test('el intento fallido en el proveedor también gasta cuota', function () {
    config(['services.openai.key' => 'clave-de-prueba', 'services.openai.endpoint' => 'https://api.openai.com/v1']);
    Http::fake(['api.openai.com/*' => Http::response(['error' => ['message' => 'overloaded']], 529)]);

    $usuario = usuarioConCuota();
    $registroDiario = RegistroDiario::factory()->for($usuario, 'usuario')->create([
        'fecha' => now()->toDateString(),
    ]);

    $cuotas = app(CuotaIaService::class);
    $limite = $cuotas->limite(CuotaIaService::CONCEPTO_DISTRIBUCION);

    $this->actingAs($usuario)->post(route('planes.distribucion', $registroDiario), [
        'ingredientes' => ['desayuno' => 'dos huevos y media palta'],
    ])->assertSessionHas('error');

    // Cobrar solo los aciertos dejaría un bucle de fallos llamando gratis para
    // siempre, que es justo lo que el límite evita.
    expect($cuotas->restantes($usuario, CuotaIaService::CONCEPTO_DISTRIBUCION))->toBe($limite - 1);
});

test('el plan diario dice cuántos ajustes quedan hoy', function () {
    $usuario = usuarioConCuota();
    $registroDiario = RegistroDiario::factory()->for($usuario, 'usuario')->create([
        'fecha' => now()->toDateString(),
    ]);

    $limite = app(CuotaIaService::class)->limite(CuotaIaService::CONCEPTO_DISTRIBUCION);

    $this->actingAs($usuario)->get(route('planes.show', $registroDiario))
        ->assertOk()
        ->assertSee("Te quedan {$limite} de {$limite} hoy.");

    app(CuotaIaService::class)->consumir($usuario, CuotaIaService::CONCEPTO_DISTRIBUCION);

    $this->actingAs($usuario)->get(route('planes.show', $registroDiario))
        ->assertOk()
        ->assertSee('Te quedan '.($limite - 1)." de {$limite} hoy.");
});

test('sin cuota, el plan diario esconde el botón y explica qué se puede seguir haciendo', function () {
    $usuario = usuarioConCuota();
    $registroDiario = RegistroDiario::factory()->for($usuario, 'usuario')->create([
        'fecha' => now()->toDateString(),
    ]);

    $cuotas = app(CuotaIaService::class);

    foreach (range(1, $cuotas->limite(CuotaIaService::CONCEPTO_DISTRIBUCION)) as $ignorado) {
        $cuotas->consumir($usuario, CuotaIaService::CONCEPTO_DISTRIBUCION);
    }

    $this->actingAs($usuario)->get(route('planes.show', $registroDiario))
        ->assertOk()
        ->assertDontSee('Calcular mi plan')
        ->assertSee('ajustes de plan de hoy')
        // El día se sigue pudiendo registrar entero: la salida es honesta.
        ->assertSee('Cerrar desayuno');
});

test('el administrador puede mover los límites sin desplegar', function () {
    fingirDistribucionDeOpenAi();

    $usuario = usuarioConCuota();
    $registroDiario = RegistroDiario::factory()->for($usuario, 'usuario')->create([
        'fecha' => now()->toDateString(),
    ]);

    app(ParametrosMaestrosService::class)->guardar(['ia_limite_distribuciones_dia' => 1]);

    expect(app(CuotaIaService::class)->limite(CuotaIaService::CONCEPTO_DISTRIBUCION))->toBe(1)
        ->and(ParametroMaestro::where('clave', 'ia_limite_distribuciones_dia')->exists())->toBeTrue();

    $this->actingAs($usuario)->post(route('planes.distribucion', $registroDiario), [
        'ingredientes' => ['desayuno' => 'dos huevos y media palta'],
    ])->assertSessionHas('status', 'plan-ajustado');

    $this->actingAs($usuario)->post(route('planes.distribucion', $registroDiario), [
        'ingredientes' => ['desayuno' => 'tres huevos y una arepa'],
    ])->assertSessionHas('error', fn (string $error) => str_contains($error, 'ajustes de plan de hoy'));
});

test('la consola de administración ofrece los dos límites de IA', function () {
    $admin = User::factory()->create(['rol' => User::ROL_ADMIN]);

    $this->actingAs($admin)->get(route('admin.parametros.edit'))
        ->assertOk()
        ->assertSee('Límites de IA')
        ->assertSee('Ajustes de plan al día')
        ->assertSee('Reportes con IA al día');
});
