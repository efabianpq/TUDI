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

<section class="space-y-3">
    <p class="tudi-label px-1">{{ __('Tu tendencia') }}</p>

    @if (! $tendencia['suficiente'])
        <div class="tudi-card p-5">
            <x-tudi.diagnostico-checklist :diagnostico="$tendencia['diagnostico']" />
        </div>
    @else
        {{--
            Dos tarjetas separadas a propósito, nunca fundidas en una sola
            cifra: el pesaje real de esta mañana y el promedio móvil son dos
            números distintos, y confundirlos es justo lo que rompía la
            confianza en la app.
        --}}
        <div class="grid gap-3 sm:grid-cols-2">
            <div class="rounded-tudi-xl bg-tudi-surface p-5">
                <p class="tudi-label">{{ __('Último peso registrado') }}</p>
                @if ($tendencia['ultimoPeso'])
                    <p class="tudi-num mt-1.5 text-[28px]">{{ $kg($tendencia['ultimoPeso']['peso_kg']) }} kg</p>
                    <p class="text-[13px] text-tudi-muted">{{ $tendencia['ultimoPeso']['fecha']->diffForHumans() }}</p>
                @else
                    <p class="tudi-num mt-1.5 text-[28px] text-tudi-muted">—</p>
                    <p class="text-[13px] text-tudi-muted">{{ __('Todavía no has apuntado tu peso.') }}</p>
                @endif
            </div>

            <div class="rounded-tudi-xl bg-tudi-surface p-5">
                <p class="tudi-label">{{ __('Tendencia · media 7 días') }}</p>
                <p class="tudi-num mt-1.5 text-[28px]">
                    {{ $tendencia['metricas']['promedio_movil_peso_kg'] === null ? '—' : $kg($tendencia['metricas']['promedio_movil_peso_kg']).' kg' }}
                </p>
                @if ($tendencia['metricas']['porcentaje_perdida_semanal'] !== null)
                    <p @class([
                        'text-[13px] font-semibold',
                        'text-tudi-lime-700' => $tendencia['metricas']['porcentaje_perdida_semanal'] >= 0,
                        'text-tudi-amber-ink' => $tendencia['metricas']['porcentaje_perdida_semanal'] < 0,
                    ])>
                        {{ number_format($tendencia['metricas']['porcentaje_perdida_semanal'], 2, ',', '.') }}% {{ __('semanal') }}
                    </p>
                @endif
                <p class="mt-1 text-[12px] text-tudi-muted">{{ __('No es tu peso de hoy: es el promedio de los últimos 7 días.') }}</p>
            </div>
        </div>

        {{--
            Sparkline de pesajes reales, sin rellenar los días sin dato
            (TrendAnalyticsService::serieHistorica) — distinto del promedio
            móvil de más abajo, que sí conecta a través de los huecos.
        --}}
        <div class="tudi-card p-5">
            <p class="tudi-label">{{ __('Tus pesajes') }}</p>
            <div class="mt-4 h-24">
                <canvas id="grafico-peso-real"
                        data-serie="{{ json_encode($tendencia['serie']) }}"
                        aria-label="{{ __('Pesajes reales registrados') }}"
                        role="img"></canvas>
            </div>
        </div>

        <div class="grid gap-3 sm:grid-cols-2">
            <div class="rounded-tudi-xl bg-tudi-surface p-5">
                <p class="tudi-label">{{ __('Déficit promedio') }}</p>
                <p class="tudi-num mt-1.5 text-[28px]">
                    {{ $tendencia['metricas']['promedio_movil_deficit_kcal'] === null ? '—' : $kcal($tendencia['metricas']['promedio_movil_deficit_kcal']).' kcal' }}
                </p>
                <p class="text-[13px] text-tudi-muted">
                    {{ trans_choice('sobre :count día cerrado|sobre :count días cerrados', $tendencia['metricas']['dias_cerrados']) }}
                </p>
            </div>

            <div class="rounded-tudi-xl bg-tudi-surface p-5">
                <p class="tudi-label">{{ __('Racha') }}</p>
                <p class="tudi-num mt-1.5 text-[28px]">
                    {{ $tendencia['racha'] }} <span class="text-base font-normal text-tudi-ink-3">{{ trans_choice('día seguido|días seguidos', $tendencia['racha']) }}</span>
                </p>
                <div class="mt-2.5 flex gap-1.5" role="img"
                     aria-label="{{ $tendencia['metricas']['dias_cerrados'] }} {{ __('de 7 días cerrados') }}">
                    @for ($dia = 1; $dia <= 7; $dia++)
                        <span @class([
                            'h-[26px] w-[26px] rounded-full',
                            'bg-tudi-lime' => $dia <= $tendencia['metricas']['dias_cerrados'],
                            'bg-tudi-input-border' => $dia > $tendencia['metricas']['dias_cerrados'],
                        ])></span>
                    @endfor
                </div>
                <p class="mt-2 text-[13px] text-tudi-muted">
                    {{ $tendencia['metricas']['dias_cerrados'] }} {{ __('de 7 días cerrados') }}
                </p>
            </div>
        </div>

        <div class="tudi-card p-5">
            <p class="tudi-label">{{ __('Evolución del promedio móvil de peso') }}</p>
            <div class="mt-4 h-56 sm:h-64">
                <canvas id="grafico-peso"
                        data-serie="{{ json_encode($tendencia['serie']) }}"
                        aria-label="{{ __('Evolución del promedio móvil de peso') }}"
                        role="img"></canvas>
            </div>
        </div>

        @push('scripts')
            <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js" defer></script>
            <script>
                document.addEventListener('DOMContentLoaded', function () {
                    if (typeof Chart === 'undefined') {
                        return;
                    }

                    function etiqueta(fecha) {
                        return fecha.slice(8, 10) + '/' + fecha.slice(5, 7);
                    }

                    var canvasPromedio = document.getElementById('grafico-peso');

                    if (canvasPromedio) {
                        var serie = JSON.parse(canvasPromedio.dataset.serie);

                        new Chart(canvasPromedio, {
                            type: 'line',
                            data: {
                                labels: serie.map(function (punto) { return etiqueta(punto.fecha); }),
                                datasets: [{
                                    label: 'Promedio móvil de peso (kg)',
                                    data: serie.map(function (punto) { return punto.promedio_movil_peso_kg; }),
                                    borderColor: '#5C6B14',
                                    backgroundColor: 'rgba(200, 240, 60, 0.22)',
                                    borderWidth: 2,
                                    pointRadius: 2,
                                    tension: 0.3,
                                    fill: true,
                                    spanGaps: true,
                                }],
                            },
                            options: {
                                responsive: true,
                                maintainAspectRatio: false,
                                scales: {
                                    x: { grid: { color: '#E4DCCC' }, ticks: { color: '#6B665C' } },
                                    y: {
                                        beginAtZero: false,
                                        grid: { color: '#E4DCCC' },
                                        ticks: { color: '#6B665C', callback: function (valor) { return valor + ' kg'; } },
                                    },
                                },
                                plugins: {
                                    legend: { display: false },
                                    tooltip: { callbacks: { label: function (c) { return c.parsed.y + ' kg'; } } },
                                },
                            },
                        });
                    }

                    // Pesajes reales: puntos sueltos, sin conectar los días sin
                    // dato (spanGaps: false), a diferencia del promedio móvil de
                    // arriba, que sí atraviesa los huecos.
                    var canvasReal = document.getElementById('grafico-peso-real');

                    if (canvasReal) {
                        var serieReal = JSON.parse(canvasReal.dataset.serie);

                        new Chart(canvasReal, {
                            type: 'line',
                            data: {
                                labels: serieReal.map(function (punto) { return etiqueta(punto.fecha); }),
                                datasets: [{
                                    label: 'Peso registrado (kg)',
                                    data: serieReal.map(function (punto) { return punto.peso_kg; }),
                                    borderColor: 'transparent',
                                    backgroundColor: '#4A463E',
                                    pointBackgroundColor: '#4A463E',
                                    pointRadius: 3,
                                    showLine: false,
                                    spanGaps: false,
                                }],
                            },
                            options: {
                                responsive: true,
                                maintainAspectRatio: false,
                                scales: {
                                    x: { grid: { display: false }, ticks: { color: '#6B665C', maxTicksLimit: 6 } },
                                    y: {
                                        beginAtZero: false,
                                        grid: { color: '#E4DCCC' },
                                        ticks: { color: '#6B665C', callback: function (valor) { return valor + ' kg'; } },
                                    },
                                },
                                plugins: {
                                    legend: { display: false },
                                    tooltip: { callbacks: { label: function (c) { return c.parsed.y + ' kg'; } } },
                                },
                            },
                        });
                    }
                });
            </script>
        @endpush
    @endif
</section>
