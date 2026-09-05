<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('Mi progreso') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            @unless ($metricas['datos_suficientes'])
                <div class="p-4 bg-amber-50 border border-amber-200 text-amber-800 rounded-lg text-sm">
                    {{ __('Todavía no hay 7 días de historial') }}
                    ({{ trans_choice(':count día registrado|:count días registrados', $metricas['dias_con_datos']) }}).
                    {{ __('Las cifras de abajo se calculan con lo que hay, pero una tendencia solo es fiable con la ventana completa de 7 días.') }}
                </div>
            @endunless

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-6">
                <div class="p-4 sm:p-6 bg-white shadow sm:rounded-lg">
                    <p class="text-sm text-gray-500">{{ __('Peso (promedio móvil 7 días)') }}</p>
                    <p class="mt-2 text-2xl font-semibold text-gray-900">
                        @if ($metricas['promedio_movil_peso_kg'] === null)
                            <span class="text-gray-400">{{ __('Sin datos') }}</span>
                        @else
                            {{ number_format($metricas['promedio_movil_peso_kg'], 2) }} kg
                        @endif
                    </p>
                    @if ($metricas['porcentaje_perdida_semanal'] !== null)
                        <p class="mt-1 text-sm {{ $metricas['porcentaje_perdida_semanal'] >= 0 ? 'text-green-700' : 'text-red-700' }}">
                            {{ number_format($metricas['porcentaje_perdida_semanal'], 2) }}%
                            {{ __('semanal') }}
                            @if ($metricas['tendencia'])
                                <span class="text-gray-500">({{ str_replace('_', ' ', $metricas['tendencia']) }})</span>
                            @endif
                        </p>
                    @endif
                </div>

                <div class="p-4 sm:p-6 bg-white shadow sm:rounded-lg">
                    <p class="text-sm text-gray-500">{{ __('Déficit promedio (7 días)') }}</p>
                    <p class="mt-2 text-2xl font-semibold {{ ($metricas['promedio_movil_deficit_kcal'] ?? 0) >= 0 ? 'text-green-700' : 'text-red-700' }}">
                        @if ($metricas['promedio_movil_deficit_kcal'] === null)
                            <span class="text-gray-400">{{ __('Sin datos') }}</span>
                        @else
                            {{ number_format($metricas['promedio_movil_deficit_kcal'], 0) }} kcal
                        @endif
                    </p>
                    <p class="mt-1 text-sm text-gray-500">
                        {{ __('Solo cuentan los días ya cerrados.') }}
                    </p>
                </div>

                <div class="p-4 sm:p-6 bg-white shadow sm:rounded-lg">
                    <p class="text-sm text-gray-500">{{ __('Índice de consistencia') }}</p>
                    <p class="mt-2 text-2xl font-semibold text-gray-900">
                        {{ number_format($metricas['indice_consistencia_pct'], 0) }}%
                    </p>
                    <p class="mt-1 text-sm text-gray-500">
                        {{ $metricas['dias_cerrados'] }} {{ __('de 7 días cerrados') }}
                    </p>
                </div>
            </div>

            <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg">
                <h3 class="text-lg font-medium text-gray-900">
                    {{ __('Evolución del promedio móvil de peso') }}
                </h3>
                <p class="mt-1 text-sm text-gray-600">
                    {{ __('Cada punto es el promedio de los 7 días anteriores, no el peso de ese día: es lo que suaviza el ruido diario de la báscula.') }}
                </p>

                <div class="mt-6 h-72">
                    <canvas id="grafico-peso"
                            data-serie="{{ json_encode($serie) }}"
                            aria-label="{{ __('Evolución del promedio móvil de peso') }}"
                            role="img"></canvas>
                </div>
            </div>
        </div>
    </div>

    @push('scripts')
        <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js" defer></script>
        <script>
            document.addEventListener('DOMContentLoaded', function () {
                var canvas = document.getElementById('grafico-peso');

                // Sin CDN disponible la página sigue siendo útil: las tres cifras
                // de arriba son server-rendered y no dependen de este script.
                if (! canvas || typeof Chart === 'undefined') {
                    return;
                }

                var serie = JSON.parse(canvas.dataset.serie);

                new Chart(canvas, {
                    type: 'line',
                    data: {
                        labels: serie.map(function (punto) {
                            return punto.fecha.slice(8, 10) + '/' + punto.fecha.slice(5, 7);
                        }),
                        datasets: [{
                            label: 'Promedio móvil de peso (kg)',
                            data: serie.map(function (punto) {
                                return punto.promedio_movil_peso_kg;
                            }),
                            borderColor: '#4f46e5',
                            backgroundColor: 'rgba(79, 70, 229, 0.1)',
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
                            y: {
                                beginAtZero: false,
                                ticks: {
                                    callback: function (valor) {
                                        return valor + ' kg';
                                    },
                                },
                            },
                        },
                        plugins: {
                            legend: { display: false },
                            tooltip: {
                                callbacks: {
                                    label: function (contexto) {
                                        return contexto.parsed.y + ' kg';
                                    },
                                },
                            },
                        },
                    },
                });
            });
        </script>
    @endpush
</x-app-layout>
