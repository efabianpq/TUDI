@php
    $kcal = fn ($valor) => number_format((float) $valor, 0, ',', '.');
    $gramos = fn ($valor) => number_format((float) $valor, 1, ',', '.');
    $porcentaje = fn ($parte, $total) => $total > 0 ? max(0, min(100, round($parte / $total * 100))) : 0;

    $totalPlanificado = collect($comidas)
        ->map(fn ($comida) => (float) ($comida['plan']->calorias_estimadas ?? 0))
        ->sum();

    // "Calcular mi plan" es una sola acción para las tres comidas: sobra en
    // cuanto no queda ninguna abierta que ajustar (CLAUDE.md sección 5.3).
    $quedaAlgoQueAjustar = collect($comidas)->contains(fn ($comida) => $comida['estado'] !== 'registrada');

    // Acordeón: solo una comida abierta a la vez dentro de cada grupo. Arranca
    // en la primera que el usuario todavía tiene que resolver.
    $comidaAbierta = collect($comidas)->firstWhere('estado', '!=', 'registrada')['tipo'] ?? null;
    $reporteAbierto = collect($comidas)->firstWhere('estado', '!=', 'registrada')['tipo'] ?? null;

    $caloriasActividad = $actividades->sum(fn ($registro) => (float) $registro->calorias_ajustadas);

    $repartoLegible = collect($reparto)
        ->map(fn ($proporcion, $tipo) => (int) round($proporcion * 100).'% '.$tipo)
        ->implode(' · ');

    // Cómo se lee "te quedan X para almuerzo y cena" (sección 5.21).
    $pendientesLegible = collect($saldo['comidas_pendientes'] ?? [])
        ->pipe(fn ($lista) => $lista->count() > 1
            ? $lista->slice(0, -1)->implode(', ').' '.__('y').' '.$lista->last()
            : $lista->first());

    $sinReportarLegible = collect($comidasSinReportar)
        ->pipe(fn ($lista) => $lista->count() > 1
            ? $lista->slice(0, -1)->implode(', ').' '.__('y').' '.$lista->last()
            : $lista->first());
@endphp

