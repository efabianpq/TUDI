<?php

use App\Models\MetricaTendencia;
use App\Models\RegistroDiario;
use App\Models\User;
use App\Services\TrendAnalyticsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

// El servicio lee RegistroDiario y escribe MetricaTendencia, así que necesita la
// base de datos explícitamente (tests/Pest.php solo aplica RefreshDatabase a Feature).
uses(TestCase::class, RefreshDatabase::class);

/**
 * Fecha de corte fija en todos los tests: así los días que se construyen a mano
 * no dependen del día en que se ejecute la suite.
 */
function corteDePrueba(): Carbon
{
    return Carbon::parse('2026-03-15')->startOfDay();
}

/**
 * Crea un RegistroDiario a $diasAtras días de la fecha de corte.
 *
 * @param  array<string, mixed>  $atributos
 */
function diaConPeso(User $usuario, int $diasAtras, ?float $pesoKg, array $atributos = []): RegistroDiario
{
    return RegistroDiario::factory()->for($usuario, 'usuario')->create([
        'fecha' => corteDePrueba()->copy()->subDays($diasAtras)->toDateString(),
        'peso_kg' => $pesoKg,
        ...$atributos,
    ]);
}

it('calcula el promedio móvil de peso de 7 días ignorando los días fuera de la ventana', function () {
    $usuario = User::factory()->create();

    // 10 días consecutivos de pesos conocidos. Los 3 más antiguos (índices 9, 8 y 7)
    // quedan fuera de la ventana de 7 días que termina en la fecha de corte.
    $pesos = [
        9 => 85.0,  // 2026-03-06 ─┐
        8 => 84.8,  // 2026-03-07  │ fuera de la ventana
        7 => 84.6,  // 2026-03-08 ─┘
        6 => 81.5,  // 2026-03-09 ─┐
        5 => 81.3,  // 2026-03-10  │
        4 => 81.2,  // 2026-03-11  │
        3 => 81.0,  // 2026-03-12  │ ventana de 7 días
        2 => 80.9,  // 2026-03-13  │
        1 => 80.7,  // 2026-03-14  │
        0 => 80.6,  // 2026-03-15 ─┘ (fecha de corte)
    ];

    foreach ($pesos as $diasAtras => $peso) {
        diaConPeso($usuario, $diasAtras, $peso);
    }

    $metricas = (new TrendAnalyticsService)->calcular($usuario, corteDePrueba());

    // Cálculo manual: 81.5 + 81.3 + 81.2 + 81.0 + 80.9 + 80.7 + 80.6 = 567.2
    //                 567.2 / 7 = 81.028571...
    $promedioManual = (81.5 + 81.3 + 81.2 + 81.0 + 80.9 + 80.7 + 80.6) / 7;

    expect($promedioManual)->toEqualWithDelta(81.0285714, 0.0000001)
        ->and($metricas['promedio_movil_peso_kg'])->toEqualWithDelta($promedioManual, 0.0001)
        ->and($metricas['dias_con_datos'])->toBe(7)
        ->and($metricas['datos_suficientes'])->toBeTrue();

    // Y no es el promedio de los 10 días: los tres más pesados quedaron fuera.
    expect($metricas['promedio_movil_peso_kg'])
        ->not->toEqualWithDelta(array_sum($pesos) / 10, 0.01);
});

it('calcula el promedio móvil de déficit calórico de 7 días', function () {
    $usuario = User::factory()->create();

    // Un día fuera de la ventana con un déficit enorme, para comprobar que no entra.
    diaConPeso($usuario, 7, 82.0, ['deficit_diario' => 5000.0, 'cerrado' => true]);

    $deficits = [600.0, 450.0, 700.0, 300.0, 550.0, 400.0, 500.0];

    foreach ($deficits as $indice => $deficit) {
        diaConPeso($usuario, 6 - $indice, 81.0, ['deficit_diario' => $deficit, 'cerrado' => true]);
    }

    $metricas = (new TrendAnalyticsService)->calcular($usuario, corteDePrueba());

    // Cálculo manual: 600 + 450 + 700 + 300 + 550 + 400 + 500 = 3500; 3500 / 7 = 500
    expect(array_sum($deficits))->toBe(3500.0)
        ->and($metricas['promedio_movil_deficit_kcal'])->toEqualWithDelta(500.0, 0.0001);
});

