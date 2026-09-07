@php
    $kcal = fn ($valor) => number_format((float) $valor, 0, ',', '.');
    $kg = fn ($valor) => number_format((float) $valor, 2, ',', '.');
@endphp

<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
            <h2 class="font-semibold text-lg sm:text-xl text-gray-800 leading-tight">
                {{ __('Inicio') }}
            </h2>
            <span class="text-sm text-gray-500">{{ now()->format('d/m/Y') }}</span>
        </div>
    </x-slot>

    <div class="py-4 sm:py-8">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-4 sm:space-y-6">

            @if (session('error'))
                <div class="p-4 bg-red-50 border border-red-200 text-red-700 rounded-xl text-sm">
                    {{ session('error') }}
                </div>
            @endif

            @if (session('status') === 'recomendacion-confirmada')
                <div class="p-4 bg-green-50 border border-green-200 text-green-700 rounded-xl text-sm">
                    {{ __('Recomendación confirmada: tu objetivo calórico quedó actualizado.') }}
                </div>
            @elseif (session('status') === 'recomendacion-rechazada')
                <div class="p-4 bg-gray-50 border border-gray-200 text-gray-700 rounded-xl text-sm">
                    {{ __('Recomendación rechazada: tu objetivo calórico no cambió.') }}
                </div>
            @endif

            {{-- ══ 1. Hoy ══ --}}
            <section class="space-y-3 sm:space-y-4">
                <div class="flex flex-wrap items-baseline justify-between gap-2 px-1">
                    <h3 class="text-base sm:text-lg font-semibold text-gray-900">{{ __('Hoy') }}</h3>
                    <a href="{{ route('planes.index') }}" class="text-sm text-indigo-600 hover:underline">
                        {{ __('Ver todos mis planes diarios') }} &rarr;
                    </a>
                </div>

                <div class="bg-white shadow-sm rounded-xl p-4 sm:p-6">
                    @if ($errorResumen)
                        <p class="text-sm text-amber-800 bg-amber-50 border border-amber-200 rounded-lg p-3">
                            {{ $errorResumen }}
                            <a href="{{ route('calculadora.edit') }}" class="underline">{{ __('Ir a la Calculadora Déficit') }}</a>
                        </p>
                    @elseif (! $registroDiario || ! $resumen)
                        <p class="text-sm text-gray-600">
                            {{ __('Todavía no has creado el plan de hoy.') }}
                        </p>
                        <form method="post" action="{{ route('planes.crear') }}" class="mt-4">
                            @csrf
                            <button type="submit"
                                    class="w-full sm:w-auto inline-flex items-center justify-center rounded-lg bg-indigo-600 px-5 py-3 text-sm font-semibold text-white hover:bg-indigo-700 transition">
                                {{ __('Crear plan diario') }}
                            </button>
                        </form>
                    @else
                        <dl class="grid grid-cols-2 lg:grid-cols-4 gap-3">
                            <div class="rounded-lg bg-gray-50 p-3">
                                <dt class="text-xs text-gray-500">{{ __('Consumidas / objetivo') }}</dt>
                                <dd class="mt-0.5 text-sm font-semibold text-gray-900">
                                    {{ $kcal($resumen['calorias_consumidas']) }} / {{ $kcal($resumen['calorias_objetivo']) }} kcal
                                </dd>
                            </div>
                            <div class="rounded-lg bg-gray-50 p-3">
                                <dt class="text-xs text-gray-500">{{ __('Gasto por actividad') }}</dt>
                                <dd class="mt-0.5 text-sm font-semibold text-gray-900">
                                    {{ $kcal($resumen['calorias_actividad_ajustada']) }} kcal
                                </dd>
                            </div>
                            <div class="rounded-lg bg-gray-50 p-3">
                                <dt class="text-xs text-gray-500">{{ __('Déficit estimado') }}</dt>
                                <dd class="mt-0.5 text-sm font-semibold {{ $resumen['deficit_diario'] >= 0 ? 'text-green-700' : 'text-red-700' }}">
                                    {{ $kcal($resumen['deficit_diario']) }} kcal
                                </dd>
                            </div>
                            <div class="rounded-lg bg-gray-50 p-3">
                                <dt class="text-xs text-gray-500">{{ __('Proteína cumplida') }}</dt>
                                <dd class="mt-0.5 text-sm font-semibold text-gray-900">
                                    {{ number_format($resumen['cumplimiento_proteina_pct'], 1, ',', '.') }}%
                                </dd>
                            </div>
                        </dl>

                        <ul class="mt-4 grid grid-cols-1 sm:grid-cols-3 gap-2">
                            @foreach ($estadoComidas as $comida)
                                <li class="flex items-center justify-between gap-2 rounded-lg border border-gray-200 px-3 py-2.5">
                                    <span class="text-sm font-medium text-gray-800 capitalize">{{ $comida['tipo'] }}</span>
                                    <span @class([
                                        'inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-medium',
                                        'bg-gray-100 text-gray-600' => $comida['estado'] === 'pendiente',
                                        'bg-amber-100 text-amber-800' => $comida['estado'] === 'planificada',
                                        'bg-green-100 text-green-800' => $comida['estado'] === 'registrada',
                                    ])>{{ __($comida['estado']) }}</span>
                                </li>
                            @endforeach
                        </ul>

                        <a href="{{ route('planes.show', $registroDiario) }}"
                           class="mt-4 inline-flex w-full sm:w-auto items-center justify-center rounded-lg bg-indigo-600 px-5 py-3 text-sm font-semibold text-white hover:bg-indigo-700 transition">
                            {{ __('Abrir el plan de hoy') }}
                        </a>
                    @endif
                </div>
            </section>

            {{-- ══ 2. Tendencia ══ --}}
            <section class="space-y-3 sm:space-y-4">
                <h3 class="px-1 text-base sm:text-lg font-semibold text-gray-900">{{ __('2. Tu tendencia') }}</h3>

                @unless ($metricas['datos_suficientes'])
                    <div class="p-4 bg-amber-50 border border-amber-200 text-amber-800 rounded-xl text-sm">
                        {{ __('Todavía no hay 7 días de historial') }}
                        ({{ trans_choice(':count día registrado|:count días registrados', $metricas['dias_con_datos']) }}).
                        {{ __('Las cifras de abajo se calculan con lo que hay, pero una tendencia solo es fiable con la ventana completa de 7 días.') }}
                    </div>
                @endunless

                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 sm:gap-4">
                    <div class="p-4 sm:p-6 bg-white shadow-sm rounded-xl">
                        <p class="text-sm text-gray-500">{{ __('Peso (promedio móvil 7 días)') }}</p>
                        <p class="mt-2 text-2xl font-semibold text-gray-900">
                            @if ($metricas['promedio_movil_peso_kg'] === null)
                                <span class="text-gray-400 text-base">{{ __('Sin datos') }}</span>
                            @else
                                {{ $kg($metricas['promedio_movil_peso_kg']) }} kg
                            @endif
                        </p>
                        @if ($metricas['porcentaje_perdida_semanal'] !== null)
                            <p class="mt-1 text-sm {{ $metricas['porcentaje_perdida_semanal'] >= 0 ? 'text-green-700' : 'text-red-700' }}">
                                {{ number_format($metricas['porcentaje_perdida_semanal'], 2, ',', '.') }}%
                                {{ __('semanal') }}
                                @if ($metricas['tendencia'])
                                    <span class="text-gray-500">({{ str_replace('_', ' ', $metricas['tendencia']) }})</span>
                                @endif
                            </p>
                        @else
                            <p class="mt-1 text-sm text-gray-500">
                                {{ __('Apunta tu peso en el plan de cada día para verlo aquí.') }}
                            </p>
                        @endif
                    </div>

                    <div class="p-4 sm:p-6 bg-white shadow-sm rounded-xl">
                        <p class="text-sm text-gray-500">{{ __('Déficit promedio (7 días)') }}</p>
                        <p class="mt-2 text-2xl font-semibold {{ ($metricas['promedio_movil_deficit_kcal'] ?? 0) >= 0 ? 'text-green-700' : 'text-red-700' }}">
                            @if ($metricas['promedio_movil_deficit_kcal'] === null)
                                <span class="text-gray-400 text-base">{{ __('Sin datos') }}</span>
                            @else
                                {{ $kcal($metricas['promedio_movil_deficit_kcal']) }} kcal
                            @endif
                        </p>
                        <p class="mt-1 text-sm text-gray-500">{{ __('Solo cuentan los días ya cerrados.') }}</p>
                    </div>

                    <div class="p-4 sm:p-6 bg-white shadow-sm rounded-xl">
                        <p class="text-sm text-gray-500">{{ __('Índice de consistencia') }}</p>
                        <p class="mt-2 text-2xl font-semibold text-gray-900">
                            {{ number_format($metricas['indice_consistencia_pct'], 0, ',', '.') }}%
                        </p>
                        <p class="mt-1 text-sm text-gray-500">
                            {{ $metricas['dias_cerrados'] }} {{ __('de 7 días cerrados') }}
                        </p>
                    </div>
                </div>

                <div class="p-4 sm:p-6 bg-white shadow-sm rounded-xl">
                    <h4 class="text-sm font-semibold text-gray-900">
                        {{ __('Evolución del promedio móvil de peso') }}
                    </h4>
                    <p class="mt-1 text-xs text-gray-500">
                        {{ __('Cada punto es el promedio de los 7 días anteriores, no el peso de ese día: es lo que suaviza el ruido diario de la báscula.') }}
                    </p>

                    <div class="mt-4 h-64 sm:h-72">
                        <canvas id="grafico-peso"
                                data-serie="{{ json_encode($serie) }}"
                                aria-label="{{ __('Evolución del promedio móvil de peso') }}"
                                role="img"></canvas>
                    </div>
                </div>
            </section>

            {{-- ══ 3. Seguimiento ══ --}}
            <section class="space-y-3 sm:space-y-4">
                <div class="px-1">
                    <h3 class="text-base sm:text-lg font-semibold text-gray-900">{{ __('3. Tu seguimiento') }}</h3>
                    <p class="mt-1 text-sm text-gray-600">
                        {{ __('Cómo has ido semana a semana, a partir de tus planes diarios cerrados.') }}
                    </p>
                </div>

                <div class="bg-white shadow-sm rounded-xl overflow-hidden">
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-100 text-sm">
                            <thead class="bg-gray-50 text-xs uppercase tracking-wide text-gray-500">
                                <tr>
                                    <th scope="col" class="px-4 py-3 text-start font-medium">{{ __('Semana') }}</th>
                                    <th scope="col" class="px-4 py-3 text-end font-medium">{{ __('Adherencia') }}</th>
                                    <th scope="col" class="px-4 py-3 text-end font-medium">{{ __('Comidas') }}</th>
                                    <th scope="col" class="px-4 py-3 text-end font-medium">{{ __('Peso medio') }}</th>
                                    <th scope="col" class="px-4 py-3 text-end font-medium">{{ __('Variación') }}</th>
                                    <th scope="col" class="px-4 py-3 text-end font-medium">{{ __('Déficit medio') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                @foreach ($semanas as $semana)
                                    <tr>
                                        <td class="whitespace-nowrap px-4 py-3 text-gray-900">
                                            {{ \Illuminate\Support\Carbon::parse($semana['inicio'])->format('d/m') }}
                                            –
                                            {{ \Illuminate\Support\Carbon::parse($semana['fin'])->format('d/m') }}
                                        </td>
                                        <td class="whitespace-nowrap px-4 py-3 text-end text-gray-700">
                                            {{ number_format($semana['adherencia_pct'], 0, ',', '.') }}%
                                            <span class="text-xs text-gray-400">({{ $semana['dias_cerrados'] }}/7)</span>
                                        </td>
                                        <td class="whitespace-nowrap px-4 py-3 text-end text-gray-700">
                                            {{ $semana['comidas_registradas'] }}/{{ $semana['comidas_planificadas'] }}
                                        </td>
                                        <td class="whitespace-nowrap px-4 py-3 text-end text-gray-700">
                                            {{ $semana['promedio_peso_kg'] === null ? '—' : $kg($semana['promedio_peso_kg']).' kg' }}
                                        </td>
                                        <td class="whitespace-nowrap px-4 py-3 text-end">
                                            @if ($semana['variacion_peso_kg'] === null)
                                                <span class="text-gray-400">—</span>
                                            @else
                                                <span class="{{ $semana['variacion_peso_kg'] <= 0 ? 'text-green-700' : 'text-red-700' }}">
                                                    {{ $semana['variacion_peso_kg'] > 0 ? '+' : '' }}{{ $kg($semana['variacion_peso_kg']) }} kg
                                                </span>
                                            @endif
                                        </td>
                                        <td class="whitespace-nowrap px-4 py-3 text-end text-gray-700">
                                            {{ $semana['promedio_deficit_kcal'] === null ? '—' : $kcal($semana['promedio_deficit_kcal']).' kcal' }}
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>

            {{-- ══ 4. Ajustes del sistema ══ --}}
            <section class="space-y-3 sm:space-y-4">
                <div class="px-1">
                    <h3 class="text-base sm:text-lg font-semibold text-gray-900">{{ __('4. Ajustes de tu objetivo') }}</h3>
                    <p class="mt-1 text-sm text-gray-600">
                        {{ __('Los ajustes salen de promedios móviles de 7 días, nunca de un día suelto, y nunca se aplican sin tu confirmación.') }}
                    </p>
                </div>

                <div class="bg-white shadow-sm rounded-xl p-4 sm:p-6">
                    <h4 class="text-sm font-semibold text-gray-900">{{ __('Pendientes de tu confirmación') }}</h4>

                    @if ($recomendacionesPendientes->isEmpty())
                        <p class="mt-1 text-xs text-gray-500">
                            {{ __('No tienes recomendaciones pendientes.') }}
                        </p>
                    @else
                        <ul class="mt-2 divide-y divide-gray-100 text-sm">
                            @foreach ($recomendacionesPendientes as $recomendacion)
                                <li class="py-3">
                                    <p class="text-gray-800">{{ $recomendacion->justificacion }}</p>
                                    @if ($recomendacion->calorias_objetivo_sugeridas)
                                        <p class="mt-0.5 text-xs text-gray-500">
                                            {{ __('Objetivo sugerido:') }} {{ $kcal($recomendacion->calorias_objetivo_sugeridas) }} kcal
                                        </p>
                                    @endif

                                    <div class="mt-2 flex flex-wrap gap-2">
                                        <form method="post" action="{{ route('recomendaciones.confirmar', $recomendacion) }}">
                                            @csrf
                                            <button type="submit" class="rounded-lg bg-indigo-600 px-4 py-2 text-xs font-semibold text-white hover:bg-indigo-700 transition">
                                                {{ __('Confirmar') }}
                                            </button>
                                        </form>
                                        <form method="post" action="{{ route('recomendaciones.rechazar', $recomendacion) }}">
                                            @csrf
                                            <button type="submit" class="rounded-lg border border-gray-300 bg-white px-4 py-2 text-xs font-semibold text-gray-700 hover:bg-gray-50 transition">
                                                {{ __('Rechazar') }}
                                            </button>
                                        </form>
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                    @endif

                    <div class="mt-5 border-t border-gray-100 pt-4">
                        <h4 class="text-sm font-semibold text-gray-900">{{ __('Historial') }}</h4>

                        @if ($historialRecomendaciones->isEmpty())
                            <p class="mt-1 text-xs text-gray-500">
                                {{ __('Todavía no hay ajustes propuestos. Cierra tus días para que el sistema pueda leer tu tendencia.') }}
                            </p>
                        @else
                            <ol class="mt-3 space-y-3 border-s border-gray-200 ps-4">
                                @foreach ($historialRecomendaciones as $recomendacion)
                                    <li class="relative">
                                        <span @class([
                                            'absolute -start-[1.4rem] mt-1.5 h-2.5 w-2.5 rounded-full ring-4 ring-white',
                                            'bg-indigo-500' => $recomendacion->estado === 'confirmada',
                                            'bg-gray-300' => $recomendacion->estado === 'rechazada',
                                            'bg-amber-400' => $recomendacion->estado === 'pendiente',
                                        ])></span>
                                        <p class="text-xs text-gray-500">
                                            {{ $recomendacion->registroDiario?->fecha?->format('d/m/Y') }}
                                            · {{ str_replace('_', ' ', $recomendacion->tipo) }}
                                            · <span class="font-medium">{{ __($recomendacion->estado) }}</span>
                                        </p>
                                        <p class="mt-0.5 text-sm text-gray-800">{{ $recomendacion->justificacion }}</p>
                                    </li>
                                @endforeach
                            </ol>
                        @endif
                    </div>
                </div>
            </section>
        </div>
    </div>

    @push('scripts')
        <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js" defer></script>
        <script>
            document.addEventListener('DOMContentLoaded', function () {
                var canvas = document.getElementById('grafico-peso');

                // Sin CDN disponible la página sigue siendo útil: todas las
                // cifras son server-rendered y no dependen de este script.
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
