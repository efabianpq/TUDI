{{--
    Bloque 2 — Hoy (CLAUDE.md sección 5.8): tres sub-estados mutuamente
    excluyentes que decide DashboardEstadoService::calcular() — esta vista
    solo pinta el que le llega en $hoy['estado'], sin repetir la condición de
    negocio.
--}}
@php
    $kcal = fn ($valor) => number_format((float) $valor, 0, ',', '.');
    $gramos = fn ($valor) => number_format((float) $valor, 0, ',', '.');
@endphp

@if ($hoy['estado'] === 'error')
    <div class="tudi-panel">
        <p class="text-tudi-on-dark-2">{{ $hoy['error'] }}</p>
        <a href="{{ route('calculadora.edit') }}" class="tudi-btn tudi-btn-lime tudi-btn-block mt-5 no-underline">
            {{ __('Ir a la Calculadora Déficit') }}
        </a>
    </div>
@elseif ($hoy['estado'] === 'sin_plan')
    {{-- Usuario recurrente que todavía no ha abierto el día. --}}
    <div class="tudi-panel text-center">
        <p class="tudi-label">{{ __('Hoy') }}</p>
        <p class="mt-3 text-tudi-on-dark-2">{{ __('Todavía no has creado el plan de hoy.') }}</p>

        <form method="post" action="{{ route('planes.crear') }}" class="mt-5">
            @csrf
            <button type="submit" class="tudi-btn tudi-btn-lime tudi-btn-block">
                {{ __('Generar plan de hoy') }}
            </button>
        </form>
    </div>
@elseif ($hoy['estado'] === 'cerrado')
    {{--
        Día cerrado: la MISMA tarjeta que usa el cierre del plan diario
        (DailyClosureService::resumen() sobre un día cerrado es un snapshot
        congelado, nunca se recalcula desde el perfil actual). Nada de anillo
        de progreso: el día ya terminó, lo que queda es el resultado.
    --}}
    <div class="tudi-card p-5 sm:p-6">
        <x-tudi.resultado-dia :resumen="$hoy['resumen']" cerrado />

        <a href="{{ route('planes.show', $hoy['registroDiario']) }}"
           class="tudi-btn tudi-btn-block mt-4 bg-tudi-ink text-tudi-on-dark no-underline">
            {{ __('Ver el detalle del día') }}
        </a>
    </div>
