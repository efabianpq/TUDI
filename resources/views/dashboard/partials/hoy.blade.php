{{--
    Bloque 2 — Hoy (CLAUDE.md sección 5.8): tres sub-estados mutuamente
    excluyentes que decide DashboardEstadoService::calcular() — esta vista
    solo pinta el que le llega en $hoy['estado'], sin repetir la condición de
    negocio.
--}}
@php
    $kcal = fn ($valor) => number_format((float) $valor, 0, ',', '.');
    $comidasRegistradas = collect($hoy['estadoComidas'])->where('estado', 'registrada')->count();
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
        Día en curso: el mismo anillo de progreso y las mismas cifras que ya
        vivían aquí, pero la cifra protagonista ahora es "lo que queda"
        (MealDistributionService::saldoDelDia, sección 5.21) y no un
        "déficit" — ese sustantivo de resultado solo se gana una vez el día
        se cierra (sección 5.8, requisito transversal).
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

    <section class="grid gap-4 lg:grid-cols-[minmax(0,1.15fr)_minmax(0,1fr)] lg:items-start">
        <div class="tudi-panel on-dark">
            <div class="flex flex-col items-center gap-6 lg:flex-row lg:items-center lg:gap-7">
                <div class="tudi-ring lg:tudi-ring-sm flex-none" style="--pct: {{ $avance }}">
                    <div>
                        <span class="tudi-label">
                            {{ $saldo['agotado'] ? __('Te pasaste por') : __('Te quedan') }}
                        </span>
                        <span @class([
                            'tudi-num text-[54px] lg:text-[46px]',
                            'text-tudi-lime' => ! $saldo['agotado'],
                            'text-tudi-amber' => $saldo['agotado'],
                        ])>{{ $kcal(abs($saldo['saldo']['calorias'])) }}</span>
                        <span class="tudi-meta">
                            kcal
                            @if ($pendientesLegible)
                                {{ __('para') }} {{ $pendientesLegible }}
                            @endif
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
            Comidas: sobre el panel carbón en móvil (una sola superficie de
            dato) y sobre tarjeta crema en escritorio.
        --}}
        <div class="rounded-tudi-xl bg-tudi-dark p-5 lg:border lg:border-tudi-border lg:bg-tudi-card lg:p-6">
            <div class="flex items-center justify-between">
                <span class="tudi-label text-tudi-on-dark-3 lg:text-tudi-muted">{{ __('Comidas') }}</span>
                <span class="tudi-meta text-tudi-lime lg:text-tudi-lime-700">
                    {{ $comidasRegistradas }} / {{ count($hoy['estadoComidas']) }}
                </span>
            </div>

            <ul class="mt-3 space-y-1.5">
                @foreach ($hoy['estadoComidas'] as $comida)
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
                            <span class="sr-only">{{ __($comida['estado']) }}</span>
                        </span>
                        <span class="tudi-meta text-tudi-on-dark-3 lg:text-tudi-muted">
                            {{ $kcalComida === null ? '—' : $kcal($kcalComida) }}
                        </span>
                    </li>
                @endforeach
            </ul>

            <a href="{{ route('planes.show', $hoy['registroDiario']) }}"
               class="tudi-btn tudi-btn-block mt-4 bg-tudi-lime text-tudi-ink no-underline hover:bg-tudi-lime-600 lg:bg-tudi-ink lg:text-tudi-on-dark">
                {{ __('Abrir el plan de hoy') }}
            </a>
        </div>
    </section>
@endif