it('el índice de consistencia refleja los días cerrados de los últimos 7', function () {
    $usuario = User::factory()->create();

    // 4 de los 7 días de la ventana están cerrados.
    foreach ([0, 1, 2, 3, 4, 5, 6] as $diasAtras) {
        diaConPeso($usuario, $diasAtras, 80.0, ['cerrado' => in_array($diasAtras, [0, 2, 4, 6], true)]);
    }

    // Un día cerrado fuera de la ventana no debe inflar el índice.
    diaConPeso($usuario, 8, 80.0, ['cerrado' => true]);

    $metricas = (new TrendAnalyticsService)->calcular($usuario, corteDePrueba());

    // 4 días cerrados / 7 días de ventana = 57.14%
    expect($metricas['dias_cerrados'])->toBe(4)
        ->and($metricas['indice_consistencia_pct'])->toEqualWithDelta(4 / 7 * 100, 0.0001)
        ->and($metricas['indice_consistencia_pct'])->toEqualWithDelta(57.142857, 0.0001);
});

it('el índice de consistencia es 0 cuando hay registros pero ninguno cerrado', function () {
    $usuario = User::factory()->create();

    foreach (range(0, 6) as $diasAtras) {
        diaConPeso($usuario, $diasAtras, 80.0, ['cerrado' => false]);
    }

    $metricas = (new TrendAnalyticsService)->calcular($usuario, corteDePrueba());

    expect($metricas['dias_con_datos'])->toBe(7)
        ->and($metricas['dias_cerrados'])->toBe(0)
        ->and($metricas['indice_consistencia_pct'])->toBe(0.0);
});

it('el índice de consistencia es 100% con los 7 días cerrados', function () {
    $usuario = User::factory()->create();

    foreach (range(0, 6) as $diasAtras) {
        diaConPeso($usuario, $diasAtras, 80.0, ['cerrado' => true]);
    }

    $metricas = (new TrendAnalyticsService)->calcular($usuario, corteDePrueba());

    expect($metricas['indice_consistencia_pct'])->toEqualWithDelta(100.0, 0.0001);
});

it('no falla con menos de 7 días de historial: promedia lo que hay y lo marca como insuficiente', function () {
    $usuario = User::factory()->create();

    // Solo 3 días de historial.
    diaConPeso($usuario, 2, 80.0);
    diaConPeso($usuario, 1, 79.4);
    diaConPeso($usuario, 0, 79.0);

    $metricas = (new TrendAnalyticsService)->calcular($usuario, corteDePrueba());

    // Cálculo manual: (80.0 + 79.4 + 79.0) / 3 = 238.4 / 3 = 79.466666...
    expect($metricas['promedio_movil_peso_kg'])->toEqualWithDelta((80.0 + 79.4 + 79.0) / 3, 0.0001)
        ->and($metricas['dias_con_datos'])->toBe(3)
        ->and($metricas['datos_suficientes'])->toBeFalse()
        // El divisor de la consistencia sigue siendo 7: 0 cerrados de 7 posibles.
        ->and($metricas['indice_consistencia_pct'])->toBe(0.0)
        // Sin ventana anterior con la que comparar no hay pérdida semanal.
        ->and($metricas['porcentaje_perdida_semanal'])->toBeNull()
        ->and($metricas['tendencia'])->toBeNull();
});

it('no falla cuando el usuario no tiene ningún registro diario', function () {
    $usuario = User::factory()->create();

    $metricas = (new TrendAnalyticsService)->calcular($usuario, corteDePrueba());

    expect($metricas['promedio_movil_peso_kg'])->toBeNull()
        ->and($metricas['promedio_movil_deficit_kcal'])->toBeNull()
        ->and($metricas['promedio_movil_calorias'])->toBeNull()
        ->and($metricas['dias_con_datos'])->toBe(0)
        ->and($metricas['datos_suficientes'])->toBeFalse()
        ->and($metricas['indice_consistencia_pct'])->toBe(0.0)
        ->and($metricas['porcentaje_perdida_semanal'])->toBeNull();
});

