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

    // Comidas que ya tienen respuesta: el cierre las muestra con lo que se
    // contestó y, con el día abierto, deja cambiarla (sección 5.5).
    $comidasRespondidas = collect($comidas)->where('estado', 'registrada');

    // ¿Este día usa el 25/40/35 de fábrica o uno propio? (sección 5.14)
    $esRepartoDeFabrica = collect($repartoDeFabrica)
        ->every(fn ($proporcion, $tipo) => abs(($reparto[$tipo] ?? 0) - $proporcion) < 0.005);

    $repartoLegible = collect($reparto)
        ->map(fn ($proporcion, $tipo) => (int) round($proporcion * 100).'% '.$tipo)
        ->implode(', ');
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
            'comida-real-eliminada' => __('Puedes volver a responder por esa comida.'),
            'comida-real-inexistente' => __('Esa comida no tenía nada registrado.'),
            'actividad-guardada' => __('Actividad registrada.'),
            'peso-guardado' => __('Peso registrado.'),
            'reparto-guardado' => __('Reparto actualizado.'),
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

        {{-- ══ Objetivo del día: el dato protagonista de la pantalla ══ --}}
        <div id="panel-objetivo" class="tudi-panel on-dark lg:sticky lg:top-8" x-data="{ peso: false }">
            <div class="flex items-end justify-between gap-4">
                <span class="tudi-label pb-1.5">{{ __('Objetivo del día') }}</span>
                <span class="flex items-baseline gap-1.5">
                    <span class="tudi-num text-[38px] text-tudi-on-dark">{{ $kcal($objetivos['dia']['calorias_objetivo']) }}</span>
                    <span class="tudi-meta">kcal</span>
                </span>
            </div>

            {{--
                Palabra completa, no la inicial (CLAUDE.md sección 5.12): aquí
                el espacio lo permite, y "P/G/C" solo se entiende cuando ya
                sabes lo que significan. Los iconos ilustrados van en los chips
                compactos sobre crema, no sobre el panel carbón.
            --}}
            <div class="mt-4 space-y-3">
                @foreach ([
                    ['tipo' => 'proteina', 'objetivo' => $objetivos['dia']['proteina_g'], 'planificado' => $proteinaPlanificada, 'color' => 'var(--tudi-lime)'],
                    ['tipo' => 'grasa', 'objetivo' => $objetivos['dia']['grasa_g'], 'planificado' => $grasaPlanificada, 'color' => 'var(--tudi-amber)'],
                    ['tipo' => 'carbohidratos', 'objetivo' => $objetivos['dia']['carbohidratos_g'], 'planificado' => $carbohidratosPlanificados, 'color' => 'var(--tudi-on-dark)'],
                ] as $macro)
                    <div>
                        <div class="flex items-baseline justify-between gap-2">
                            <x-tudi.macro :tipo="$macro['tipo']" variante="palabra" class="tudi-label" />
                            <span class="tudi-meta text-tudi-on-dark">{{ $gramos($macro['objetivo']) }} g</span>
                        </div>
                        <span class="tudi-bar mt-1.5 block" style="--pct: {{ $porcentaje($macro['planificado'], $macro['objetivo']) }}">
                            <span style="background: {{ $macro['color'] }}"></span>
                        </span>
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
                                <p>{{ __('Escribe o dicta lo que tienes para cada comida. Con un solo botón se reparte el día entero: cada comida recibe su parte del objetivo (:reparto).', ['reparto' => $repartoLegible]) }}</p>
                                <p>{{ __('Ese reparto se cambia en "Reparto del día", y puedes dejarlo distinto solo para hoy o guardarlo como tu reparto habitual.') }}</p>
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
                ── Reparto del día (sección 5.14) ──
                Fuera del formulario de distribución: son dos envíos distintos y
                un <form> no puede anidarse dentro de otro.
            --}}
            <div id="seccion-reparto">
                <details class="tudi-card" {{ $errors->has('reparto') ? 'open' : '' }}>
                    <summary class="flex min-h-[52px] cursor-pointer list-none items-center justify-between gap-3 px-4 py-3">
                        <span class="text-[13px] font-semibold tracking-tudi-title">{{ __('Reparto del día') }}</span>
                        <span class="tudi-meta">
                            {{ collect($reparto)->map(fn ($p) => (int) round($p * 100).'%')->implode(' · ') }}
                            @unless ($esRepartoDeFabrica)
                                <span class="text-tudi-amber-ink">{{ __('· propio') }}</span>
                            @endunless
                        </span>
                    </summary>

                    <form method="post" action="{{ route('planes.reparto', $registroDiario) }}" data-fetch
                          data-fetch-secciones="#tudi-avisos,#panel-objetivo,#seccion-reparto,#lista-comidas,#seccion-cierre"
                          x-data="{
                              reparto: @js(collect($reparto)->map(fn ($p) => (int) round($p * 100))),
                              get suma() { return Object.values(this.reparto).reduce((a, b) => a + Number(b || 0), 0) },
                          }"
                          class="px-4 pb-4">
                        @csrf

                        <div class="grid grid-cols-3 gap-2">
                            @foreach (array_keys($repartoDeFabrica) as $tipoComida)
                                <div>
                                    <label for="reparto-{{ $tipoComida }}" class="tudi-label capitalize">{{ $tipoComida }}</label>
                                    {{--
                                        Porcentajes enteros: aquí type="number"
                                        sí (no es un decimal — sección 9), con
                                        los pasos de 5 que se usan de verdad.
                                    --}}
                                    <input id="reparto-{{ $tipoComida }}"
                                           name="reparto[{{ $tipoComida }}]"
                                           type="number" inputmode="numeric" min="5" max="100" step="1" required
                                           x-model.number="reparto.{{ $tipoComida }}"
                                           value="{{ old('reparto.'.$tipoComida, (int) round($reparto[$tipoComida] * 100)) }}"
                                           class="tudi-input mt-1 text-center">
                                </div>
                            @endforeach
                        </div>

                        <div class="mt-3 flex flex-wrap items-center justify-between gap-3">
                            <span class="tudi-meta" :class="suma === 100 ? '' : 'text-tudi-amber-ink'">
                                {{ __('Suma') }} <span x-text="suma"></span>%
                            </span>

                            <label class="flex min-h-[44px] cursor-pointer items-center gap-2 text-[13px] text-tudi-ink-2">
                                <input type="checkbox" name="como_habitual" value="1" class="peer sr-only">
                                <span class="tudi-switch"></span>
                                {{ __('Guardar como mi reparto habitual') }}
                            </label>
                        </div>

                        <x-input-error :messages="$errors->get('reparto')" class="mt-2" />

                        <button type="submit" class="tudi-btn tudi-btn-secondary mt-3 w-full sm:w-auto"
                                :disabled="suma !== 100">
                            {{ __('Aplicar reparto') }}
                        </button>

                        <p class="tudi-meta mt-2">
                            {{ __('Solo afecta a lo que quede por generar; los planes ya hechos no cambian.') }}
                        </p>
                    </form>
                </details>
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

                                        {{--
                                            El icono ilustrado del branding en
                                            vez de la inicial (sección 5.12): el
                                            chip es demasiado estrecho para la
                                            palabra completa, y "P/G/C" no dice
                                            nada a quien empieza.
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

                                        @if ($comidaReal)
                                            <div class="border-t border-tudi-surface pt-3">
                                                <p class="tudi-label">{{ __('Lo que comiste') }}</p>
                                                <div class="mt-2 flex flex-wrap items-center gap-2">
                                                    <span class="tudi-chip tudi-chip-lime">{{ $kcal($comidaReal->calorias_reales) }} kcal</span>
                                                    <x-tudi.macro tipo="proteina" :valor="$gramos($comidaReal->proteina_g).' g'" class="tudi-chip" />
                                                    <x-tudi.macro tipo="grasa" :valor="$gramos($comidaReal->grasa_g).' g'" class="tudi-chip" />
                                                    <x-tudi.macro tipo="carbohidratos" :valor="$gramos($comidaReal->carbohidratos_g).' g'" class="tudi-chip" />
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

                {{--
                    ── Resultado real del día (CLAUDE.md sección 5.5) ──
                    El espejo de "Objetivo del día": las mismas cuatro cifras,
                    pero las que se comieron de verdad. Sin esto, el cierre
                    contaba las calorías y la proteína y callaba sobre los otros
                    dos macros, que sí se muestran arriba como objetivo.
                --}}
                <div class="tudi-card-inset mt-5 rounded-tudi-sm">
                    <div class="flex items-end justify-between gap-4">
                        <span class="tudi-label pb-1">
                            {{ $registroDiario->cerrado ? __('Resultado real del día') : __('Lo que llevas comido') }}
                        </span>
                        <span class="flex items-baseline gap-1.5">
                            <span class="tudi-num text-[30px]">{{ $kcal($resumenCierre['calorias_consumidas']) }}</span>
                            <span class="tudi-meta">/ {{ $kcal($resumenCierre['calorias_objetivo']) }} kcal</span>
                        </span>
                    </div>

                    <div class="mt-3 space-y-3">
                        @foreach ([
                            ['tipo' => 'proteina', 'real' => $resumenCierre['proteina_consumida_g'], 'objetivo' => $resumenCierre['proteina_objetivo_g'], 'color' => 'var(--tudi-lime-700)'],
                            ['tipo' => 'grasa', 'real' => $resumenCierre['grasa_consumida_g'], 'objetivo' => $resumenCierre['grasa_objetivo_g'], 'color' => 'var(--tudi-amber)'],
                            ['tipo' => 'carbohidratos', 'real' => $resumenCierre['carbohidratos_consumidos_g'], 'objetivo' => $resumenCierre['carbohidratos_objetivo_g'], 'color' => 'var(--tudi-ink)'],
                        ] as $macro)
                            <div>
                                <div class="flex items-baseline justify-between gap-2">
                                    {{-- Icono Y palabra: en esta tarjeta cabe, y es donde el
                                         usuario compara lo comido contra su objetivo. --}}
                                    <span class="flex items-center gap-2">
                                        <x-tudi.macro :tipo="$macro['tipo']" />
                                        <x-tudi.macro :tipo="$macro['tipo']" variante="palabra" class="text-[13px] font-semibold" />
                                    </span>
                                    <span class="tudi-meta">
                                        {{-- Los días cerrados antes de que el snapshot guardara grasa y
                                             carbohidratos no tienen estas cifras: se dicen ausentes, no cero. --}}
                                        @if ($macro['real'] === null || $macro['objetivo'] === null)
                                            —
                                        @else
                                            <span class="text-tudi-ink">{{ $gramos($macro['real']) }}</span>
                                            / {{ $gramos($macro['objetivo']) }} g
                                        @endif
                                    </span>
                                </div>
                                <span class="tudi-bar on-light mt-1.5 block"
                                      style="--pct: {{ $macro['real'] === null || $macro['objetivo'] === null ? 0 : $porcentaje($macro['real'], $macro['objetivo']) }}">
                                    <span style="background: {{ $macro['color'] }}"></span>
                                </span>
                            </div>
                        @endforeach
                    </div>
                </div>

                {{--
                    ── Lo que respondiste, comida a comida (sección 5.5) ──
                    Al cerrar, el resumen decía cuántas calorías entraron pero no
                    de dónde salía esa cifra. Con el día abierto, cada respuesta
                    se puede cambiar: eso es lo que devuelve la pregunta después
                    de reabrir un día.
                --}}
                @if ($comidasRespondidas->isNotEmpty())
                    <div class="mt-5 border-t border-tudi-divider pt-5">
                        <p class="tudi-label">{{ __('Lo que respondiste') }}</p>

                        <ul class="mt-3 space-y-2.5">
                            @foreach ($comidasRespondidas as $comida)
                                @php $real = $comida['plan']->comidaReal; @endphp
                                <li class="rounded-tudi-md border border-tudi-border p-4">
                                    <div class="flex flex-wrap items-baseline justify-between gap-2">
                                        <span class="font-semibold capitalize tracking-tudi-title">{{ $comida['tipo'] }}</span>
                                        <span class="tudi-meta">
                                            {{ $kcal($real->calorias_reales) }} kcal
                                            <span class="text-tudi-muted">({{ __('sugerido') }} {{ $kcal($comida['plan']->calorias_estimadas) }})</span>
                                        </span>
                                    </div>

                                    @if ($real->notas)
                                        <p class="mt-1.5 text-[13px] text-tudi-ink-3">{{ $real->notas }}</p>
                                    @endif

                                    <div class="mt-2 flex flex-wrap items-center gap-2">
                                        <x-tudi.macro tipo="proteina" :valor="$gramos($real->proteina_g).' g'" class="tudi-chip" />
                                        <x-tudi.macro tipo="grasa" :valor="$gramos($real->grasa_g).' g'" class="tudi-chip" />
                                        <x-tudi.macro tipo="carbohidratos" :valor="$gramos($real->carbohidratos_g).' g'" class="tudi-chip" />
                                    </div>

                                    @if ($real->imagenUrl())
                                        <img src="{{ $real->imagenUrl() }}" alt="{{ __('Evidencia visual') }}"
                                             class="mt-2 max-h-40 rounded-tudi-sm">
                                    @endif

                                    @unless ($registroDiario->cerrado)
                                        <form method="post" action="{{ route('comida-real.destroy', $comida['plan']) }}" class="mt-3">
                                            @csrf
                                            @method('delete')
                                            <button type="submit" class="tudi-btn tudi-btn-ghost text-[13px]">
                                                {{ __('Cambiar mi respuesta') }}
                                            </button>
                                        </form>
                                    @endunless
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif

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
                    <div class="flex items-center justify-between gap-3">
                        <p class="tudi-label">{{ __('Recomendaciones') }}</p>

                        {{-- La ayuda va detrás de un "¿Cómo funciona?", nunca en
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
                            <p class="mt-2 text-sm text-tudi-ink-3">
                                {{ __('Todavía no hay historial suficiente para sugerirte un ajuste.') }}
                            </p>

                            <ul class="mt-3 space-y-2">
                                @foreach ([
                                    [
                                        'hecho' => $diagnosticoRecomendaciones['historial_completo'],
                                        'texto' => __('Días con plan en la última semana'),
                                        'cifra' => $diagnosticoRecomendaciones['dias_con_datos'].' / '.$diagnosticoRecomendaciones['dias_necesarios'],
                                    ],
                                    [
                                        'hecho' => $diagnosticoRecomendaciones['dias_con_peso'] > 0,
                                        'texto' => __('Pesajes en la última semana'),
                                        'cifra' => (string) $diagnosticoRecomendaciones['dias_con_peso'],
                                    ],
                                    [
                                        'hecho' => $diagnosticoRecomendaciones['dias_con_peso_anterior'] > 0,
                                        'texto' => __('Pesajes en la semana anterior'),
                                        'cifra' => (string) $diagnosticoRecomendaciones['dias_con_peso_anterior'],
                                    ],
                                ] as $requisito)
                                    <li class="flex items-center justify-between gap-3 text-sm">
                                        <span class="flex items-center gap-2.5">
                                            <span @class([
                                                'h-2 w-2 flex-none rounded-full',
                                                'bg-tudi-lime' => $requisito['hecho'],
                                                'bg-tudi-input-border' => ! $requisito['hecho'],
                                            ])></span>
                                            {{ $requisito['texto'] }}
                                        </span>
                                        <span class="tudi-meta">{{ $requisito['cifra'] }}</span>
                                    </li>
                                @endforeach
                            </ul>
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

                    <p class="tudi-meta mt-2">{{ __('Borra las sugerencias, lo registrado y la actividad. El día sigue existiendo.') }}</p>
                </div>

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