<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between gap-3">
            <div class="flex min-w-0 items-center gap-3">
                <a href="{{ route('planes.index') }}"
                   class="grid h-11 w-11 flex-none place-items-center rounded-full text-tudi-muted no-underline hover:bg-tudi-surface"
                   aria-label="{{ __('Volver a Planes diarios') }}">
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7" />
                    </svg>
                </a>
                <h1 class="truncate text-lg font-semibold tracking-tudi-title sm:text-2xl">
                    {{ __('Plan del') }} {{ $registroDiario->fecha->format('d/m/Y') }}
                </h1>
            </div>

            <span @class([
                'tudi-chip flex-none uppercase tracking-tudi-label',
                'tudi-chip-solid text-tudi-lime' => ! $registroDiario->cerrado,
                'tudi-chip-solid' => $registroDiario->cerrado,
            ])>{{ $registroDiario->cerrado ? __('cerrado') : __('abierto') }}</span>
        </div>
    </x-slot>

    <div id="tudi-avisos">
        <x-tudi.flash :mensajes="[
            'plan-creado' => __('Tu plan de hoy está listo.'),
            'plan-existente' => __('Ya tenías un plan para hoy.'),
            'plan-ajustado' => __('Tu plan quedó ajustado a lo que te queda del día.'),
            'plan-generado' => __('Plan generado.'),
            'comida-cerrada' => __('Comida cerrada. Ya cuenta en el saldo de tu día.'),
            'comida-reabierta' => __('Esa comida vuelve a estar abierta.'),
            'comida-sin-reporte' => __('Esa comida no tenía nada reportado.'),
            'comida-real-guardada' => __('Comida registrada.'),
            'comida-real-eliminada' => __('Puedes volver a responder por esa comida.'),
            'comida-real-inexistente' => __('Esa comida no tenía nada registrado.'),
            'actividad-guardada' => __('Actividad registrada.'),
            'peso-guardado' => __('Peso registrado.'),
            'plan-reiniciado' => __('Tu día quedó vacío. Empieza cuando quieras.'),
            'dia-cerrado' => __('Tu día quedó cerrado.'),
            'dia-reabierto' => __('Tu día está abierto de nuevo. Puedes cambiar tus respuestas antes de volver a cerrarlo.'),
        ]" />
    </div>

    @if ($errorPerfil)
        <div class="tudi-panel">
            <p class="tudi-label">{{ __('Calculadora Déficit') }}</p>
            <p class="mt-3 text-tudi-on-dark-2">{{ $errorPerfil }}</p>
            <a href="{{ route('calculadora.edit') }}" class="tudi-btn tudi-btn-lime tudi-btn-block mt-5 no-underline">
                {{ __('Ir a la Calculadora Déficit') }}
            </a>
        </div>
    @else

    <div class="space-y-8">

        <div class="grid gap-5 lg:grid-cols-[minmax(0,320px)_minmax(0,1fr)] lg:items-start lg:gap-6">

        {{--
            ══ Objetivo del día ══
            Desde el cierre por comida (sección 5.5) la cifra protagonista ya no
            es solo el objetivo, sino lo que QUEDA de él: las barras miden lo
            comido de verdad contra el objetivo, y cada macro dice cuánto falta
            y para qué comidas (sección 5.21). Todo lo calcula PHP.
        --}}
        <div id="panel-objetivo" class="tudi-panel on-dark lg:sticky lg:top-8" x-data="{ peso: false }">
            <div class="flex items-end justify-between gap-4">
                <span class="tudi-label pb-1.5">{{ __('Objetivo del día') }}</span>
                <span class="flex items-baseline gap-1.5">
                    <span class="tudi-num text-[38px] text-tudi-on-dark">{{ $kcal($objetivos['dia']['calorias_objetivo']) }}</span>
                    <span class="tudi-meta">kcal</span>
                </span>
            </div>

            @if ($saldo)
                <div class="mt-4 border-t border-tudi-dark-3 pt-4">
                    <div class="flex items-end justify-between gap-3">
                        <span class="tudi-label pb-1">
                            {{ $saldo['agotado'] ? __('Te pasaste por') : __('Te quedan') }}
                        </span>
                        <span class="flex items-baseline gap-1.5">
                            <span @class([
                                'tudi-num text-[30px]',
                                'text-tudi-lime' => ! $saldo['agotado'],
                                'text-tudi-amber' => $saldo['agotado'],
                            ])>{{ $kcal(abs($saldo['saldo']['calorias'])) }}</span>
                            <span class="tudi-meta">kcal</span>
                        </span>
                    </div>

                    @if ($pendientesLegible)
                        <p class="tudi-meta mt-1 text-tudi-on-dark-2">
                            {{ __('para') }} {{ $pendientesLegible }}
                        </p>
                    @endif
                </div>

                <div class="mt-4 space-y-3">
                    @foreach ([
                        ['tipo' => 'proteina', 'macro' => 'proteina_g', 'color' => 'var(--tudi-lime)'],
                        ['tipo' => 'grasa', 'macro' => 'grasa_g', 'color' => 'var(--tudi-amber)'],
                        ['tipo' => 'carbohidratos', 'macro' => 'carbohidratos_g', 'color' => 'var(--tudi-on-dark)'],
                    ] as $fila)
                        @php
                            $objetivoMacro = $saldo['objetivo'][$fila['macro']];
                            $consumidoMacro = $saldo['consumido'][$fila['macro']];
                            $saldoMacro = $saldo['saldo'][$fila['macro']];
                        @endphp
                        <div>
                            <div class="flex items-baseline justify-between gap-2">
                                <x-tudi.macro :tipo="$fila['tipo']" variante="palabra" class="tudi-label" />
                                <span class="tudi-meta text-tudi-on-dark">
                                    {{ $saldoMacro >= 0
                                        ? __('quedan :cantidad g', ['cantidad' => $gramos($saldoMacro)])
                                        : __('+:cantidad g de más', ['cantidad' => $gramos(abs($saldoMacro))]) }}
                                </span>
                            </div>
                            <span class="tudi-bar mt-1.5 block" style="--pct: {{ $porcentaje($consumidoMacro, $objetivoMacro) }}">
                                <span style="background: {{ $fila['color'] }}"></span>
                            </span>
                            <p class="tudi-meta mt-1 text-tudi-on-dark-2">
                                {{ $gramos($consumidoMacro) }} / {{ $gramos($objetivoMacro) }} g
                            </p>
                        </div>
                    @endforeach
                </div>
            @endif

            <div class="mt-4 flex items-center justify-between gap-3 border-t border-tudi-dark-3 pt-3">
                <span class="text-[13px] text-tudi-on-dark-2">
                    {{ __('Planificado:') }} {{ $kcal($totalPlanificado) }} kcal
                </span>
                <button type="button" x-on:click="peso = ! peso" class="text-[13px] text-tudi-lime">
                    {{ $registroDiario->peso_kg ? $gramos($registroDiario->peso_kg).' kg' : __('Peso de hoy') }} +
                </button>
            </div>

            <form method="post" action="{{ route('planes.peso', $registroDiario) }}" data-fetch
                  x-show="peso" style="display: none"
                  class="mt-3 flex items-end gap-2">
                @csrf
                <div class="flex-1">
                    <label for="peso_kg" class="tudi-label">{{ __('Tu peso hoy (kg)') }}</label>
                    {{--
                        type="text" + inputmode="decimal", no type="number"
                        (CLAUDE.md sección 9): con type="number" el navegador
                        devuelve valor vacío mientras se escribe "80." y valida
                        `step` por su cuenta, lo que impedía teclear decimales
                        desde el móvil. La coma decimal la normaliza el servidor.
                    --}}
                    <input id="peso_kg" name="peso_kg" type="text" inputmode="decimal" autocomplete="off"
                           value="{{ old('peso_kg', $registroDiario->peso_kg) }}"
                           placeholder="80,4"
                           class="tudi-input mt-1 bg-tudi-dark-2 text-tudi-on-dark"
                           style="border-color: var(--tudi-dark-4)">
                </div>
                <button type="submit" class="tudi-btn tudi-btn-lime flex-none">{{ __('Guardar') }}</button>
            </form>
        </div>

        {{-- ══ Cálculo alimenticio ══ --}}
        <section class="space-y-3">
            <div class="flex items-center justify-between gap-3 px-1">
                <p class="tudi-label">{{ __('Cálculo alimenticio') }}</p>

                <div x-data="{ abierto: false }" x-on:keydown.escape.window="abierto = false">
                    <button type="button" x-on:click="abierto = true" class="tudi-link text-[13px]">
                        {{ __('¿Cómo funciona?') }}
                    </button>

                    <div x-show="abierto" style="display: none"
                         class="fixed inset-0 z-50 flex items-end justify-center bg-tudi-ink/60 p-4 sm:items-center"
                         x-on:click.self="abierto = false" role="dialog" aria-modal="true">
                        <div class="tudi-card w-full max-w-md p-6">
                            <h2 class="text-lg font-semibold tracking-tudi-title">{{ __('¿Cómo funciona?') }}</h2>
                            <div class="mt-3 space-y-3 text-sm text-tudi-ink-3">
                                <p>{{ __('Escribe o dicta lo que tienes para cada comida y pulsa el botón de abajo: se reparte el día entero en una sola consulta.') }}</p>
                                <p>{{ __('El reparto entre comidas lo calcula TUDI: parte de un reparto balanceado (:reparto) y desplaza calorías hacia la comida posterior a tu entrenamiento cuando registras actividad.', ['reparto' => $repartoLegible]) }}</p>
                                <p>{{ __('Si ya cerraste alguna comida, no se toca: lo que se reparte es lo que te queda del objetivo del día.') }}</p>
                                <p>{{ __('No hace falta rellenar las tres. Las comidas sin texto guardan sus calorías para cuando las escribas.') }}</p>
                            </div>
                            <button type="button" x-on:click="abierto = false" class="tudi-btn tudi-btn-primary tudi-btn-block mt-5">
                                {{ __('Entendido') }}
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            {{--
                Cómo quedó repartido el día. Ya no es un panel con porcentajes
                editables (sección 5.14): es una línea que dice qué salió y por
                qué, porque repartir el día es una decisión nutricional, no una
                preferencia de interfaz.
            --}}
            <p class="tudi-meta px-1">
                {{ $repartoLegible }}
                @if ($explicacionReparto['comida'])
                    <span class="text-tudi-lime-700">
                        {{ __('· más para tu :comida por tu actividad de hoy', ['comida' => $explicacionReparto['comida']]) }}
                    </span>
                @endif
            </p>

            <div id="lista-comidas" class="space-y-2.5">
                {{--
                    ── Un solo formulario para las tres comidas ──
                    Al enviarlo viajan juntas y el servidor hace UNA llamada al
                    proveedor, no una por comida (CLAUDE.md sección 5.3).

                    No envuelve a las tarjetas: cada comida lleva dentro su
                    propio formulario de cierre, y un <form> no puede anidarse en
                    otro. Los campos de ingredientes y los botones de ajuste se
                    asocian a este por el atributo `form=`, que es exactamente
                    para lo que existe.
                --}}
                <form id="tudi-ajustar-plan" method="post"
                      action="{{ route('planes.distribucion', $registroDiario) }}" data-fetch
                      data-cargando="{{ __('Ajustando tu plan…') }}"
                      data-cargando-pistas="{{ __('Estamos midiendo lo que te queda del día.') }}|{{ __('Se reparten tus alimentos entre las comidas que faltan.') }}|{{ __('Suele tardar unos segundos.') }}">
                    @csrf
                </form>

                @foreach ($comidas as $comida)
                    @php
                        $plan = $comida['plan'];
                        $real = $plan?->comidaReal;
                        $cerrada = $comida['estado'] === 'registrada';
                        $abierta = $comida['tipo'] === $comidaAbierta;
                    @endphp

                    <details class="tudi-card" data-comida="{{ $comida['tipo'] }}" {{ $abierta ? 'open' : '' }}>
                        <summary class="flex min-h-[56px] cursor-pointer list-none items-center justify-between gap-3 p-4">
                            <span class="flex items-center gap-2.5">
                                <span @class([
                                    'h-2 w-2 flex-none rounded-full',
                                    'bg-tudi-lime' => $comida['estado'] !== 'pendiente',
                                    'bg-tudi-input-border' => $comida['estado'] === 'pendiente',
                                ])></span>
                                <span class="font-semibold capitalize tracking-tudi-title">{{ $comida['tipo'] }}</span>
                                <span class="sr-only">{{ __($comida['estado']) }}</span>
                            </span>
                            <span class="tudi-meta">
                                @if ($cerrada)
                                    {{ $kcal($real->calorias_reales) }} kcal · {{ __('cerrada') }}
                                @else
                                    {{ $kcal($plan->calorias_estimadas ?? $comida['objetivos']['calorias']) }} kcal ·
                                    {{ (int) round($comida['porcentaje'] * 100) }}%
                                @endif
                            </span>
                        </summary>

                        <div class="px-4 pb-4">
                            @if ($cerrada)
                                {{-- Una comida cerrada no se ajusta mientras lo esté (sección 5.3). --}}
                                <p class="tudi-note">
                                    {{ __('Ya cerraste esta comida, así que "Calcular mi plan" no la toca. Reábrela para cambiarla.') }}
                                </p>
                            @else
                                <div class="relative">
                                    <label for="ingredientes-{{ $comida['tipo'] }}" class="sr-only">
                                        {{ __('Ingredientes para el') }} {{ $comida['tipo'] }}
                                    </label>
                                    <textarea id="ingredientes-{{ $comida['tipo'] }}"
                                              name="ingredientes[{{ $comida['tipo'] }}]"
                                              form="tudi-ajustar-plan"
                                              rows="2"
                                              data-dictado
                                              placeholder="{{ __('huevos, queso chitagá y tinto') }}"
                                              class="tudi-input pe-14">{{ old('ingredientes.'.$comida['tipo'], $comida['texto']) }}</textarea>

                                    <button type="button"
                                            data-boton-dictado="ingredientes-{{ $comida['tipo'] }}"
                                            hidden
                                            aria-label="{{ __('Dictar por voz') }}"
                                            class="absolute end-2 top-2 grid h-11 w-11 place-items-center rounded-full text-tudi-muted hover:bg-tudi-surface">
                                        <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" aria-hidden="true">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 15a3 3 0 0 0 3-3V6a3 3 0 1 0-6 0v6a3 3 0 0 0 3 3Zm7-3a7 7 0 0 1-14 0m7 7v3" />
                                        </svg>
                                    </button>
                                </div>
                            @endif

                            @if ($plan && ! $comida['sinPlanPrevio'])
                                <div class="tudi-card-inset mt-3 space-y-3 rounded-tudi-sm">
                                    <p class="font-semibold leading-tight tracking-tudi-title">{{ $plan->descripcion }}</p>

                                    @if (! empty($plan->ingredientes_detalle))
                                        <ul class="space-y-1.5">
                                            @foreach ($plan->ingredientes_detalle as $ingrediente)
                                                <li class="flex items-baseline justify-between gap-3 text-[13px] text-tudi-ink-3">
                                                    <span>
                                                        {{ $ingrediente['nombre'] }} ·
                                                        {{ $ingrediente['porcion'] ?? $kcal($ingrediente['cantidad_g'] ?? 0).' g' }}
                                                    </span>
                                                    <span class="tudi-meta">{{ $kcal($ingrediente['calorias'] ?? 0) }}</span>
                                                </li>
                                            @endforeach
                                        </ul>
                                    @endif

                                    {{--
                                        El icono ilustrado del branding en vez de
                                        la inicial (sección 5.12): el chip es
                                        demasiado estrecho para la palabra
                                        completa, y "P/G/C" no dice nada a quien
                                        empieza.
                                    --}}
                                    <div class="flex flex-wrap items-center gap-2">
                                        <span class="tudi-chip tudi-chip-solid">{{ $kcal($plan->calorias_estimadas) }} kcal</span>
                                        <x-tudi.macro tipo="proteina" :valor="$gramos($plan->proteina_g).' g'" class="tudi-chip" />
                                        <x-tudi.macro tipo="grasa" :valor="$gramos($plan->grasa_g).' g'" class="tudi-chip" />
                                        <x-tudi.macro tipo="carbohidratos" :valor="$gramos($plan->carbohidratos_g).' g'" class="tudi-chip" />
                                    </div>

                                    @if ($plan->notas_ia)
                                        <p class="tudi-note">{{ $plan->notas_ia }}</p>
                                    @endif
                                </div>
                            @endif

                            @if ($plan && ! $cerrada && ! $comida['sinPlanPrevio'] && $cuotaDistribucion > 0)
                                <button type="submit" name="rehacer" value="{{ $comida['tipo'] }}"
                                        form="tudi-ajustar-plan"
                                        data-cargando="{{ __('Rehaciendo el') }} {{ $comida['tipo'] }}…"
                                        class="tudi-btn tudi-btn-secondary mt-3 w-full sm:w-auto">
                                    {{ __('Rehacer solo el') }} {{ $comida['tipo'] }}
                                </button>
                            @endif

                            {{--
                                ── Cierre de esta comida (CLAUDE.md sección 5.5) ──
                                Dentro de la misma tarjeta y a continuación de lo
                                planificado, no en una sección aparte: planificar
                                una comida y contar qué se comió en ella son dos
                                pasos del mismo gesto, y tenerlos separados
                                obligaba a buscar la comida dos veces en la
                                pantalla.
                            --}}
                            <div class="mt-4 border-t border-tudi-divider pt-4">
                                @if ($cerrada)
                                    <p class="tudi-label">{{ __('Lo que comiste') }}</p>

                                    <div class="mt-2 flex flex-wrap items-center gap-2">
                                        <span class="tudi-chip tudi-chip-lime">{{ $kcal($real->calorias_reales) }} kcal</span>
                                        <x-tudi.macro tipo="proteina" :valor="$gramos($real->proteina_g).' g'" class="tudi-chip" />
                                        <x-tudi.macro tipo="grasa" :valor="$gramos($real->grasa_g).' g'" class="tudi-chip" />
                                        <x-tudi.macro tipo="carbohidratos" :valor="$gramos($real->carbohidratos_g).' g'" class="tudi-chip" />
                                    </div>

                                    @if ($real->notas)
                                        <p class="mt-2 text-[13px] text-tudi-ink-3">{{ $real->notas }}</p>
                                    @endif

                                    @if ($real->imagenUrl())
                                        <img src="{{ $real->imagenUrl() }}" alt="{{ __('Evidencia visual') }}"
                                             class="mt-2 max-h-40 rounded-tudi-sm">
                                    @endif

                                    @unless ($registroDiario->cerrado)
                                        <form method="post" action="{{ route('comidas.reabrir', [$registroDiario, $comida['tipo']]) }}"
                                              data-fetch class="mt-3">
                                            @csrf
                                            <button type="submit" class="tudi-btn tudi-btn-secondary w-full sm:w-auto">
                                                {{ __('Reabrir') }} {{ $comida['tipo'] }}
                                            </button>
                                        </form>
                                    @endunless
                                @elseif ($registroDiario->cerrado)
                                    <p class="tudi-note">
                                        {{ __('El día está cerrado. Reábrelo si quieres reportar esta comida.') }}
                                    </p>
                                @else
                                    {{--
                                        Un solo botón que cambia de papel: primero
                                        abre los campos ("Cerrar almuerzo") y, con
                                        ellos a la vista, confirma ("Confirmar
                                        cierre"). Así la tarjeta no enseña un
                                        formulario de reporte a quien todavía no
                                        ha comido.
                                    --}}
                                    <form method="post" action="{{ route('comidas.cerrar', [$registroDiario, $comida['tipo']]) }}"
                                          enctype="multipart/form-data" data-fetch
                                          data-cargando="{{ __('Cerrando tu') }} {{ $comida['tipo'] }}…"
                                          data-cargando-pistas="{{ __('Estamos estimando lo que comiste de verdad.') }}|{{ __('Después entra en el saldo de tu día.') }}"
                                          x-data="{ reportando: false, cumplio: false, foto: '', repetir: '' }">
                                        @csrf

                                        <div x-show="reportando" style="display: none">
                                            {{--
                                                Comidas frecuentes (sección 5.22):
                                                repetir lo de siempre no cuesta
                                                ninguna llamada, porque esos
                                                macros ya se calcularon el día que
                                                se reportaron.
                                            --}}
                                            @if ($comida['frecuentes']->isNotEmpty())
                                                <p class="tudi-label">{{ __('Lo que sueles comer') }}</p>
                                                <div class="mt-2 flex flex-wrap gap-2">
                                                    @foreach ($comida['frecuentes'] as $frecuente)
                                                        {{--
                                                            Un atajo de escritura y nada más: escribe el
                                                            texto en el campo de abajo, no envía el
                                                            formulario ni llama a nadie. El id viaja en un
                                                            campo oculto para que, si ese texto se manda
                                                            sin tocar, se copien los macros que ya se
                                                            calcularon aquel día en vez de volver a
                                                            estimarlos (sección 5.22).
                                                        --}}
                                                        <button type="button"
                                                                x-on:click="
                                                                    $refs.texto.value = @js($frecuente['etiqueta']);
                                                                    $refs.texto.dispatchEvent(new Event('input'));
                                                                    repetir = '{{ $frecuente['comida_real_id'] }}';
                                                                    cumplio = false;
                                                                    $refs.texto.focus();
                                                                "
                                                                class="tudi-btn tudi-btn-secondary text-[13px]">
                                                            {{ $frecuente['etiqueta'] }}
                                                            <span class="tudi-meta">· {{ $kcal($frecuente['calorias']) }} kcal</span>
                                                        </button>
                                                    @endforeach
                                                </div>

                                                <input type="hidden" name="repetir" :value="repetir">
                                            @endif

                                            @if ($plan && ! $comida['sinPlanPrevio'])
                                                <div @class(['flex items-center gap-3', 'mt-4' => $comida['frecuentes']->isNotEmpty()])>
                                                    <div class="flex-1">
                                                        <p class="font-semibold tracking-tudi-title">{{ __('Cumplí lo sugerido') }}</p>
                                                        <p class="tudi-label mt-0.5">
                                                            {{ $kcal($plan->calorias_estimadas) }} kcal ·
                                                            {{ $gramos($plan->proteina_g) }} g {{ __('proteína') }}
                                                        </p>
                                                    </div>

                                                    <label class="flex flex-none cursor-pointer items-center">
                                                        <input type="checkbox" name="cumplio" value="1"
                                                               x-model="cumplio" class="peer sr-only">
                                                        <span class="tudi-switch"></span>
                                                        <span class="sr-only">{{ __('Sí, comí lo que se sugirió') }}</span>
                                                    </label>
                                                </div>
                                            @endif

                                            {{-- El texto manda sobre el interruptor, así que se esconde si dice que cumplió. --}}
                                            <div class="relative mt-3" x-show="! cumplio">
                                                <label for="reporte-{{ $comida['tipo'] }}" class="sr-only">
                                                    {{ __('Qué comiste en el') }} {{ $comida['tipo'] }}
                                                </label>
                                                <textarea id="reporte-{{ $comida['tipo'] }}"
                                                          name="texto"
                                                          rows="2"
                                                          data-dictado
                                                          x-ref="texto"
                                                          x-on:input="repetir = ''"
                                                          placeholder="{{ __('Cuéntanos qué comiste de verdad…') }}"
                                                          class="tudi-input pe-14">{{ old('texto') }}</textarea>

                                                <button type="button"
                                                        data-boton-dictado="reporte-{{ $comida['tipo'] }}"
                                                        hidden
                                                        aria-label="{{ __('Dictar por voz') }}"
                                                        class="absolute end-2 top-2 grid h-11 w-11 place-items-center rounded-full text-tudi-muted hover:bg-tudi-surface">
                                                    <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" aria-hidden="true">
                                                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 15a3 3 0 0 0 3-3V6a3 3 0 1 0-6 0v6a3 3 0 0 0 3 3Zm7-3a7 7 0 0 1-14 0m7 7v3" />
                                                    </svg>
                                                </button>
                                            </div>

                                            {{-- Evidencia visual: opcional, en cualquiera de los caminos. --}}
                                            <label class="mt-3 flex min-h-[44px] cursor-pointer items-center gap-2.5 text-[13px] text-tudi-ink-2">
                                                <input type="file" name="imagen" accept="image/*" class="sr-only"
                                                       x-on:change="foto = $event.target.files[0]?.name || ''">
                                                <svg class="h-5 w-5 flex-none text-tudi-muted" fill="none" stroke="currentColor" stroke-width="1.7" viewBox="0 0 24 24" aria-hidden="true">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M3 8a2 2 0 0 1 2-2h2l1.5-2h7L17 6h2a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8Zm9 9a4 4 0 1 0 0-8 4 4 0 0 0 0 8Z" />
                                                </svg>
                                                <span x-text="foto || '{{ __('Adjuntar foto (opcional)') }}'"
                                                      class="truncate">{{ __('Adjuntar foto (opcional)') }}</span>
                                            </label>
                                        </div>

                                        {{--
                                            Mismo tamaño que "Rehacer solo el X" y negro (tudi-btn-primary):
                                            abrir el reporte de una comida es un paso menor al lado de
                                            "Calcular mi plan", que es la acción que reparte el día
                                            entero — pero sigue siendo una acción normal, no un enlace
                                            secundario.
                                        --}}
                                        <button type="button" x-show="! reportando"
                                                x-on:click="reportando = true"
                                                class="tudi-btn tudi-btn-primary mt-3 w-full sm:w-auto">
                                            {{ __('Cerrar') }} {{ $comida['tipo'] }}
                                        </button>

                                        <button type="submit" x-show="reportando" style="display: none"
                                                class="tudi-btn tudi-btn-primary tudi-btn-block mt-4">
                                            {{ __('Confirmar cierre') }}
                                        </button>

                                        <p class="mt-2 text-center text-xs text-tudi-muted" x-show="reportando" style="display: none">
                                            @if ($cuotaReporte > 0)
                                                {{ __('Contarlo por escrito usa IA (te quedan :restantes de :limite hoy). Cumplir lo sugerido o enviar sin cambios lo que sueles comer, no.', ['restantes' => $cuotaReporte, 'limite' => $limiteReporte]) }}
                                            @else
                                                {{ __('Usaste tus :limite reportes con IA de hoy. Puedes cerrar la comida cumpliendo lo sugerido o enviando sin cambios lo que sueles comer.', ['limite' => $limiteReporte]) }}
                                            @endif
                                        </p>
                                    </form>
                                @endif
                            </div>
                        </div>
                    </details>
                @endforeach

                {{-- ── Una sola acción para las tres comidas ── --}}
                @if ($quedaAlgoQueAjustar)
                    <div class="pt-1.5">
                        @if ($cuotaDistribucion > 0)
                            <button type="submit" form="tudi-ajustar-plan" class="tudi-btn tudi-btn-primary tudi-btn-block">
                                {{ __('Calcular mi plan') }}
                            </button>
                            <p class="mt-2 text-center text-xs text-tudi-muted">
                                {{ __('Reparte lo que te queda del día entre las comidas que faltan.') }}
                                <span class="whitespace-nowrap">
                                    {{ __('Te quedan :restantes de :limite hoy.', ['restantes' => $cuotaDistribucion, 'limite' => $limiteDistribucion]) }}
                                </span>
                            </p>
                        @else
                            {{-- Cuota diaria agotada (sección 5.20): se dice qué SÍ se puede hacer. --}}
                            <p class="tudi-note text-center">
                                {{ __('Usaste tus :limite ajustes de plan de hoy. Puedes seguir cerrando tus comidas y mañana vuelves a tenerlos todos.', ['limite' => $limiteDistribucion]) }}
                            </p>
                        @endif
                    </div>
                @endif
            </div>
        </section>

        </div>

        {{-- ══ Actividad física ══ --}}
        {{--
            Agrupada en un solo desplegable: la sugerencia y el registro de lo
            que se hizo ocupaban dos tarjetas y media pantalla en móvil. Lo
            importante —el objetivo y lo que ya llevas— se lee en la cabecera sin
            abrirlo. Lo que se registre aquí además desplaza el reparto del día
            hacia la comida posterior (sección 5.14).
        --}}
        <section id="seccion-actividad" class="space-y-3">
            <p class="tudi-label px-1">{{ __('Actividad física') }}</p>

            {{-- Cerrada por defecto para no ocupar pantalla; se abre sola justo
                 después de guardar una actividad o si el formulario falló. --}}
            <details class="tudi-card"
                     {{ session('status') === 'actividad-guardada' || $errors->any() ? 'open' : '' }}>
                <summary class="flex min-h-[56px] cursor-pointer list-none items-center justify-between gap-3 p-4">
                    <span class="flex items-center gap-2.5">
                        <span @class([
                            'h-2 w-2 flex-none rounded-full',
                            'bg-tudi-lime' => $actividades->isNotEmpty(),
                            'bg-tudi-input-border' => $actividades->isEmpty(),
                        ])></span>
                        <span class="font-semibold tracking-tudi-title">
                            {{ $actividades->isEmpty() ? __('Sin actividad registrada') : trans_choice(':count actividad|:count actividades', $actividades->count()) }}
                        </span>
                        <span class="sr-only">{{ $actividades->isNotEmpty() ? __('registrada') : __('pendiente') }}</span>
                    </span>
                    <span class="tudi-meta">
                        {{ $kcal($caloriasActividad) }} / {{ $kcal($actividad['calorias_objetivo_actividad']) }} kcal
                    </span>
                </summary>

                <div class="space-y-4 px-4 pb-4">
                    {{-- Sugerencias: qué hacer para llegar al objetivo de hoy. --}}
                    <div class="tudi-card-inset rounded-tudi-sm">
                        <p class="tudi-label">{{ __('Para llegar a tu objetivo de hoy') }}</p>
                        <ul class="mt-2 grid gap-1.5 sm:grid-cols-2 lg:grid-cols-3">
                            @foreach ($actividad['sugerencias'] as $sugerencia)
                                <li class="flex items-center justify-between gap-2 text-sm">
                                    <span class="capitalize">{{ $sugerencia['tipo'] }}</span>
                                    <span class="tudi-meta text-end">
                                        {{ $sugerencia['duracion_min'] }} min · {{ $kcal($sugerencia['calorias_estimadas']) }} kcal
                                    </span>
                                </li>
                            @endforeach
                        </ul>
                    </div>

                    {{-- Lo que sí se hizo, en la misma sección. --}}
                    @if ($actividades->isNotEmpty())
                        <ul class="space-y-1.5">
                            @foreach ($actividades as $registro)
                                <li class="flex items-baseline justify-between gap-3 text-sm">
                                    <span class="capitalize">{{ $registro->tipo }} · {{ $registro->duracion_min }} min</span>
                                    <span class="tudi-meta">{{ $kcal($registro->calorias_ajustadas) }} kcal</span>
                                </li>
                            @endforeach
                        </ul>

                        @if ($explicacionReparto['comida'])
                            <p class="tudi-note">
                                {{ __('Por esta actividad, tu :comida recibe más parte del día y se prioriza en él la energía de los carbohidratos.', ['comida' => $explicacionReparto['comida']]) }}
                            </p>
                        @endif
                    @endif

                    @unless ($registroDiario->cerrado)
                        <form method="post" action="{{ route('actividades.store', $registroDiario) }}"
                              class="space-y-3 border-t border-tudi-divider pt-4"
                              data-cargando="{{ __('Guardando tu actividad…') }}">
                            @csrf

                            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
                                <div>
                                    <label for="tipo_actividad" class="tudi-label">{{ __('Actividad') }}</label>
                                    <input id="tipo_actividad" name="tipo_actividad" type="text" list="tipos-de-actividad" required
                                           value="{{ old('tipo_actividad') }}" placeholder="{{ __('caminata') }}"
                                           class="tudi-input mt-1">
                                    <datalist id="tipos-de-actividad">
                                        @foreach ($actividad['sugerencias'] as $sugerencia)
                                            <option value="{{ $sugerencia['tipo'] }}"></option>
                                        @endforeach
                                    </datalist>
                                </div>
                                <div>
                                    <label for="duracion_min" class="tudi-label">{{ __('Minutos') }}</label>
                                    <input id="duracion_min" name="duracion_min" type="number" inputmode="numeric" min="1" required
                                           value="{{ old('duracion_min') }}" placeholder="45" class="tudi-input mt-1">
                                </div>
                                <div>
                                    <label for="calorias_dispositivo" class="tudi-label">{{ __('Kcal quemadas') }}</label>
                                    {{-- Decimal: mismo criterio que el peso (sección 9). --}}
                                    <input id="calorias_dispositivo" name="calorias_dispositivo" type="text" inputmode="decimal"
                                           autocomplete="off" required
                                           value="{{ old('calorias_dispositivo') }}" placeholder="320" class="tudi-input mt-1">
                                </div>
                                <div>
                                    <label for="pasos" class="tudi-label">{{ __('Pasos') }}</label>
                                    <input id="pasos" name="pasos" type="number" inputmode="numeric" min="0"
                                           value="{{ old('pasos') }}" placeholder="8000" class="tudi-input mt-1">
                                </div>
                                <div>
                                    <label for="fuente" class="tudi-label">{{ __('Fuente') }}</label>
                                    <select id="fuente" name="fuente" required class="tudi-input mt-1">
                                        <option value="manual" @selected(old('fuente') === 'manual')>{{ __('Manual') }}</option>
                                        <option value="dispositivo" @selected(old('fuente') === 'dispositivo')>{{ __('Dispositivo') }}</option>
                                    </select>
                                </div>
                            </div>

                            <button type="submit" class="tudi-btn tudi-btn-primary tudi-btn-block sm:w-auto">
                                {{ __('Guardar actividad') }}
                            </button>
                        </form>
                    @endunless
                </div>
            </details>
        </section>

        {{-- ══ Cierre del día ══ --}}
        <section id="seccion-cierre" class="space-y-3">
            <p class="tudi-label px-1">{{ __('Cierre del día') }}</p>

            <div class="tudi-card p-5">
                <div class="flex items-start justify-between gap-3">
                    <p class="text-base font-semibold tracking-tudi-title">
                        {{ $registroDiario->cerrado ? __('Resumen del cierre') : __('Vista previa (todavía sin cerrar)') }}
                    </p>

                    @if ($registroDiario->cerrado)
                        <form method="post" action="{{ route('cierre.reabrir', $registroDiario) }}">
                            @csrf
                            <button type="submit" class="tudi-btn tudi-btn-secondary">{{ __('Reabrir mi día') }}</button>
                        </form>
                    @endif
                </div>

                <x-tudi.resultado-dia :resumen="$resumenCierre" :cerrado="$registroDiario->cerrado" class="mt-5" />

                @unless ($registroDiario->cerrado)
                    {{--
                        Cerrar el día ya no pregunta nada ni llama a la IA
                        (sección 5.5): lo que se comió se reportó comida a
                        comida arriba. Aquí solo se consolida y se congela, y
                        por eso lo único que queda es el control de validación.
                    --}}
                    <form method="post" action="{{ route('cierre.cerrar', $registroDiario) }}"
                          class="mt-5 border-t border-tudi-divider pt-5"
                          data-cargando="{{ __('Cerrando tu día…') }}">
                        @csrf

                        @if ($comidasSinReportar !== [])
                            <div class="rounded-tudi-md border border-tudi-border p-4">
                                <p class="font-semibold tracking-tudi-title">{{ __('Te falta por reportar') }}</p>
                                <p class="mt-1 text-sm text-tudi-ink-3">
                                    {{ $sinReportarLegible }}.
                                    {{ __('Si cierras el día ahora, esas comidas contarán como cero calorías.') }}
                                </p>

                                @if (count($comidasSinReportar) < count($comidas))
                                    <label class="mt-3 flex min-h-[44px] cursor-pointer items-center gap-2.5 text-[13px] text-tudi-ink-2">
                                        <input type="checkbox" name="confirmar_sin_reportar" value="1" class="peer sr-only">
                                        <span class="tudi-switch"></span>
                                        {{ __('Cerrar el día igualmente') }}
                                    </label>
                                @endif
                            </div>
                        @endif

                        @if (count($comidasSinReportar) < count($comidas))
                            <button type="submit" class="tudi-btn tudi-btn-primary tudi-btn-block mt-4 gap-2.5">
                                {{ __('Cerrar mi día') }}
                                <span class="h-2 w-2 rounded-full bg-tudi-lime"></span>
                            </button>
                            <p class="mt-2.5 text-center text-xs text-tudi-muted">
                                {{ __('Solo suma lo que ya reportaste y congela tus cifras. No usa IA.') }}
                            </p>
                        @else
                            <p class="mt-4 text-center text-sm text-tudi-ink-3">
                                {{ __('Cierra al menos una comida antes de cerrar el día.') }}
                            </p>
                        @endif
                    </form>
                @endunless

                <div class="mt-5 border-t border-tudi-divider pt-5">
                    <div class="flex items-center justify-between gap-3">
                        <p class="tudi-label">{{ __('Recomendaciones') }}</p>

                        {{-- La ayuda va detrás de un "¿Qué es esto?", nunca en
                             un párrafo suelto en pantalla (sección 5.12). --}}
                        <div x-data="{ abierto: false }" x-on:keydown.escape.window="abierto = false">
                            <button type="button" x-on:click="abierto = true" class="tudi-link text-[13px]">
                                {{ __('¿Qué es esto?') }}
                            </button>

                            <div x-show="abierto" style="display: none"
                                 class="fixed inset-0 z-50 flex items-end justify-center bg-tudi-ink/60 p-4 sm:items-center"
                                 x-on:click.self="abierto = false" role="dialog" aria-modal="true">
                                <div class="tudi-card w-full max-w-md p-6">
                                    <h2 class="text-lg font-semibold tracking-tudi-title">{{ __('Recomendaciones') }}</h2>
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

                    @if ($resumenCierre['recomendaciones']->isEmpty())
                        {{--
                            El vacío informa de por qué está vacío (sección 5.6):
                            "todavía no hay historial" y "tu ritmo es correcto"
                            son dos cosas distintas y antes se veían igual.
                        --}}
                        @if ($diagnosticoRecomendaciones === null)
                            <p class="mt-2 text-sm text-tudi-muted">{{ __('Sin recomendaciones para este día.') }}</p>
                        @elseif ($diagnosticoRecomendaciones['listo'])
                            <p class="mt-2 text-sm text-tudi-ink-3">
                                {{ __('Tu ritmo está dentro de lo esperado, así que no hay nada que ajustar.') }}
                                @if ($diagnosticoRecomendaciones['ritmo_pct'] !== null)
                                    <span class="tudi-meta">
                                        {{ __('Pérdida semanal:') }} {{ number_format($diagnosticoRecomendaciones['ritmo_pct'], 2, ',', '.') }}%
                                    </span>
                                @endif
                            </p>
                        @else
                            <x-tudi.diagnostico-checklist :diagnostico="$diagnosticoRecomendaciones" />
                        @endif
                    @else
                        <ul class="mt-3 space-y-4">
                            @foreach ($resumenCierre['recomendaciones'] as $recomendacion)
                                <li>
                                    <p class="text-sm">{{ $recomendacion->justificacion }}</p>
                                    <p class="tudi-meta mt-1">
                                        {{ __($recomendacion->estado) }}
                                        @if ($recomendacion->calorias_objetivo_sugeridas)
                                            · {{ $kcal($recomendacion->calorias_objetivo_sugeridas) }} kcal
                                        @endif
                                    </p>

                                    @if ($recomendacion->estado === 'pendiente')
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
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            </div>
        </section>

        {{--
            ══ Empezar de cero ══ (CLAUDE.md sección 5.16)
            Abajo del todo y detrás de una confirmación: son las dos únicas
            acciones de esta pantalla que borran datos.
        --}}
        <section class="space-y-3">
            <p class="tudi-label px-1">{{ __('Empezar de cero') }}</p>

            <div class="tudi-card flex flex-col gap-3 p-5 sm:flex-row sm:items-center sm:justify-between">
                {{--
                    Reiniciar es "empezar de nuevo el día que estoy viviendo": solo
                    se ofrece en el de hoy. Vaciar un día pasado no vuelve a
                    llenarlo —ya no se puede reportar lo que se comió entonces— y
                    sí borraría historial que alimenta la ventana de 7 días. Para
                    deshacerse de un día viejo está "Eliminar este plan".
                --}}
                @if ($registroDiario->fecha->isToday())
                    <div x-data="{ confirmando: false }" class="min-w-0">
                        <button type="button" x-show="! confirmando" x-on:click="confirmando = true"
                                class="tudi-btn tudi-btn-secondary w-full sm:w-auto">
                            {{ __('Reiniciar este día') }}
                        </button>

                        <form method="post" action="{{ route('planes.resetear', $registroDiario) }}"
                              x-show="confirmando" style="display: none"
                              class="flex flex-wrap gap-2"
                              data-cargando="{{ __('Vaciando tu día…') }}">
                            @csrf
                            <button type="submit" class="tudi-btn bg-tudi-amber text-tudi-ink">
                                {{ __('Sí, borrar lo de hoy y empezar de nuevo') }}
                            </button>
                            <button type="button" x-on:click="confirmando = false" class="tudi-btn tudi-btn-ghost">
                                {{ __('Cancelar') }}
                            </button>
                        </form>

                        <p class="tudi-meta mt-2">{{ __('Borra las sugerencias, lo reportado y la actividad. El día sigue existiendo.') }}</p>
                    </div>
                @else
                    <p class="tudi-meta min-w-0">
                        {{ __('Reiniciar solo está disponible en el día de hoy.') }}
                    </p>
                @endif

                <div x-data="{ confirmando: false }" class="min-w-0 sm:text-end">
                    <button type="button" x-show="! confirmando" x-on:click="confirmando = true"
                            class="tudi-btn tudi-btn-ghost w-full text-tudi-amber-ink sm:w-auto">
                        {{ __('Eliminar este plan') }}
                    </button>

                    <form method="post" action="{{ route('planes.destroy', $registroDiario) }}"
                          x-show="confirmando" style="display: none"
                          class="flex flex-wrap gap-2 sm:justify-end">
                        @csrf
                        @method('delete')
                        <button type="submit" class="tudi-btn bg-tudi-amber text-tudi-ink">
                            {{ __('Sí, eliminar el día entero') }}
                        </button>
                        <button type="button" x-on:click="confirmando = false" class="tudi-btn tudi-btn-ghost">
                            {{ __('Cancelar') }}
                        </button>
                    </form>
                </div>
            </div>
        </section>
    </div>

    @endif
</x-app-layout>
