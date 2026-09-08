@php
    $kcal = fn ($valor) => number_format((float) $valor, 0, ',', '.');
    $gramos = fn ($valor) => number_format((float) $valor, 1, ',', '.');
    $porcentaje = fn ($parte, $total) => $total > 0 ? max(0, min(100, round($parte / $total * 100))) : 0;

    $totalPlanificado = collect($comidas)
        ->map(fn ($comida) => (float) ($comida['plan']->calorias_estimadas ?? 0))
        ->sum();
    $proteinaPlanificada = collect($comidas)
        ->map(fn ($comida) => (float) ($comida['plan']->proteina_g ?? 0))
        ->sum();
    $grasaPlanificada = collect($comidas)
        ->map(fn ($comida) => (float) ($comida['plan']->grasa_g ?? 0))
        ->sum();
    $carbohidratosPlanificados = collect($comidas)
        ->map(fn ($comida) => (float) ($comida['plan']->carbohidratos_g ?? 0))
        ->sum();

    // Comidas planificadas pendientes de registrar: son las que el cierre
    // pregunta si se cumplieron (CLAUDE.md sección 4.16). Desde la sección 4.23
    // el cierre es el ÚNICO sitio donde se registra lo que se comió.
    $comidasPorConfirmar = collect($comidas)->where('estado', 'planificada');

    // "Generar distribución" es una sola acción para las tres comidas (sección
    // 4.23): sobra en cuanto no queda ninguna por resolver.
    $quedaAlgoQueGenerar = collect($comidas)->contains(fn ($comida) => $comida['estado'] !== 'registrada');

    // Acordeón: solo una comida abierta a la vez. Arranca en la primera que
    // todavía no se ha registrado — la que el usuario tiene que resolver.
    $comidaAbierta = collect($comidas)->firstWhere('estado', '!=', 'registrada')['tipo'] ?? null;

    $caloriasActividad = $actividades->sum(fn ($registro) => (float) $registro->calorias_ajustadas);
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
            'distribucion-generada' => __('Distribución generada.'),
            'plan-generado' => __('Plan generado.'),
            'comida-real-guardada' => __('Comida registrada.'),
            'actividad-guardada' => __('Actividad registrada.'),
            'peso-guardado' => __('Peso registrado.'),
            'dia-cerrado' => __('Tu día quedó cerrado.'),
            'dia-reabierto' => __('Tu día está abierto de nuevo.'),
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

        {{-- ══ Objetivo del día: el dato protagonista de la pantalla ══ --}}
        <div id="panel-objetivo" class="tudi-panel on-dark lg:sticky lg:top-8" x-data="{ peso: false }">
            <div class="flex items-end justify-between gap-4">
                <span class="tudi-label pb-1.5">{{ __('Objetivo del día') }}</span>
                <span class="flex items-baseline gap-1.5">
                    <span class="tudi-num text-[38px] text-tudi-on-dark">{{ $kcal($objetivos['dia']['calorias_objetivo']) }}</span>
                    <span class="tudi-meta">kcal</span>
                </span>
            </div>

            <div class="mt-4 space-y-2.5">
                @foreach ([
                    ['letra' => 'P', 'objetivo' => $objetivos['dia']['proteina_g'], 'planificado' => $proteinaPlanificada, 'color' => 'var(--tudi-lime)'],
                    ['letra' => 'G', 'objetivo' => $objetivos['dia']['grasa_g'], 'planificado' => $grasaPlanificada, 'color' => 'var(--tudi-amber)'],
                    ['letra' => 'C', 'objetivo' => $objetivos['dia']['carbohidratos_g'], 'planificado' => $carbohidratosPlanificados, 'color' => 'var(--tudi-on-dark)'],
                ] as $macro)
                    <div class="flex items-center gap-3">
                        <span class="tudi-meta w-4">{{ $macro['letra'] }}</span>
                        <span class="tudi-bar flex-1" style="--pct: {{ $porcentaje($macro['planificado'], $macro['objetivo']) }}">
                            <span style="background: {{ $macro['color'] }}"></span>
                        </span>
                        <span class="tudi-meta w-16 text-end text-tudi-on-dark">{{ $gramos($macro['objetivo']) }} g</span>
                    </div>
                @endforeach
            </div>

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
                        (CLAUDE.md sección 4.24): con type="number" el navegador
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
                                <p>{{ __('Escribe o dicta lo que tienes para cada comida. Con un solo botón se reparte el día entero: cada comida recibe su parte del objetivo (25% desayuno, 40% almuerzo, 35% cena).') }}</p>
                                <p>{{ __('Las tres comidas viajan juntas en una sola consulta, así que rellenarlas todas antes de generar cuesta lo mismo que rellenar una.') }}</p>
                                <p>{{ __('No hace falta rellenar las tres. Lo que ya está generado no se toca, y las comidas sin texto guardan sus calorías para cuando las escribas.') }}</p>
                            </div>
                            <button type="button" x-on:click="abierto = false" class="tudi-btn tudi-btn-primary tudi-btn-block mt-5">
                                {{ __('Entendido') }}
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            {{--
                Un solo formulario con las tres comidas dentro: al enviarlo
                viajan juntas y el servidor hace UNA llamada al proveedor, no
                una por comida (CLAUDE.md sección 4.23).
            --}}
            <form method="post" action="{{ route('planes.distribucion', $registroDiario) }}" data-fetch
                  data-cargando="{{ __('Generando tu distribución…') }}"
                  data-cargando-pistas="{{ __('Estamos repartiendo tus alimentos entre las comidas.') }}|{{ __('Se calculan las calorías y los macros de cada porción.') }}|{{ __('Suele tardar unos segundos.') }}">
                @csrf

                <div id="lista-comidas" class="space-y-2.5">
                    @foreach ($comidas as $comida)
                        @php
                            $plan = $comida['plan'];
                            $comidaReal = $plan?->comidaReal;
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
                                    {{ $kcal($plan->calorias_estimadas ?? $comida['objetivos']['calorias']) }} kcal ·
                                    {{ (int) round($comida['porcentaje'] * 100) }}%
                                </span>
                            </summary>

                            <div class="px-4 pb-4">
                                @if ($comida['estado'] !== 'registrada')
                                    <div class="relative">
                                        <label for="ingredientes-{{ $comida['tipo'] }}" class="sr-only">
                                            {{ __('Ingredientes para el') }} {{ $comida['tipo'] }}
                                        </label>
                                        <textarea id="ingredientes-{{ $comida['tipo'] }}"
                                                  name="ingredientes[{{ $comida['tipo'] }}]"
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

                                @if ($plan)
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

                                        <div class="flex flex-wrap items-center gap-2">
                                            <span class="tudi-chip tudi-chip-solid">{{ $kcal($plan->calorias_estimadas) }} kcal</span>
                                            <span class="tudi-chip">P {{ $gramos($plan->proteina_g) }}</span>
                                            <span class="tudi-chip">G {{ $gramos($plan->grasa_g) }}</span>
                                            <span class="tudi-chip">C {{ $gramos($plan->carbohidratos_g) }}</span>
                                        </div>

                                        @if ($plan->notas_ia)
                                            <p class="tudi-note">{{ $plan->notas_ia }}</p>
                                        @endif

                                        @if ($comidaReal)
                                            <div class="border-t border-tudi-surface pt-3">
                                                <p class="tudi-label">{{ __('Lo que comiste') }}</p>
                                                <div class="mt-2 flex flex-wrap items-center gap-2">
                                                    <span class="tudi-chip tudi-chip-lime">{{ $kcal($comidaReal->calorias_reales) }} kcal</span>
                                                    <span class="tudi-chip">P {{ $gramos($comidaReal->proteina_g) }}</span>
                                                    <span class="tudi-chip">G {{ $gramos($comidaReal->grasa_g) }}</span>
                                                    <span class="tudi-chip">C {{ $gramos($comidaReal->carbohidratos_g) }}</span>
                                                </div>
                                                @if ($comidaReal->notas)
                                                    <p class="mt-2 text-[13px] text-tudi-ink-3">{{ $comidaReal->notas }}</p>
                                                @endif
                                                @if ($comidaReal->imagenUrl())
                                                    <img src="{{ $comidaReal->imagenUrl() }}" alt="{{ __('Evidencia visual') }}"
                                                         class="mt-2 max-h-48 rounded-tudi-sm">
                                                @endif
                                            </div>
                                        @endif
                                    </div>
                                @endif

                                {{--
                                    Ya no hay botón "Registrar" por comida
                                    (sección 4.23): lo que se comió se cuenta una
                                    sola vez, abajo, al cerrar el día. Aquí solo
                                    queda rehacer una comida cuyo texto no cambió.
                                --}}
                                @if ($plan && ! $comidaReal)
                                    <button type="submit" name="rehacer" value="{{ $comida['tipo'] }}"
                                            data-cargando="{{ __('Rehaciendo el') }} {{ $comida['tipo'] }}…"
                                            class="tudi-btn tudi-btn-secondary mt-3 w-full sm:w-auto">
                                        {{ __('Rehacer solo el') }} {{ $comida['tipo'] }}
                                    </button>
                                @endif
                            </div>
                        </details>
                    @endforeach

                    {{-- ── Una sola acción para las tres comidas ── --}}
                    @if ($quedaAlgoQueGenerar)
                        <div class="pt-1.5">
                            <button type="submit" class="tudi-btn tudi-btn-primary tudi-btn-block">
                                {{ __('Generar distribución') }}
                            </button>
                            <p class="mt-2 text-center text-xs text-tudi-muted">
                                {{ __('Una sola consulta para desayuno, almuerzo y cena.') }}
                            </p>
                        </div>
                    @endif
                </div>
            </form>
        </section>

        </div>

        {{-- ══ Actividad física ══ --}}
        {{--
            Agrupada en un solo desplegable (sección 4.23): la sugerencia y el
            registro de lo que se hizo ocupaban dos tarjetas y media pantalla en
            móvil. Lo importante —el objetivo y lo que ya llevas— se lee en la
            cabecera sin abrirlo.
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
                                    {{-- Decimal: mismo criterio que el peso (sección 4.24). --}}
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

                <dl class="mt-4 grid grid-cols-2 gap-4 sm:grid-cols-4">
                    <div>
                        <dt class="tudi-label">{{ __('Consumidas') }}</dt>
                        <dd class="tudi-num mt-1 text-xl">{{ $kcal($resumenCierre['calorias_consumidas']) }}</dd>
                    </div>
                    <div>
                        <dt class="tudi-label">{{ __('Actividad') }}</dt>
                        <dd class="tudi-num mt-1 text-xl">{{ $kcal($resumenCierre['calorias_actividad_ajustada']) }}</dd>
                    </div>
                    <div>
                        <dt class="tudi-label">{{ __('Déficit') }}</dt>
                        <dd @class([
                            'tudi-num mt-1 text-xl',
                            'text-tudi-lime-700' => $resumenCierre['deficit_diario'] >= 0,
                            'text-tudi-amber-ink' => $resumenCierre['deficit_diario'] < 0,
                        ])>{{ $kcal($resumenCierre['deficit_diario']) }}</dd>
                    </div>
                    <div>
                        <dt class="tudi-label">{{ __('Proteína') }}</dt>
                        <dd class="tudi-num mt-1 text-xl">{{ number_format($resumenCierre['cumplimiento_proteina_pct'], 1, ',', '.') }}%</dd>
                    </div>
                </dl>

                @unless ($registroDiario->cerrado)
                    {{--
                        enctype multipart: desde la sección 4.23 la foto de
                        evidencia de cada comida se adjunta aquí, que es donde ya
                        se preguntaba qué se comió.
                    --}}
                    <form method="post" action="{{ route('cierre.cerrar', $registroDiario) }}"
                          enctype="multipart/form-data"
                          class="mt-5 border-t border-tudi-divider pt-5"
                          data-cargando="{{ __('Cerrando tu día…') }}"
                          data-cargando-pistas="{{ __('Estamos estimando lo que comiste de verdad.') }}|{{ __('Después se congelan tus cifras del día.') }}|{{ __('Suele tardar unos segundos.') }}">
                        @csrf

                        @if ($comidasPorConfirmar->isNotEmpty())
                            <p class="text-base font-semibold tracking-tudi-title">{{ __('¿Cumpliste con lo sugerido?') }}</p>

                            <div class="mt-3 space-y-2.5">
                                @foreach ($comidasPorConfirmar as $comida)
                                    <div class="rounded-tudi-md border border-tudi-border p-4"
                                         x-data="{ cumplio: false, foto: '' }">
                                        <div class="flex items-center gap-3">
                                            <div class="flex-1">
                                                <p class="font-semibold capitalize tracking-tudi-title">{{ $comida['tipo'] }}</p>
                                                <p class="tudi-label mt-0.5">
                                                    {{ __('Sugerido') }} {{ $kcal($comida['plan']->calorias_estimadas) }} kcal
                                                </p>
                                            </div>

                                            <label class="flex flex-none cursor-pointer items-center">
                                                <input type="checkbox" name="feedback[{{ $comida['tipo'] }}][cumplio]" value="1"
                                                       x-model="cumplio" class="peer sr-only">
                                                <span class="tudi-switch"></span>
                                                <span class="sr-only">{{ __('Sí, comí lo que se sugirió') }}</span>
                                            </label>
                                        </div>

                                        {{-- Un solo campo, y solo si el interruptor dice que no. --}}
                                        <div class="relative mt-3" x-show="! cumplio">
                                            <label for="feedback-{{ $comida['tipo'] }}" class="sr-only">
                                                {{ __('Qué comiste en el') }} {{ $comida['tipo'] }}
                                            </label>
                                            <textarea id="feedback-{{ $comida['tipo'] }}"
                                                      name="feedback[{{ $comida['tipo'] }}][texto]"
                                                      rows="2"
                                                      data-dictado
                                                      placeholder="{{ __('Cuéntanos qué comiste de verdad…') }}"
                                                      class="tudi-input pe-14">{{ old('feedback.'.$comida['tipo'].'.texto') }}</textarea>

                                            <button type="button"
                                                    data-boton-dictado="feedback-{{ $comida['tipo'] }}"
                                                    hidden
                                                    aria-label="{{ __('Dictar por voz') }}"
                                                    class="absolute end-2 top-2 grid h-11 w-11 place-items-center rounded-full text-tudi-muted hover:bg-tudi-surface">
                                                <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" aria-hidden="true">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 15a3 3 0 0 0 3-3V6a3 3 0 1 0-6 0v6a3 3 0 0 0 3 3Zm7-3a7 7 0 0 1-14 0m7 7v3" />
                                                </svg>
                                            </button>
                                        </div>

                                        {{-- Evidencia visual: opcional, en los dos caminos. --}}
                                        <label class="mt-3 flex min-h-[44px] cursor-pointer items-center gap-2.5 text-[13px] text-tudi-ink-2">
                                            <input type="file" name="feedback[{{ $comida['tipo'] }}][imagen]"
                                                   accept="image/*" class="sr-only"
                                                   x-on:change="foto = $event.target.files[0]?.name || ''">
                                            <svg class="h-5 w-5 flex-none text-tudi-muted" fill="none" stroke="currentColor" stroke-width="1.7" viewBox="0 0 24 24" aria-hidden="true">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M3 8a2 2 0 0 1 2-2h2l1.5-2h7L17 6h2a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8Zm9 9a4 4 0 1 0 0-8 4 4 0 0 0 0 8Z" />
                                            </svg>
                                            <span x-text="foto || '{{ __('Adjuntar foto (opcional)') }}'"
                                                  class="truncate">{{ __('Adjuntar foto (opcional)') }}</span>
                                        </label>
                                    </div>
                                @endforeach
                            </div>
                        @endif

                        <button type="submit" class="tudi-btn tudi-btn-primary tudi-btn-block mt-4 gap-2.5">
                            {{ __('Cerrar mi día') }}
                            <span class="h-2 w-2 rounded-full bg-tudi-lime"></span>
                        </button>
                        <p class="mt-2.5 text-center text-xs text-tudi-muted">
                            {{ __('Al cerrar se congelan tus cifras del día.') }}
                        </p>
                    </form>
                @endunless

                <div class="mt-5 border-t border-tudi-divider pt-5">
                    <p class="tudi-label">{{ __('Recomendaciones') }}</p>

                    @if ($resumenCierre['recomendaciones']->isEmpty())
                        <p class="mt-2 text-sm text-tudi-muted">{{ __('Sin recomendaciones para este día.') }}</p>
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
    </div>

    @endif
</x-app-layout>
