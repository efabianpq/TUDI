{{--
    Bloque 3 — Tu tendencia (CLAUDE.md sección 5.8): sin promedio móvil de
    fiar (menos de 7 días de historial) se enseña el mismo checklist que ya
    usa el cierre del plan diario — nunca un gráfico vacío ni un promedio con
    un solo dato.
--}}
@php
    $kcal = fn ($valor) => number_format((float) $valor, 0, ',', '.');
    $kg = fn ($valor) => number_format((float) $valor, 2, ',', '.');
@endphp

@if (! $tendencia['suficiente'])
    <section class="space-y-3">
        <p class="tudi-label px-1">{{ __('Tu tendencia') }}</p>

        <div class="tudi-card p-5">
            <x-tudi.diagnostico-checklist :diagnostico="$tendencia['diagnostico']" />
        </div>
    </section>
@else
    @php
        $metricas = $tendencia['metricas'];
        $ritmo = $metricas['porcentaje_perdida_semanal'];

        /*
         * `fecha` es una fecha sin hora, así que diffForHumans() diría "hace 9
         * horas" por la distancia a la medianoche — una precisión que el dato
         * no tiene. Se cuenta en días, que es la granularidad real del pesaje.
         */
        // (int) a propósito: Carbon devuelve un float y "=== 0" nunca casaría.
        $diasDesdeElPesaje = $tendencia['ultimoPeso']
            ? (int) $tendencia['ultimoPeso']['fecha']->copy()->startOfDay()->diffInDays(now()->startOfDay())
            : null;

        $cuandoSePeso = match (true) {
            $diasDesdeElPesaje === null => null,
            $diasDesdeElPesaje === 0 => __('hoy'),
            $diasDesdeElPesaje === 1 => __('ayer'),
            default => __('hace :dias días', ['dias' => $diasDesdeElPesaje]),
        };
    @endphp

    <section>
        <div class="tudi-card p-5 sm:p-6">
            <p class="tudi-label">{{ __('Tu tendencia') }}</p>

            {{--
                Dos tarjetas separadas a propósito, nunca fundidas en una sola
                cifra: el pesaje real y el promedio móvil son dos números
                distintos, y confundirlos es lo que rompía la confianza en la
                app (el usuario compara contra su báscula de esta mañana).
            --}}
            <div class="mt-3 grid grid-cols-2 gap-3">
                <div class="tudi-card-inset rounded-tudi-sm">
                    <p class="tudi-label">{{ __('Último peso registrado') }}</p>
                    @if ($tendencia['ultimoPeso'])
                        <p class="tudi-num mt-1.5 text-[22px]">{{ $kg($tendencia['ultimoPeso']['peso_kg']) }} kg</p>
                        <p class="tudi-meta mt-0.5">{{ $cuandoSePeso }}</p>
                    @else
                        <p class="tudi-num mt-1.5 text-[22px] text-tudi-muted">—</p>
                        <p class="tudi-meta mt-0.5">{{ __('sin pesajes todavía') }}</p>
                    @endif
                </div>

                <div class="tudi-card-inset rounded-tudi-sm">
                    <p class="tudi-label">{{ __('Tendencia 7 días') }}</p>
                    @if ($ritmo !== null)
                        <p @class([
                            'tudi-num mt-1.5 text-[22px]',
                            'text-tudi-lime-700' => $ritmo >= 0,
                            'text-tudi-amber-ink' => $ritmo < 0,
                        ])>
                            {{-- La flecha dice la dirección sin depender del signo ni del color. --}}
                            {{ $ritmo >= 0 ? '↓' : '↑' }} {{ number_format(abs($ritmo), 2, ',', '.') }}%
                        </p>
                    @else
                        <p class="tudi-num mt-1.5 text-[22px] text-tudi-muted">—</p>
                    @endif
                    <p class="tudi-meta mt-0.5">{{ __('no es tu peso de hoy') }}</p>
                </div>
            </div>

            {{-- Pesajes reales, sin rellenar los días sin dato (sección 5.7). --}}
            <x-tudi.sparkline :serie="$tendencia['serie']" class="mt-5" />

            <dl class="mt-5 border-t border-tudi-divider">
                <div class="flex items-baseline justify-between gap-3 border-b border-tudi-divider py-3">
                    <dt class="text-sm text-tudi-ink-2">{{ __('Déficit promedio') }}</dt>
                    <dd class="tudi-num text-[15px]">
                        {{ $metricas['promedio_movil_deficit_kcal'] === null
                            ? '—'
                            : $kcal($metricas['promedio_movil_deficit_kcal']).' kcal/'.__('día') }}
                    </dd>
                </div>
                <div class="flex items-baseline justify-between gap-3 py-3">
                    <dt class="text-sm text-tudi-ink-2">{{ __('Racha de días cerrados') }}</dt>
                    <dd class="tudi-num text-[15px]">
                        {{ $tendencia['racha'] }} {{ trans_choice('día|días', $tendencia['racha']) }}
                    </dd>
                </div>
            </dl>
        </div>
    </section>
@endif
