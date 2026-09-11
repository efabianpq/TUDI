@props([
    // La serie de TrendAnalyticsService::serieHistorica(): un elemento por día,
    // con `fecha` y `peso_kg` (null los días sin pesaje).
    'serie',
    // Alto del trazo. El ancho siempre es el del contenedor.
    'alto' => 'h-16',
])

@php
    /*
     * Sparkline de pesajes reales (CLAUDE.md sección 5.8).
     *
     * Se dibuja en el servidor como SVG en línea: no hace falta Chart.js desde
     * un CDN, funciona con JavaScript desactivado y no añade una petición
     * externa a una pantalla que ya carga en un solo viaje.
     *
     * Los días sin pesaje NO se rellenan ni se interpolan: solo se dibujan los
     * días que de verdad tienen un número en la báscula, en su posición real
     * dentro de la ventana (sección 5.7: un día sin peso no es un cero).
     *
     * Esto es geometría de presentación, no cálculo de dominio: no entra
     * ninguna cifra nueva al balance energético (regla 7 de la sección 13).
     */
    $dias = count($serie);

    // Posición horizontal por el índice del día dentro de la ventana, para que
    // un hueco de tres días se vea como un hueco de tres días.
    $puntos = [];

    foreach (array_values($serie) as $indice => $punto) {
        if (($punto['peso_kg'] ?? null) === null) {
            continue;
        }

        $puntos[] = [
            'x' => $dias > 1 ? $indice / ($dias - 1) * 100 : 50.0,
            'peso' => (float) $punto['peso_kg'],
            'fecha' => $punto['fecha'],
        ];
    }

    $pesos = array_column($puntos, 'peso');
    $minimo = $pesos === [] ? 0.0 : min($pesos);
    $maximo = $pesos === [] ? 0.0 : max($pesos);
    $rango = $maximo - $minimo;

    // Margen vertical para que un punto en el extremo no quede cortado por el
    // borde del trazo. Con todos los pesos iguales, la línea va por el centro.
    foreach ($puntos as $indice => $punto) {
        $puntos[$indice]['y'] = $rango > 0
            ? 92 - ($punto['peso'] - $minimo) / $rango * 84
            : 50.0;
    }

    $linea = implode(' ', array_map(
        fn (array $punto): string => round($punto['x'], 2).','.round($punto['y'], 2),
        $puntos,
    ));

    $gramos = fn ($valor) => number_format((float) $valor, 2, ',', '.');
@endphp

@if ($puntos === [])
    <p {{ $attributes->merge(['class' => 'tudi-meta']) }}>
        {{ __('Apunta tu peso en el plan del día para ver tu evolución.') }}
    </p>
@else
    <div {{ $attributes->merge(['class' => 'relative w-full '.$alto]) }}
         role="img"
         aria-label="{{ trans_choice(':count pesaje registrado|:count pesajes registrados', count($puntos)) }}: {{ $gramos($puntos[0]['peso']) }} kg → {{ $gramos(end($puntos)['peso']) }} kg">
        @if (count($puntos) > 1)
            {{--
                preserveAspectRatio="none" estira el trazo a lo ancho del
                contenedor; `vector-effect` mantiene el grosor de la línea
                constante pese al estirado. Los puntos NO van aquí dentro: un
                <circle> estirado se vería como una elipse.
            --}}
            <svg viewBox="0 0 100 100" preserveAspectRatio="none"
                 class="absolute inset-0 h-full w-full" aria-hidden="true" focusable="false">
                <polyline points="{{ $linea }}" fill="none"
                          stroke="var(--tudi-input-border)" stroke-width="2"
                          stroke-linecap="round" stroke-linejoin="round"
                          vector-effect="non-scaling-stroke" />
            </svg>
        @endif

        {{-- Los puntos son elementos HTML colocados en porcentajes: así son
             círculos de verdad en cualquier ancho de pantalla. --}}
        @foreach ($puntos as $indice => $punto)
            <span class="absolute h-2 w-2 -translate-x-1/2 -translate-y-1/2 rounded-full {{ $indice === count($puntos) - 1 ? 'bg-tudi-lime-700' : 'bg-tudi-muted' }}"
                  style="left: {{ round($punto['x'], 2) }}%; top: {{ round($punto['y'], 2) }}%"></span>
        @endforeach
    </div>
@endif
