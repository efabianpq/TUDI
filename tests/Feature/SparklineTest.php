<?php

use Illuminate\Support\Facades\Blade;

/**
 * Sparkline de pesajes reales (CLAUDE.md sección 5.8).
 *
 * Se dibuja en el servidor como SVG en línea — sin Chart.js ni CDN — así que
 * lo que hay que fijar es la geometría: qué días entran, dónde cae cada punto
 * y qué pasa con los casos degenerados (sin pesajes, uno solo, todos iguales).
 */
function pintarSparkline(array $serie): string
{
    return Blade::render('<x-tudi.sparkline :serie="$serie" />', ['serie' => $serie]);
}

/**
 * @param  array<int, float|null>  $pesos
 * @return array<int, array{fecha: string, peso_kg: float|null}>
 */
function serieDePesos(array $pesos): array
{
    return collect($pesos)
        ->map(fn (?float $peso, int $indice): array => [
            'fecha' => now()->subDays(count($pesos) - 1 - $indice)->toDateString(),
            'peso_kg' => $peso,
        ])
        ->values()
        ->all();
}

it('no dibuja nada y lo dice cuando no hay ningún pesaje', function () {
    $html = pintarSparkline(serieDePesos([null, null, null]));

    expect($html)->toContain('Apunta tu peso en el plan del día')
        ->and($html)->not->toContain('<polyline');
});

it('con un solo pesaje dibuja el punto pero no una línea que no existe', function () {
    $html = pintarSparkline(serieDePesos([null, 80.0, null]));

    expect($html)->not->toContain('<polyline')
        // Tres días, el pesaje en el del medio: 1/2 del ancho.
        ->and($html)->toContain('left: 50%');
});

it('coloca cada pesaje en el día que le toca, dejando el hueco de los días sin dato', function () {
    // Cinco días: pesaje el primero y el último, nada en medio.
    $html = pintarSparkline(serieDePesos([81.0, null, null, null, 80.0]));

    expect($html)->toContain('<polyline')
        ->and($html)->toContain('left: 0%')
        ->and($html)->toContain('left: 100%')
        // El hueco NO se rellena: solo hay dos puntos, no cinco.
        ->and(substr_count($html, 'rounded-full'))->toBe(2);
});

it('el peso más alto queda arriba y el más bajo abajo', function () {
    $html = pintarSparkline(serieDePesos([82.0, 80.0]));

    // y se mide desde arriba: el peso mayor (82) va al tope del trazo (8) y el
    // menor (80) al pie (92).
    expect($html)->toContain('top: 8%')
        ->and($html)->toContain('top: 92%');
});

it('con todos los pesos iguales dibuja una línea plana en vez de dividir por cero', function () {
    $html = pintarSparkline(serieDePesos([80.0, 80.0, 80.0]));

    expect($html)->toContain('<polyline')
        ->and(substr_count($html, 'top: 50%'))->toBe(3);
});

it('describe la evolución para lectores de pantalla', function () {
    $html = pintarSparkline(serieDePesos([82.0, 80.0]));

    expect($html)->toContain('2 pesajes registrados')
        ->and($html)->toContain('82,00 kg')
        ->and($html)->toContain('80,00 kg');
});
