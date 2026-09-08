@php
    $kcal = fn ($valor) => number_format((float) $valor, 0, ',', '.');
    $kg = fn ($valor) => number_format((float) $valor, 2, ',', '.');

    $comidasRegistradas = collect($estadoComidas)->where('estado', 'registrada')->count();

    // Avance del día para el anillo: cuánto del presupuesto energético del día
    // (objetivo + lo quemado en actividad) se ha consumido ya. 0–100.
    $avance = 0;

    if ($resumen) {
        $presupuesto = (float) $resumen['calorias_objetivo'] + (float) $resumen['calorias_actividad_ajustada'];
        $avance = $presupuesto > 0
            ? max(0, min(100, round((float) $resumen['calorias_consumidas'] / $presupuesto * 100)))
            : 0;
    }

    $proteinaPct = $resumen ? max(0, min(100, (float) $resumen['cumplimiento_proteina_pct'])) : 0;
@endphp

<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between gap-4">
            <a href="{{ route('dashboard') }}" class="text-tudi-ink no-underline sm:hidden">
                <x-tudi.marca :size="26" :texto="17" />
            </a>

            <div class="hidden min-w-0 sm:block">
                <h1 class="text-3xl font-semibold tracking-tudi-display">
                    {{ __('Buen día') }}, {{ str(Auth::user()->name)->before(' ') }}
                </h1>
                <p class="mt-1 text-sm text-tudi-ink-3 first-letter:uppercase">{{ now()->translatedFormat('l d \d\e F') }}</p>
            </div>

            <div class="flex items-center gap-2">
                <span class="tudi-meta uppercase sm:hidden">{{ now()->translatedFormat('d M') }}</span>
                <x-tudi.avatar-menu />
            </div>
        </div>
    </x-slot>

    <x-tudi.flash :mensajes="[
        'recomendacion-confirmada' => __('Tu objetivo calórico quedó actualizado.'),
        'recomendacion-rechazada' => __('Tu objetivo calórico no cambió.'),
    ]" />

    <div class="space-y-8">

        {{-- ══ Hoy: el déficit es el único dato protagonista ══ --}}
        @if ($errorResumen)
            <div class="tudi-panel">
                <p class="text-tudi-on-dark-2">{{ $errorResumen }}</p>
                <a href="{{ route('calculadora.edit') }}" class="tudi-btn tudi-btn-lime tudi-btn-block mt-5 no-underline">
                    {{ __('Ir a la Calculadora Déficit') }}
                </a>
            </div>
        @elseif (! $registroDiario || ! $resumen)
            <div class="tudi-panel text-center">
                <p class="tudi-label">{{ __('Déficit de hoy') }}</p>
                <p class="tudi-num mt-3 text-[54px] text-tudi-on-dark-3">—</p>
                <p class="mt-3 text-tudi-on-dark-2">{{ __('Todavía no has creado el plan de hoy.') }}</p>

                <form method="post" action="{{ route('planes.crear') }}" class="mt-5">
                    @csrf
                    <button type="submit" class="tudi-btn tudi-btn-lime tudi-btn-block">
                        {{ __('Crear plan diario') }}
                    </button>
                </form>
            </div>
        @else
            @php $deficit = (float) $resumen['deficit_diario']; @endphp

            <section class="grid gap-4 lg:grid-cols-[minmax(0,1.15fr)_minmax(0,1fr)] lg:items-start">
                {{-- Anillo de déficit --}}
                <div class="tudi-panel on-dark">
                    <div class="flex flex-col items-center gap-6 lg:flex-row lg:items-center lg:gap-7">
                        <div class="tudi-ring lg:tudi-ring-sm flex-none" style="--pct: {{ $avance }}">
                            <div>
                                <span class="tudi-label">{{ __('Déficit de hoy') }}</span>
                                <span @class([
                                    'tudi-num text-[54px] lg:text-[46px]',
                                    'text-tudi-lime' => $deficit >= 0,
                                    'text-tudi-on-dark' => $deficit < 0,
                                ])>{{ $kcal(abs($deficit)) }}</span>
                                <span class="tudi-meta">
                                    {{ $deficit >= 0 ? __('kcal por debajo') : __('kcal por encima') }}
                                </span>
                            </div>
                        </div>

                        <div class="w-full min-w-0 text-center lg:w-auto lg:text-start">
                            <p class="text-sm text-tudi-on-dark-2">
                                {{ $kcal($resumen['calorias_consumidas']) }} {{ __('consumidas') }}
                                <span class="hidden lg:inline">· {{ $kcal($resumen['calorias_actividad_ajustada']) }} {{ __('quemadas en actividad') }}</span>
                                · {{ $kcal($resumen['calorias_objetivo']) }} {{ __('objetivo') }}
                            </p>

                            <div class="mt-4 grid grid-cols-2 gap-2.5">
                                <div class="tudi-panel-tile text-start">
                                    <p class="tudi-label">{{ __('Actividad') }}</p>
                                    <p class="tudi-num mt-1 text-xl text-tudi-on-dark">{{ $kcal($resumen['calorias_actividad_ajustada']) }} kcal</p>
                                </div>
                                <div class="tudi-panel-tile text-start">
                                    <p class="tudi-label">{{ __('Proteína') }}</p>
                                    <p class="tudi-num mt-1 text-xl text-tudi-on-dark">{{ number_format($resumen['cumplimiento_proteina_pct'], 1, ',', '.') }}%</p>
                                    <div class="tudi-bar mt-2" style="--pct: {{ $proteinaPct }}"><span></span></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                {{--
                    Comidas: sobre el panel carbón en móvil (una sola superficie
                    de dato, como el mockup) y sobre tarjeta crema en escritorio.
                --}}
                <div class="rounded-tudi-xl bg-tudi-dark p-5 lg:border lg:border-tudi-border lg:bg-tudi-card lg:p-6">
                    <div class="flex items-center justify-between">
                        <span class="tudi-label text-tudi-on-dark-3 lg:text-tudi-muted">{{ __('Comidas') }}</span>
                        <span class="tudi-meta text-tudi-lime lg:text-tudi-lime-700">
                            {{ $comidasRegistradas }} / {{ count($estadoComidas) }}
                        </span>
                    </div>

                    <ul class="mt-3 space-y-1.5">
                        @foreach ($estadoComidas as $comida)
                            @php
                                $plan = $comida['planComida'];
                                $kcalComida = $plan?->comidaReal?->calorias_reales ?? $plan?->calorias_estimadas;
                            @endphp
                            <li class="flex items-center gap-3 rounded-tudi-sm bg-tudi-dark-2 px-4 py-3 lg:rounded-tudi-pill lg:bg-tudi-card-inset">
                                <span @class([
                                    'h-[18px] w-[18px] flex-none rounded-full',
                                    'bg-tudi-lime' => $comida['estado'] === 'registrada',
                                    'bg-tudi-dark-4 lg:bg-tudi-input-border' => $comida['estado'] !== 'registrada',
                                ])></span>
                                <span class="flex-1 text-[15px] capitalize text-tudi-on-dark lg:font-semibold lg:text-tudi-ink">
                                    {{ $comida['tipo'] }}
                                    {{-- El estado lo lleva el punto: aquí queda para lectores de pantalla. --}}
                                    <span class="sr-only">{{ __($comida['estado']) }}</span>
                                </span>
                                <span class="tudi-meta text-tudi-on-dark-3 lg:text-tudi-muted">
                                    {{ $kcalComida === null ? '—' : $kcal($kcalComida) }}
                                </span>
                            </li>
                        @endforeach
                    </ul>

                    <a href="{{ route('planes.show', $registroDiario) }}"
                       class="tudi-btn tudi-btn-block mt-4 bg-tudi-lime text-tudi-ink no-underline hover:bg-tudi-lime-600 lg:bg-tudi-ink lg:text-tudi-on-dark">
                        {{ __('Abrir el plan de hoy') }}
                    </a>
                </div>
            </section>
        @endif

        {{-- ══ Tendencia ══ --}}
        <section class="space-y-3">
            <p class="tudi-label px-1">{{ __('Tu tendencia') }}</p>

            <div class="grid gap-3 sm:grid-cols-3">
                <div class="rounded-tudi-xl bg-tudi-surface p-5">
                    <p class="tudi-label">{{ __('Peso · media 7 días') }}</p>
                    <p class="tudi-num mt-1.5 text-[28px]">
                        {{ $metricas['promedio_movil_peso_kg'] === null ? '—' : $kg($metricas['promedio_movil_peso_kg']).' kg' }}
                    </p>
                    @if ($metricas['porcentaje_perdida_semanal'] !== null)
                        <p @class([
                            'text-[13px] font-semibold',
                            'text-tudi-lime-700' => $metricas['porcentaje_perdida_semanal'] >= 0,
                            'text-tudi-amber-ink' => $metricas['porcentaje_perdida_semanal'] < 0,
                        ])>
                            {{ number_format($metricas['porcentaje_perdida_semanal'], 2, ',', '.') }}% {{ __('semanal') }}
                        </p>
                    @else
                        <p class="text-[13px] text-tudi-muted">{{ __('Apunta tu peso en el plan del día') }}</p>
                    @endif
                </div>

                <div class="rounded-tudi-xl bg-tudi-surface p-5">
                    <p class="tudi-label">{{ __('Déficit promedio') }}</p>
                    <p class="tudi-num mt-1.5 text-[28px]">
                        {{ $metricas['promedio_movil_deficit_kcal'] === null ? '—' : $kcal($metricas['promedio_movil_deficit_kcal']).' kcal' }}
                    </p>
                    <p class="text-[13px] text-tudi-muted">
                        {{ trans_choice('sobre :count día cerrado|sobre :count días cerrados', $metricas['dias_cerrados']) }}
                    </p>
                </div>

                <div class="rounded-tudi-xl bg-tudi-surface p-5">
                    <p class="tudi-label">{{ __('Racha') }}</p>
                    <div class="mt-2.5 flex gap-1.5" role="img"
                         aria-label="{{ $metricas['dias_cerrados'] }} {{ __('de 7 días cerrados') }}">
                        @for ($dia = 1; $dia <= 7; $dia++)
                            <span @class([
                                'h-[26px] w-[26px] rounded-full',
                                'bg-tudi-lime' => $dia <= $metricas['dias_cerrados'],
                                'bg-tudi-input-border' => $dia > $metricas['dias_cerrados'],
                            ])></span>
                        @endfor
                    </div>
                    <p class="mt-2 text-[13px] text-tudi-muted">
                        {{ $metricas['dias_cerrados'] }} {{ __('de 7 días cerrados') }}
                    </p>
                </div>
            </div>

            <div class="tudi-card p-5">
                <p class="tudi-label">{{ __('Evolución del promedio móvil de peso') }}</p>
                <div class="mt-4 h-56 sm:h-64">
                    <canvas id="grafico-peso"
                            data-serie="{{ json_encode($serie) }}"
                            aria-label="{{ __('Evolución del promedio móvil de peso') }}"
                            role="img"></canvas>
                </div>
            </div>
        </section>

        {{-- ══ Seguimiento ══ --}}
        <section class="space-y-3">
            <p class="tudi-label px-1">{{ __('Tu seguimiento') }}</p>

            <div class="tudi-card">
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="border-b border-tudi-divider">
                                <th scope="col" class="tudi-label px-4 py-3 text-start font-normal">{{ __('Semana') }}</th>
                                <th scope="col" class="tudi-label px-4 py-3 text-end font-normal">{{ __('Adherencia') }}</th>
                                <th scope="col" class="tudi-label px-4 py-3 text-end font-normal">{{ __('Comidas') }}</th>
                                <th scope="col" class="tudi-label px-4 py-3 text-end font-normal">{{ __('Peso medio') }}</th>
                                <th scope="col" class="tudi-label px-4 py-3 text-end font-normal">{{ __('Variación') }}</th>
                                <th scope="col" class="tudi-label px-4 py-3 text-end font-normal">{{ __('Déficit medio') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($semanas as $semana)
                                <tr class="border-b border-tudi-divider last:border-0">
                                    <td class="whitespace-nowrap px-4 py-3">
                                        {{ \Illuminate\Support\Carbon::parse($semana['inicio'])->format('d/m') }}
                                        –
                                        {{ \Illuminate\Support\Carbon::parse($semana['fin'])->format('d/m') }}
                                    </td>
                                    <td class="whitespace-nowrap px-4 py-3 text-end font-mono text-xs text-tudi-ink-2">
                                        {{ number_format($semana['adherencia_pct'], 0, ',', '.') }}%
                                        <span class="text-tudi-muted">({{ $semana['dias_cerrados'] }}/7)</span>
                                    </td>
                                    <td class="whitespace-nowrap px-4 py-3 text-end font-mono text-xs text-tudi-ink-2">
                                        {{ $semana['comidas_registradas'] }}/{{ $semana['comidas_planificadas'] }}
                                    </td>
                                    <td class="whitespace-nowrap px-4 py-3 text-end font-mono text-xs text-tudi-ink-2">
                                        {{ $semana['promedio_peso_kg'] === null ? '—' : $kg($semana['promedio_peso_kg']).' kg' }}
                                    </td>
                                    <td class="whitespace-nowrap px-4 py-3 text-end font-mono text-xs">
                                        @if ($semana['variacion_peso_kg'] === null)
                                            <span class="text-tudi-muted">—</span>
                                        @else
                                            <span class="{{ $semana['variacion_peso_kg'] <= 0 ? 'text-tudi-lime-700' : 'text-tudi-amber-ink' }}">
                                                {{ $semana['variacion_peso_kg'] > 0 ? '+' : '' }}{{ $kg($semana['variacion_peso_kg']) }} kg
                                            </span>
                                        @endif
                                    </td>
                                    <td class="whitespace-nowrap px-4 py-3 text-end font-mono text-xs text-tudi-ink-2">
                                        {{ $semana['promedio_deficit_kcal'] === null ? '—' : $kcal($semana['promedio_deficit_kcal']).' kcal' }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

        {{-- ══ Ajustes de tu objetivo ══ --}}
        <section class="space-y-3">
            <div class="flex items-center justify-between gap-3 px-1">
                <p class="tudi-label">{{ __('Ajustes de tu objetivo') }}</p>

                {{-- Mismo "¿Qué es esto?" que en el cierre (sección 5.6): sin
                     él, esta sección lleva semanas vacía sin decir por qué. --}}
                <div x-data="{ abierto: false }" x-on:keydown.escape.window="abierto = false">
                    <button type="button" x-on:click="abierto = true" class="tudi-link text-[13px]">
                        {{ __('¿Qué es esto?') }}
                    </button>

                    <div x-show="abierto" style="display: none"
                         class="fixed inset-0 z-50 flex items-end justify-center bg-tudi-ink/60 p-4 sm:items-center"
                         x-on:click.self="abierto = false" role="dialog" aria-modal="true">
                        <div class="tudi-card w-full max-w-md p-6">
                            <h2 class="text-lg font-semibold tracking-tudi-title">{{ __('Ajustes de tu objetivo') }}</h2>
                            <div class="mt-3 space-y-3 text-sm text-tudi-ink-3">
                                <p>{{ __('Cuando tu ritmo se sale de lo esperado, TUDI te propone subir o bajar tu objetivo calórico. La propuesta nunca se aplica sola: la confirmas o la rechazas tú.') }}</p>
                                <p>{{ __('No mira un día suelto. Compara el promedio de los últimos 7 días con el de los 7 anteriores, así que en las primeras semanas está vacía a propósito.') }}</p>
                                <p>{{ __('Para que aparezca hacen falta 7 días con plan y al menos un pesaje en cada una de las dos semanas. No hay que pesarse a diario: los días sin peso no cuentan como cero, simplemente no entran en el promedio.') }}</p>
                            </div>
                            <button type="button" x-on:click="abierto = false" class="tudi-btn tudi-btn-primary tudi-btn-block mt-5">
                                {{ __('Entendido') }}
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <div class="tudi-card p-5 sm:p-6">
                @if ($recomendacionesPendientes->isEmpty())
                    <p class="text-sm text-tudi-muted">{{ __('No tienes recomendaciones pendientes.') }}</p>
                @else
                    <ul class="space-y-4">
                        @foreach ($recomendacionesPendientes as $recomendacion)
                            <li>
                                <p class="text-sm">{{ $recomendacion->justificacion }}</p>
                                @if ($recomendacion->calorias_objetivo_sugeridas)
                                    <p class="tudi-meta mt-1">{{ $kcal($recomendacion->calorias_objetivo_sugeridas) }} kcal</p>
                                @endif
                                <div class="mt-3 flex flex-wrap gap-2">
                                    <form method="post" action="{{ route('recomendaciones.confirmar', $recomendacion) }}">
                                        @csrf
                                        <button type="submit" class="tudi-btn tudi-btn-primary">{{ __('Confirmar') }}</button>
                                    </form>
                                    <form method="post" action="{{ route('recomendaciones.rechazar', $recomendacion) }}">
                                        @csrf
                                        <button type="submit" class="tudi-btn tudi-btn-secondary">{{ __('Rechazar') }}</button>
                                    </form>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif

                <div class="mt-6 border-t border-tudi-divider pt-5">
                    <p class="tudi-label">{{ __('Historial') }}</p>

                    @if ($historialRecomendaciones->isEmpty())
                        <p class="mt-2 text-sm text-tudi-muted">{{ __('Todavía no hay ajustes propuestos.') }}</p>
                    @else
                        <ol class="mt-3 space-y-3 border-s border-tudi-divider ps-4">
                            @foreach ($historialRecomendaciones as $recomendacion)
                                <li class="relative">
                                    <span @class([
                                        'absolute -start-[1.4rem] mt-1.5 h-2.5 w-2.5 rounded-full ring-4 ring-tudi-card',
                                        'bg-tudi-lime' => $recomendacion->estado === 'confirmada',
                                        'bg-tudi-input-border' => $recomendacion->estado === 'rechazada',
                                        'bg-tudi-amber' => $recomendacion->estado === 'pendiente',
                                    ])></span>
                                    <p class="tudi-meta">
                                        {{ $recomendacion->registroDiario?->fecha?->format('d/m/Y') }}
                                        · {{ __($recomendacion->estado) }}
                                    </p>
                                    <p class="mt-0.5 text-sm">{{ $recomendacion->justificacion }}</p>
                                </li>
                            @endforeach
                        </ol>
                    @endif
                </div>
            </div>
        </section>
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
                            x: {
                                grid: { color: '#E4DCCC' },
                                ticks: { color: '#6B665C' },
                            },
                            y: {
                                beginAtZero: false,
                                grid: { color: '#E4DCCC' },
                                ticks: {
                                    color: '#6B665C',
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