it('no falla cuando hay registros diarios pero ninguno tiene el peso apuntado', function () {
    $usuario = User::factory()->create();

    foreach (range(0, 6) as $diasAtras) {
        diaConPeso($usuario, $diasAtras, null, ['cerrado' => true]);
    }

    $metricas = (new TrendAnalyticsService)->calcular($usuario, corteDePrueba());

    expect($metricas['promedio_movil_peso_kg'])->toBeNull()
        ->and($metricas['porcentaje_perdida_semanal'])->toBeNull()
        // La consistencia sí es medible: no depende del peso.
        ->and($metricas['indice_consistencia_pct'])->toEqualWithDelta(100.0, 0.0001);
});

it('el promedio ignora los días sin peso en vez de contarlos como cero', function () {
    $usuario = User::factory()->create();

    diaConPeso($usuario, 2, 80.0);
    diaConPeso($usuario, 1, null);
    diaConPeso($usuario, 0, 79.0);

    $metricas = (new TrendAnalyticsService)->calcular($usuario, corteDePrueba());

    // (80.0 + 79.0) / 2 = 79.5, no (80.0 + 0 + 79.0) / 3 = 53.0
    expect($metricas['promedio_movil_peso_kg'])->toEqualWithDelta(79.5, 0.0001);
});

it('calcula el porcentaje de pérdida semanal comparando las dos ventanas de 7 días', function () {
    $usuario = User::factory()->create();

    // Ventana anterior (días 13..7): todos a 80 kg → promedio 80.
    foreach (range(7, 13) as $diasAtras) {
        diaConPeso($usuario, $diasAtras, 80.0);
    }

    // Ventana actual (días 6..0): todos a 79.4 kg → promedio 79.4.
    foreach (range(0, 6) as $diasAtras) {
        diaConPeso($usuario, $diasAtras, 79.4);
    }

    $metricas = (new TrendAnalyticsService)->calcular($usuario, corteDePrueba());

    // Cálculo manual: (80 - 79.4) / 80 * 100 = 0.6 / 80 * 100 = 0.75%
    expect($metricas['porcentaje_perdida_semanal'])->toEqualWithDelta(0.75, 0.0001)
        // Entre 0.5% y 1%: la banda que la sección 6 no toca.
        ->and($metricas['tendencia'])->toBe('perdida_adecuada');
});

it('clasifica como pérdida lenta una bajada por debajo del 0.5% semanal', function () {
    $usuario = User::factory()->create();

    foreach (range(7, 13) as $diasAtras) {
        diaConPeso($usuario, $diasAtras, 80.0);
    }

    // (80 - 79.76) / 80 * 100 = 0.3%
    foreach (range(0, 6) as $diasAtras) {
        diaConPeso($usuario, $diasAtras, 79.76);
    }

    $metricas = (new TrendAnalyticsService)->calcular($usuario, corteDePrueba());

    expect($metricas['porcentaje_perdida_semanal'])->toEqualWithDelta(0.3, 0.0001)
        ->and($metricas['tendencia'])->toBe('perdida_lenta');
});

it('clasifica como estable una variación dentro del ruido de la báscula', function () {
    $usuario = User::factory()->create();

    foreach (range(7, 13) as $diasAtras) {
        diaConPeso($usuario, $diasAtras, 80.0);
    }

    // (80 - 79.96) / 80 * 100 = 0.05%, por debajo del umbral de estabilidad.
    foreach (range(0, 6) as $diasAtras) {
        diaConPeso($usuario, $diasAtras, 79.96);
    }

    expect((new TrendAnalyticsService)->calcular($usuario, corteDePrueba())['tendencia'])->toBe('estable');
});

