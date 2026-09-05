<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('Dashboard') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            @if (session('error'))
                <div class="p-4 bg-red-50 border border-red-200 text-red-700 rounded-lg">
                    {{ session('error') }}
                </div>
            @endif

            @if (session('status') === 'recomendacion-confirmada')
                <div class="p-4 bg-green-50 border border-green-200 text-green-700 rounded-lg">
                    {{ __('Recomendación confirmada.') }}
                </div>
            @endif

            @if (session('status') === 'recomendacion-rechazada')
                <div class="p-4 bg-green-50 border border-green-200 text-green-700 rounded-lg">
                    {{ __('Recomendación rechazada.') }}
                </div>
            @endif

            {{-- Resumen del día --}}
            <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg">
                <h3 class="text-lg font-medium text-gray-900">
                    {{ __('Resumen de hoy') }}
                    <span class="text-sm font-normal text-gray-500">
                        ({{ now()->format('d/m/Y') }})
                    </span>
                </h3>

                @if ($errorResumen)
                    <p class="mt-4 text-sm text-red-700">
                        {{ $errorResumen }}
                        <a href="{{ route('profile.parametros.edit') }}" class="underline">{{ __('Ir a mis parámetros') }}</a>
                    </p>
                @elseif (! $registroDiario || ! $resumen)
                    <p class="mt-4 text-sm text-gray-600">
                        {{ __('Todavía no hay nada registrado hoy.') }}
                        <a href="{{ route('ingredientes.create') }}" class="text-indigo-600 underline">{{ __('Reportar ingredientes') }}</a>
                    </p>
                @else
                    <dl class="mt-6 grid grid-cols-1 sm:grid-cols-3 gap-6">
                        <div>
                            <dt class="text-sm text-gray-500">{{ __('Calorías consumidas vs. objetivo') }}</dt>
                            <dd class="mt-1 text-gray-900">
                                <strong>{{ number_format($resumen['calorias_consumidas'], 0) }}</strong>
                                {{ __('de') }}
                                <strong>{{ number_format($resumen['calorias_objetivo'], 0) }} kcal</strong>
                            </dd>
                        </div>

                        <div>
                            <dt class="text-sm text-gray-500">{{ __('Gasto por actividad (ajustado)') }}</dt>
                            <dd class="mt-1 text-gray-900">
                                <strong>{{ number_format($resumen['calorias_actividad_ajustada'], 0) }} kcal</strong>
                            </dd>
                        </div>

                        <div>
                            <dt class="text-sm text-gray-500">{{ __('Déficit calórico estimado') }}</dt>
                            <dd class="mt-1 {{ $resumen['deficit_diario'] >= 0 ? 'text-green-700' : 'text-red-700' }}">
                                <strong>{{ number_format($resumen['deficit_diario'], 0) }} kcal</strong>
                            </dd>
                        </div>
                    </dl>

                    <div class="mt-6">
                        <dt class="text-sm text-gray-500">{{ __('Estado de tus comidas') }}</dt>
                        <ul class="mt-2 flex flex-wrap gap-2">
                            @foreach ($estadoComidas as $comida)
                                <li>
                                    <span @class([
                                        'inline-flex items-center px-3 py-1 rounded-full text-xs font-medium',
                                        'bg-gray-100 text-gray-600' => $comida['estado'] === 'pendiente',
                                        'bg-amber-100 text-amber-800' => $comida['estado'] === 'planificada',
                                        'bg-green-100 text-green-800' => $comida['estado'] === 'registrada',
                                    ])>
                                        {{ ucfirst($comida['tipo']) }}: {{ __($comida['estado']) }}
                                    </span>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            </div>

            {{-- Tendencias --}}
            <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg">
                <h3 class="text-lg font-medium text-gray-900">{{ __('Tendencias (últimos 7 días)') }}</h3>

                @unless ($metricas['datos_suficientes'])
                    <div class="mt-4 p-4 bg-amber-50 border border-amber-200 text-amber-800 rounded-lg text-sm">
                        {{ __('Todavía no hay 7 días de historial') }}
                        ({{ trans_choice(':count día registrado|:count días registrados', $metricas['dias_con_datos']) }}).
                    </div>
                @endunless

                <div class="mt-6 grid grid-cols-1 sm:grid-cols-3 gap-6">
                    <div>
                        <p class="text-sm text-gray-500">{{ __('Peso (promedio móvil)') }}</p>
                        <p class="mt-1 text-xl font-semibold text-gray-900">
                            @if ($metricas['promedio_movil_peso_kg'] === null)
                                <span class="text-gray-400">{{ __('Sin datos') }}</span>
                            @else
                                {{ number_format($metricas['promedio_movil_peso_kg'], 2) }} kg
                            @endif
                        </p>
                    </div>

                    <div>
                        <p class="text-sm text-gray-500">{{ __('Déficit promedio') }}</p>
                        <p class="mt-1 text-xl font-semibold {{ ($metricas['promedio_movil_deficit_kcal'] ?? 0) >= 0 ? 'text-green-700' : 'text-red-700' }}">
                            @if ($metricas['promedio_movil_deficit_kcal'] === null)
                                <span class="text-gray-400">{{ __('Sin datos') }}</span>
                            @else
                                {{ number_format($metricas['promedio_movil_deficit_kcal'], 0) }} kcal
                            @endif
                        </p>
                    </div>

                    <div>
                        <p class="text-sm text-gray-500">{{ __('Índice de consistencia') }}</p>
                        <p class="mt-1 text-xl font-semibold text-gray-900">
                            {{ number_format($metricas['indice_consistencia_pct'], 0) }}%
                        </p>
                        <p class="text-sm text-gray-500">{{ $metricas['dias_cerrados'] }} {{ __('de 7 días cerrados') }}</p>
                    </div>
                </div>

                <div class="mt-6 h-64">
                    <canvas id="grafico-peso-dashboard"
                            data-serie="{{ json_encode($serie) }}"
                            aria-label="{{ __('Evolución del promedio móvil de peso') }}"
                            role="img"></canvas>
                </div>

                <p class="mt-4 text-sm">
                    <a href="{{ route('progreso.index') }}" class="text-indigo-600 underline">{{ __('Ver mi progreso completo') }}</a>
                </p>
            </div>

            {{-- Recomendaciones pendientes --}}
            <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg">
                <h3 class="text-lg font-medium text-gray-900">{{ __('Recomendaciones pendientes') }}</h3>

                @if ($recomendacionesPendientes->isEmpty())
                    <p class="mt-2 text-sm text-gray-600">
                        {{ __('No tienes recomendaciones pendientes de confirmar.') }}
                    </p>
                @else
                    <ul class="mt-4 divide-y text-sm">
                        @foreach ($recomendacionesPendientes as $recomendacion)
                            <li class="py-3">
                                <span class="text-gray-900">{{ $recomendacion->justificacion }}</span>
                                @if ($recomendacion->calorias_objetivo_sugeridas)
                                    <span class="text-gray-500">({{ $recomendacion->calorias_objetivo_sugeridas }} kcal)</span>
                                @endif

                                <div class="mt-2 flex gap-2">
                                    <form method="post" action="{{ route('recomendaciones.confirmar', $recomendacion) }}">
                                        @csrf
                                        <x-primary-button type="submit">{{ __('Confirmar') }}</x-primary-button>
                                    </form>
                                    <form method="post" action="{{ route('recomendaciones.rechazar', $recomendacion) }}">
                                        @csrf
                                        <x-secondary-button type="submit">{{ __('Rechazar') }}</x-secondary-button>
                                    </form>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </div>
    </div>

    @push('scripts')
        <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js" defer></script>
        <script>
            document.addEventListener('DOMContentLoaded', function () {
                var canvas = document.getElementById('grafico-peso-dashboard');

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
