<?php

use App\Models\MetricaTendencia;
use App\Models\RegistroDiario;
use App\Models\User;

/**
 * Crea $dias días consecutivos que terminan hoy, bajando 0.1 kg por día hasta
 * $pesoHoy, con un déficit conocido de 500 kcal en cada día cerrado.
 */
function historialDePeso(User $usuario, int $dias, float $pesoHoy, bool $cerrado = true): void
{
    foreach (range(0, $dias - 1) as $diasAtras) {
        RegistroDiario::factory()->for($usuario, 'usuario')->create([
            'fecha' => now()->subDays($diasAtras)->toDateString(),
            'peso_kg' => $pesoHoy + $diasAtras * 0.1,
            'deficit_diario' => $cerrado ? 500.0 : null,
            'cerrado' => $cerrado,
            'cerrado_en' => $cerrado ? now() : null,
        ]);
    }
}

it('exige autenticación para ver el progreso', function () {
    $this->get(route('progreso.index'))->assertRedirect(route('login'));
});

it('muestra el promedio móvil de peso, el déficit promedio y la consistencia', function () {
    $usuario = User::factory()->create();

    // 7 días cerrados: 80.6 → 80.0 (el más antiguo pesa 80.6).
    // Promedio: (80.6 + 80.5 + 80.4 + 80.3 + 80.2 + 80.1 + 80.0) / 7 = 80.30
    historialDePeso($usuario, 7, 80.0);

    $respuesta = $this->actingAs($usuario)->get(route('progreso.index'));

    $respuesta->assertOk()
        ->assertSee('Mi progreso')
        ->assertSee('80.30 kg')            // promedio móvil de peso
        ->assertSee('500 kcal')            // déficit promedio de los 7 días
        ->assertSee('100%')                // los 7 días están cerrados
        ->assertSee('7 de 7 días cerrados');
});

it('refleja en el índice de consistencia los días sin cerrar', function () {
    $usuario = User::factory()->create();

    // 3 días cerrados y 4 abiertos dentro de la ventana → 3/7 = 42.86% ≈ 43%
    foreach (range(0, 6) as $diasAtras) {
        RegistroDiario::factory()->for($usuario, 'usuario')->create([
            'fecha' => now()->subDays($diasAtras)->toDateString(),
            'peso_kg' => 80.0,
            'cerrado' => $diasAtras < 3,
            'cerrado_en' => $diasAtras < 3 ? now() : null,
        ]);
    }

    $this->actingAs($usuario)->get(route('progreso.index'))
        ->assertOk()
        ->assertSee('43%')
        ->assertSee('3 de 7 días cerrados');
});

it('persiste el snapshot de tendencia del día al consultar el progreso', function () {
    $usuario = User::factory()->create();

    historialDePeso($usuario, 7, 80.0);

    $this->actingAs($usuario)->get(route('progreso.index'))->assertOk();

    $metrica = MetricaTendencia::where('usuario_id', $usuario->id)->sole();

    expect($metrica->fecha->toDateString())->toBe(now()->toDateString())
        ->and((float) $metrica->promedio_movil_peso_kg)->toEqualWithDelta(80.3, 0.01)
        ->and((float) $metrica->indice_consistencia_pct)->toEqualWithDelta(100.0, 0.01)
        ->and($metrica->dias_con_datos)->toBe(7);
});

it('recargar la página no duplica el snapshot de tendencia', function () {
    $usuario = User::factory()->create();

    historialDePeso($usuario, 7, 80.0);

    $this->actingAs($usuario)->get(route('progreso.index'))->assertOk();
    $this->actingAs($usuario)->get(route('progreso.index'))->assertOk();

    expect(MetricaTendencia::where('usuario_id', $usuario->id)->count())->toBe(1);
});

it('avisa de datos insuficientes con menos de 7 días de historial en vez de fallar', function () {
    $usuario = User::factory()->create();

    historialDePeso($usuario, 3, 80.0);

    $this->actingAs($usuario)->get(route('progreso.index'))
        ->assertOk()
        ->assertSee('Todavía no hay 7 días de historial')
        ->assertSee('3 días registrados');
});

it('no falla para un usuario recién registrado sin ningún dato', function () {
    $usuario = User::factory()->create();

    $this->actingAs($usuario)->get(route('progreso.index'))
        ->assertOk()
        ->assertSee('Sin datos')
        ->assertSee('0%');
});

it('incluye la serie del gráfico en el canvas de Chart.js', function () {
    $usuario = User::factory()->create();

    historialDePeso($usuario, 7, 80.0);

    $respuesta = $this->actingAs($usuario)->get(route('progreso.index'));

    $respuesta->assertOk()
        ->assertSee('grafico-peso')
        ->assertSee('chart.js', false)
        ->assertSee(now()->toDateString());
});

it('no mezcla el progreso de otro usuario', function () {
    $usuario = User::factory()->create();
    $otro = User::factory()->create();

    historialDePeso($usuario, 7, 80.0);

    foreach (range(0, 6) as $diasAtras) {
        RegistroDiario::factory()->for($otro, 'usuario')->create([
            'fecha' => now()->subDays($diasAtras)->toDateString(),
            'peso_kg' => 120.0,
        ]);
    }

    $this->actingAs($usuario)->get(route('progreso.index'))
        ->assertOk()
        ->assertSee('80.30 kg')
        ->assertDontSee('120.00 kg');
});