it('clasifica como ganancia una subida de peso', function () {
    $usuario = User::factory()->create();

    foreach (range(7, 13) as $diasAtras) {
        diaConPeso($usuario, $diasAtras, 80.0);
    }

    foreach (range(0, 6) as $diasAtras) {
        diaConPeso($usuario, $diasAtras, 80.8);
    }

    $metricas = (new TrendAnalyticsService)->calcular($usuario, corteDePrueba());

    expect($metricas['porcentaje_perdida_semanal'])->toBeLessThan(0.0)
        ->and($metricas['tendencia'])->toBe('ganancia');
});

it('persiste una única MetricaTendencia por usuario y fecha de corte', function () {
    $usuario = User::factory()->create();

    foreach (range(0, 6) as $diasAtras) {
        diaConPeso($usuario, $diasAtras, 80.0 + $diasAtras * 0.1, [
            'deficit_diario' => 500.0,
            'cerrado' => $diasAtras < 3,
        ]);
    }

    $servicio = new TrendAnalyticsService;
    $metrica = $servicio->calcularYPersistir($usuario, corteDePrueba());

    // (80.0 + 80.1 + 80.2 + 80.3 + 80.4 + 80.5 + 80.6) / 7 = 562.1 / 7 = 80.3
    expect((float) $metrica->promedio_movil_peso_kg)->toEqualWithDelta(80.3, 0.01)
        ->and((float) $metrica->promedio_movil_deficit_kcal)->toEqualWithDelta(500.0, 0.01)
        ->and((float) $metrica->indice_consistencia_pct)->toEqualWithDelta(3 / 7 * 100, 0.01)
        ->and($metrica->dias_con_datos)->toBe(7)
        ->and($metrica->fecha->toDateString())->toBe('2026-03-15');

    expect(MetricaTendencia::where('usuario_id', $usuario->id)->count())->toBe(1);
});

it('recalcular el mismo día actualiza la fila existente en vez de duplicarla', function () {
    $usuario = User::factory()->create();

    diaConPeso($usuario, 0, 80.0);

    $servicio = new TrendAnalyticsService;
    $primera = $servicio->calcularYPersistir($usuario, corteDePrueba());

    // Cambia el peso del día y se vuelve a calcular la misma fecha de corte.
    RegistroDiario::where('usuario_id', $usuario->id)->update(['peso_kg' => 78.0]);

    $segunda = $servicio->calcularYPersistir($usuario, corteDePrueba());

    expect($segunda->id)->toBe($primera->id)
        ->and((float) $segunda->promedio_movil_peso_kg)->toEqualWithDelta(78.0, 0.01)
        ->and(MetricaTendencia::where('usuario_id', $usuario->id)->count())->toBe(1);
});

it('persiste un snapshot vacío sin fallar cuando no hay historial', function () {
    $usuario = User::factory()->create();

    $metrica = (new TrendAnalyticsService)->calcularYPersistir($usuario, corteDePrueba());

    expect($metrica->promedio_movil_peso_kg)->toBeNull()
        ->and($metrica->promedio_movil_deficit_kcal)->toBeNull()
        ->and((float) $metrica->indice_consistencia_pct)->toBe(0.0)
        ->and($metrica->dias_con_datos)->toBe(0)
        ->and($metrica->tendencia)->toBeNull();
});

it('no mezcla los registros de otros usuarios en el promedio', function () {
    $usuario = User::factory()->create();
    $otro = User::factory()->create();

    foreach (range(0, 6) as $diasAtras) {
        diaConPeso($usuario, $diasAtras, 80.0);
        diaConPeso($otro, $diasAtras, 120.0);
    }

    $metricas = (new TrendAnalyticsService)->calcular($usuario, corteDePrueba());

    expect($metricas['promedio_movil_peso_kg'])->toEqualWithDelta(80.0, 0.0001)
        ->and($metricas['dias_con_datos'])->toBe(7);
});

