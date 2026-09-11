{{--
    Bloque 2 — Hoy (CLAUDE.md sección 5.8).

    La tarjeta principal es SIEMPRE la misma, esté el día abierto o cerrado:
    mismo panel carbón, mismo anillo, mismos macros, mismas comidas. Cerrar el
    día no cambia de tarjeta, solo cambia qué dicen sus cifras — sustituirla
    por un resumen distinto obligaba a reaprender la pantalla justo cuando el
    usuario acaba de terminar su día.
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
@else
    @php
        $resumen = $hoy['resumen'];
        $saldo = $hoy['saldo'];
        $cerrado = $hoy['estado'] === 'cerrado';

        $presupuesto = (float) $resumen['calorias_objetivo'] + (float) $resumen['calorias_actividad_ajustada'];
        $avance = $presupuesto > 0
            ? max(0, min(100, round((float) $resumen['calorias_consumidas'] / $presupuesto * 100)))
            : 0;

        /*
         * Qué dice la cifra protagonista (regla transversal de honestidad,
         * sección 5.8): con el día abierto, lo que QUEDA —un saldo todavía
         * puede moverse—; con el día cerrado, el déficit, que ya es un
         * resultado y por fin se puede llamar por su nombre.
         */
        $deficit = (float) $resumen['deficit_diario'];

        $pendientesLegible = collect($saldo['comidas_pendientes'] ?? [])
            ->pipe(fn ($lista) => $lista->count() > 1
                ? $lista->slice(0, -1)->implode(', ').' '.__('y').' '.$lista->last()
                : $lista->first());

        $enNegativo = $cerrado ? $deficit < 0 : $saldo['agotado'];
        $cifra = $cerrado ? abs($deficit) : abs($saldo['saldo']['calorias']);

        $rotulo = match (true) {
            $cerrado && $deficit >= 0 => __('Déficit de'),
            $cerrado => __('Te pasaste por'),
            $saldo['agotado'] => __('Te pasaste por'),
            default => __('Te quedan'),
        };

        $porcentaje = fn ($parte, $total) => $total > 0 ? max(0, min(100, round($parte / $total * 100))) : 0;
    @endphp

    <div class="tudi-panel on-dark">
        {{-- flex-wrap: en un móvil de 360px la línea de cifras no cabe junto a
             la etiqueta y tiene que poder bajar, no desbordarse. --}}
        <div class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
            <span class="flex items-center gap-2">
                <span class="tudi-label">{{ __('Hoy') }}</span>
                @if ($cerrado)
                    <span class="tudi-chip bg-tudi-dark-2 text-tudi-lime">{{ __('cerrado') }}</span>
                @endif
            </span>
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
                        'text-tudi-on-dark' => ! $enNegativo,
                        'text-tudi-amber' => $enNegativo,
                    ])>
                        {{ $rotulo }} <span class="tudi-num">{{ $kcal($cifra) }}</span> kcal
                    </p>
                    @if ($cerrado)
                        <p class="tudi-meta mt-0.5">
                            {{ $resumen['calorias_actividad_ajustada'] > 0
                                ? __('incluye :kcal de actividad', ['kcal' => $kcal($resumen['calorias_actividad_ajustada'])])
                                : __('resultado del día') }}
                        </p>
                    @elseif ($pendientesLegible)
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
                {{--
                    Los tres macros, no solo la proteína: el objetivo del día
                    son cuatro cifras (kcal + tres macros) y enseñar una sola
                    dejaba el panel contando media historia. En carbón van con
                    la palabra completa, nunca la inicial (sección 5.12).
                --}}
                <div class="space-y-2.5">
                    @foreach ([
                        ['tipo' => 'proteina', 'real' => $resumen['proteina_consumida_g'], 'objetivo' => $resumen['proteina_objetivo_g'], 'color' => 'var(--tudi-lime)'],
                        ['tipo' => 'grasa', 'real' => $resumen['grasa_consumida_g'], 'objetivo' => $resumen['grasa_objetivo_g'], 'color' => 'var(--tudi-amber)'],
                        ['tipo' => 'carbohidratos', 'real' => $resumen['carbohidratos_consumidos_g'], 'objetivo' => $resumen['carbohidratos_objetivo_g'], 'color' => 'var(--tudi-on-dark)'],
                    ] as $macro)
                        <div>
                            <div class="flex items-baseline justify-between gap-2">
                                <x-tudi.macro :tipo="$macro['tipo']" variante="palabra" class="tudi-label" />
                                <span class="tudi-meta text-tudi-on-dark">
                                    {{-- Los días cerrados antes de que el snapshot guardara grasa y
                                         carbohidratos no tienen estas cifras: ausentes, no cero. --}}
                                    @if ($macro['real'] === null || $macro['objetivo'] === null)
                                        —
                                    @else
                                        {{ $gramos($macro['real']) }} / {{ $gramos($macro['objetivo']) }} g
                                    @endif
                                </span>
                            </div>
                            <span class="tudi-bar mt-1.5 block"
                                  style="--pct: {{ $macro['real'] === null || $macro['objetivo'] === null ? 0 : $porcentaje($macro['real'], $macro['objetivo']) }}">
                                <span style="background: {{ $macro['color'] }}"></span>
                            </span>
                        </div>
                    @endforeach
                </div>

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
                    {{ $cerrado ? __('Ver el detalle del día') : __('Abrir el plan de hoy') }}
                </a>
            </div>
        </div>
    </div>
@endif
