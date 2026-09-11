@props([
    // El resumen que devuelven DailyClosureService::resumen()/cerrar(): mismas
    // claves para un día abierto (vista previa) o cerrado (snapshot congelado).
    'resumen',
    // Cambia solo el rótulo: "Resultado real del día" vs. "Lo que llevas comido".
    'cerrado' => false,
])

@php
    $kcal = fn ($valor) => number_format((float) $valor, 0, ',', '.');
    $gramos = fn ($valor) => number_format((float) $valor, 1, ',', '.');
    $porcentaje = fn ($parte, $total) => $total > 0 ? max(0, min(100, round($parte / $total * 100))) : 0;
@endphp

{{--
    ── Resultado real del día (CLAUDE.md sección 5.5) ──
    El espejo de "Objetivo del día": las mismas cuatro cifras, pero las que se
    comieron de verdad. Un solo partial para el cierre del plan diario e
    Inicio (sección 5.8): las dos pantallas leen el mismo
    DailyClosureService::resumen() y no hay motivo para pintarlo dos veces.
--}}
<div {{ $attributes->merge(['class' => 'tudi-card-inset rounded-tudi-sm']) }}>
    <div class="flex items-end justify-between gap-4">
        <span class="tudi-label pb-1">
            {{ $cerrado ? __('Resultado real del día') : __('Lo que llevas comido') }}
        </span>
        <span class="flex items-baseline gap-1.5">
            <span class="tudi-num text-[30px]">{{ $kcal($resumen['calorias_consumidas']) }}</span>
            <span class="tudi-meta">/ {{ $kcal($resumen['calorias_objetivo']) }} kcal</span>
        </span>
    </div>

    <div class="mt-3 space-y-3">
        @foreach ([
            ['tipo' => 'proteina', 'real' => $resumen['proteina_consumida_g'], 'objetivo' => $resumen['proteina_objetivo_g'], 'color' => 'var(--tudi-lime-700)'],
            ['tipo' => 'grasa', 'real' => $resumen['grasa_consumida_g'], 'objetivo' => $resumen['grasa_objetivo_g'], 'color' => 'var(--tudi-amber)'],
            ['tipo' => 'carbohidratos', 'real' => $resumen['carbohidratos_consumidos_g'], 'objetivo' => $resumen['carbohidratos_objetivo_g'], 'color' => 'var(--tudi-ink)'],
        ] as $macro)
            <div>
                <div class="flex items-baseline justify-between gap-2">
                    <span class="flex items-center gap-2">
                        <x-tudi.macro :tipo="$macro['tipo']" />
                        <x-tudi.macro :tipo="$macro['tipo']" variante="palabra" class="text-[13px] font-semibold" />
                    </span>
                    <span class="tudi-meta">
                        {{-- Los días cerrados antes de que el snapshot guardara grasa y
                             carbohidratos no tienen estas cifras: se dicen ausentes, no cero. --}}
                        @if ($macro['real'] === null || $macro['objetivo'] === null)
                            —
                        @else
                            <span class="text-tudi-ink">{{ $gramos($macro['real']) }}</span>
                            / {{ $gramos($macro['objetivo']) }} g
                        @endif
                    </span>
                </div>
                <span class="tudi-bar on-light mt-1.5 block"
                      style="--pct: {{ $macro['real'] === null || $macro['objetivo'] === null ? 0 : $porcentaje($macro['real'], $macro['objetivo']) }}">
                    <span style="background: {{ $macro['color'] }}"></span>
                </span>
            </div>
        @endforeach
    </div>

    {{--
        El déficit sí se queda: es la única de las cifras del cierre que no
        está ya en las barras de arriba —incluye el gasto por actividad, que
        no es un macro— y es el número que persigue todo el producto
        (sección 7).
    --}}
    <div class="mt-4 flex flex-wrap items-baseline justify-between gap-2 border-t border-tudi-border pt-3">
        <span class="tudi-label">{{ __('Déficit estimado') }}</span>
        <span class="flex items-baseline gap-1.5">
            <span @class([
                'tudi-num text-xl',
                'text-tudi-lime-700' => $resumen['deficit_diario'] >= 0,
                'text-tudi-amber-ink' => $resumen['deficit_diario'] < 0,
            ])>{{ $kcal($resumen['deficit_diario']) }}</span>
            <span class="tudi-meta">
                kcal
                @if ($resumen['calorias_actividad_ajustada'] > 0)
                    · {{ __('incluye :kcal de actividad', ['kcal' => $kcal($resumen['calorias_actividad_ajustada'])]) }}
                @endif
            </span>
        </span>
    </div>
</div>