it('devuelve una serie histórica con un punto por día y la ventana móvil de cada uno', function () {
    $usuario = User::factory()->create();

    // 10 días: del más antiguo (9) al de corte (0), bajando 0.1 kg por día.
    foreach (range(0, 9) as $diasAtras) {
        diaConPeso($usuario, $diasAtras, 80.0 + $diasAtras * 0.1);
    }

    $serie = (new TrendAnalyticsService)->serieHistorica($usuario, 3, corteDePrueba());

    expect($serie)->toHaveCount(3)
        ->and(array_column($serie, 'fecha'))->toBe(['2026-03-13', '2026-03-14', '2026-03-15']);

    // Último punto = ventana 2026-03-09..2026-03-15:
    // (80.6 + 80.5 + 80.4 + 80.3 + 80.2 + 80.1 + 80.0) / 7 = 562.1 / 7 = 80.3
    expect($serie[2]['promedio_movil_peso_kg'])->toEqualWithDelta(80.3, 0.0001)
        // Primer punto = ventana 2026-03-07..2026-03-13, dos días más arriba: 80.5
        ->and($serie[0]['promedio_movil_peso_kg'])->toEqualWithDelta(80.5, 0.0001);
});

it('devuelve una serie histórica con huecos, no una excepción, cuando faltan días', function () {
    $usuario = User::factory()->create();

    $serie = (new TrendAnalyticsService)->serieHistorica($usuario, 30, corteDePrueba());

    expect($serie)->toHaveCount(30)
        ->and(array_unique(array_column($serie, 'promedio_movil_peso_kg')))->toBe([null]);
});

it('calcula las variaciones semanales de peso comparando promedios móviles de 7 días consecutivos', function () {
    $usuario = User::factory()->create();

    // Cuatro bloques de 7 días, cada uno con un peso constante dentro del
    // bloque: así el promedio móvil de cada ventana es exactamente ese valor
    // y la variación semana a semana es fácil de verificar a mano.
    $bloques = [
        // diasAtras 21-27 (semana más antigua) => 79.5
        [21, 27, 79.5],
        // diasAtras 14-20 => 79.0
        [14, 20, 79.0],
        // diasAtras 7-13 => 78.5
        [7, 13, 78.5],
        // diasAtras 0-6 (semana más reciente) => 78.0
        [0, 6, 78.0],
    ];

    foreach ($bloques as [$desde, $hasta, $peso]) {
        for ($diasAtras = $desde; $diasAtras <= $hasta; $diasAtras++) {
            diaConPeso($usuario, $diasAtras, $peso);
        }
    }

    $variaciones = (new TrendAnalyticsService)->variacionesSemanalesPesoKg($usuario, 3, corteDePrueba());

    // De la más antigua a la más reciente: 79.0-79.5, 78.5-79.0, 78.0-78.5.
    expect($variaciones)->toHaveCount(3);
    foreach ($variaciones as $variacion) {
        expect($variacion)->toEqualWithDelta(-0.5, 0.001);
    }
});

it('omite una variación semanal cuando falta el promedio de una de las dos semanas comparadas', function () {
    $usuario = User::factory()->create();

    // La semana intermedia (diasAtras 14-20) no tiene ningún peso apuntado:
    // su promedio es null, así que las dos variaciones que la involucran se
    // omiten en vez de compararse contra un dato inexistente.
    foreach ([21, 22, 23, 24, 25, 26, 27] as $diasAtras) {
        diaConPeso($usuario, $diasAtras, 79.5);
    }
    foreach ([7, 8, 9, 10, 11, 12, 13] as $diasAtras) {
        diaConPeso($usuario, $diasAtras, 78.5);
    }
    foreach ([0, 1, 2, 3, 4, 5, 6] as $diasAtras) {
        diaConPeso($usuario, $diasAtras, 78.0);
    }

    $variaciones = (new TrendAnalyticsService)->variacionesSemanalesPesoKg($usuario, 3, corteDePrueba());

    expect($variaciones)->toHaveCount(1)
        ->and($variaciones[0])->toEqualWithDelta(-0.5, 0.001);
});