@else
    {{--
        Día en curso. El anillo pasa a ser un indicador compacto con su
        porcentaje dentro, y la cifra protagonista es la accionable: lo que
        QUEDA del día (MealDistributionService::saldoDelDia, sección 5.21).
        "Déficit" es un sustantivo de resultado y solo se gana con el día
        cerrado (sección 5.8, regla transversal de honestidad).
    --}}
    @php
        $resumen = $hoy['resumen'];
        $saldo = $hoy['saldo'];

        $presupuesto = (float) $resumen['calorias_objetivo'] + (float) $resumen['calorias_actividad_ajustada'];
        $avance = $presupuesto > 0
            ? max(0, min(100, round((float) $resumen['calorias_consumidas'] / $presupuesto * 100)))
            : 0;
        $proteinaPct = max(0, min(100, (float) $resumen['cumplimiento_proteina_pct']));

        $pendientesLegible = collect($saldo['comidas_pendientes'] ?? [])
            ->pipe(fn ($lista) => $lista->count() > 1
                ? $lista->slice(0, -1)->implode(', ').' '.__('y').' '.$lista->last()
                : $lista->first());
    @endphp

    <div class="tudi-panel on-dark">
        {{-- flex-wrap: en un móvil de 360px la línea de cifras no cabe junto a
             la etiqueta y tiene que poder bajar, no desbordarse. --}}
        <div class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
            <span class="tudi-label">{{ __('Hoy') }}</span>
            <span class="tudi-meta">
                {{ $kcal($resumen['calorias_consumidas']) }} / {{ $kcal($resumen['calorias_objetivo']) }} kcal
                @if ($resumen['calorias_actividad_ajustada'] > 0)
                    · {{ $kcal($resumen['calorias_actividad_ajustada']) }} {{ __('en actividad') }}
                @endif
            </span>
        </div>

        {{-- Móvil: apilado. sm+: dos mitades equilibradas, no dos extremos. --}}
        <div class="mt-4 sm:grid sm:grid-cols-2 sm:items-center sm:gap-6 lg:gap-10">
            <div class="flex items-center gap-4">
                <div class="tudi-ring tudi-ring-mini flex-none" style="--pct: {{ $avance }}">
                    <div>
                        <span class="tudi-num text-tudi-on-dark">{{ $avance }}%</span>
                    </div>
                </div>

                <div class="min-w-0">
                    <p @class([
                        'text-[22px] font-semibold leading-tight tracking-tudi-title',
                        'text-tudi-on-dark' => ! $saldo['agotado'],
                        'text-tudi-amber' => $saldo['agotado'],
                    ])>
                        {{ $saldo['agotado'] ? __('Te pasaste por') : __('Te quedan') }}
                        <span class="tudi-num">{{ $kcal(abs($saldo['saldo']['calorias'])) }}</span> kcal
                    </p>
                    @if ($pendientesLegible)
                        <p class="tudi-meta mt-0.5">{{ __('para') }} {{ $pendientesLegible }}</p>
                    @endif
                </div>
            </div>

            {{--
                El CTA vive en esta columna y no fuera del grid: en escritorio
                equilibra las dos mitades del panel, y en móvil —donde el grid
                no aplica— cae igualmente al final, después de las comidas.
            --}}
            <div class="mt-5 sm:mt-0">
                <div class="flex items-baseline justify-between gap-2">
                    <x-tudi.macro tipo="proteina" variante="palabra" class="tudi-label" />
                    <span class="tudi-meta text-tudi-on-dark">
                        {{ $gramos($resumen['proteina_consumida_g']) }} / {{ $gramos($resumen['proteina_objetivo_g']) }} g
                    </span>
                </div>
                <span class="tudi-bar mt-2 block" style="--pct: {{ $proteinaPct }}"><span></span></span>

                {{--
                    Las comidas pasan de una tarjeta propia con su lista a tres
                    chips en línea: el estado de cada una es un sí/no, y una
                    fila de chips lo dice en el mismo golpe de vista que la
                    cifra de arriba sin ocupar media pantalla.
                --}}
                <div class="mt-4 flex flex-wrap gap-2">
                    @foreach ($hoy['estadoComidas'] as $comida)
                        <span @class([
                            'tudi-chip gap-1.5 capitalize',
                            'bg-tudi-dark-2 text-tudi-lime' => $comida['estado'] === 'registrada',
                            'bg-tudi-dark-2 text-tudi-on-dark' => $comida['estado'] === 'planificada',
                            'bg-tudi-dark-2 text-tudi-on-dark-3' => $comida['estado'] === 'pendiente',
                        ])>
                            @if ($comida['estado'] === 'registrada')
                                <svg class="h-3.5 w-3.5 flex-none" fill="none" stroke="currentColor"
                                     stroke-width="2.5" viewBox="0 0 24 24" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="m5 13 4 4L19 7" />
                                </svg>
                            @endif
                            {{ $comida['tipo'] }}
                            {{-- El estado lo lleva el color: aquí queda para lectores de pantalla. --}}
                            <span class="sr-only">{{ __($comida['estado']) }}</span>
                        </span>
                    @endforeach
                </div>

                {{-- Ancho completo en móvil (objetivo táctil), al ancho de su
                     texto en escritorio: ahí una barra lima de 900px gritaría
                     más que la cifra que la gente vino a mirar. --}}
                <a href="{{ route('planes.show', $hoy['registroDiario']) }}"
                   class="tudi-btn tudi-btn-lime tudi-btn-block mt-5 no-underline sm:w-auto">
                    {{ __('Abrir el plan de hoy') }}
                </a>
            </div>
        </div>
    </div>
@endif
