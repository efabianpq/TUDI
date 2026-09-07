@php
    $kcal = fn ($valor) => number_format((float) $valor, 0, ',', '.');
    $gramos = fn ($valor) => number_format((float) $valor, 1, ',', '.');

    $totalPlanificado = collect($comidas)
        ->map(fn ($comida) => (float) ($comida['plan']->calorias_estimadas ?? 0))
        ->sum();
    $proteinaPlanificada = collect($comidas)
        ->map(fn ($comida) => (float) ($comida['plan']->proteina_g ?? 0))
        ->sum();

    // Comidas planificadas pendientes de registrar: son las que el cierre
    // pregunta si se cumplieron (CLAUDE.md sección 4.16).
    $comidasPorConfirmar = collect($comidas)->where('estado', 'planificada');
@endphp

<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
            <div class="flex items-baseline gap-3">
                <a href="{{ route('planes.index') }}" class="text-sm text-indigo-600 hover:underline">
                    &larr; {{ __('Planes') }}
                </a>
                <h2 class="font-semibold text-lg sm:text-xl text-gray-800 leading-tight">
                    {{ __('Plan del') }} {{ $registroDiario->fecha->format('d/m/Y') }}
                </h2>
            </div>
            <span @class([
                'inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium',
                'bg-green-100 text-green-800' => $registroDiario->cerrado,
                'bg-amber-100 text-amber-800' => ! $registroDiario->cerrado,
            ])>{{ $registroDiario->cerrado ? __('cerrado') : __('abierto') }}</span>
        </div>
    </x-slot>

    <div class="py-4 sm:py-8">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-4 sm:space-y-6">

            @if (session('error'))
                <div class="p-4 bg-red-50 border border-red-200 text-red-700 rounded-xl text-sm">
                    {{ session('error') }}
                </div>
            @endif

            @if ($errors->any())
                <div class="p-4 bg-red-50 border border-red-200 text-red-700 rounded-xl text-sm">
                    <ul class="list-disc list-inside space-y-1">
                        @foreach ($errors->all() as $mensaje)
                            <li>{{ $mensaje }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            @php
                $mensajesDeExito = [
                    'plan-creado' => __('Tu plan diario está listo. Empieza contándonos qué tienes para comer.'),
                    'plan-existente' => __('Ya tenías un plan para hoy: aquí lo tienes.'),
                    'distribucion-generada' => __('Distribución generada.'),
                    'plan-generado' => __('Tu plan se generó correctamente.'),
                    'comida-real-guardada' => __('Tu comida real se registró correctamente.'),
                    'actividad-guardada' => __('Tu actividad se registró correctamente.'),
                    'peso-guardado' => __('Tu peso quedó registrado en este día.'),
                    'dia-cerrado' => __('Tu día quedó cerrado.'),
                    'dia-reabierto' => __('Tu día está abierto de nuevo.'),
                ];
            @endphp

            @if (isset($mensajesDeExito[session('status')]))
                <div class="p-4 bg-green-50 border border-green-200 text-green-700 rounded-xl text-sm">
                    {{ $mensajesDeExito[session('status')] }}
                </div>
            @endif

            {{-- ── Perfil incompleto: nada más tiene sentido sin objetivo calórico ── --}}
            @if ($errorPerfil)
                <div class="p-4 sm:p-8 bg-white shadow-sm rounded-xl">
                    <h3 class="text-base sm:text-lg font-medium text-gray-900">{{ __('Primero, tu objetivo calórico') }}</h3>
                    <p class="mt-2 text-sm text-gray-600">{{ $errorPerfil }}</p>
                    <a href="{{ route('calculadora.edit') }}"
                       class="mt-4 inline-flex w-full sm:w-auto items-center justify-center rounded-lg bg-indigo-600 px-5 py-3 text-sm font-semibold text-white hover:bg-indigo-700 transition">
                        {{ __('Ir a la Calculadora Déficit') }}
                    </a>
                </div>
            @else

            {{-- ── Objetivo del día ── --}}
            <div class="p-4 sm:p-6 bg-white shadow-sm rounded-xl">
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <h3 class="text-base sm:text-lg font-medium text-gray-900">{{ __('Tu objetivo de este día') }}</h3>
                    <span class="text-xl sm:text-2xl font-bold text-indigo-600">
                        {{ $kcal($objetivos['dia']['calorias_objetivo']) }} <span class="text-sm font-medium">kcal</span>
                    </span>
                </div>

                <dl class="mt-4 grid grid-cols-3 gap-2 sm:gap-4 text-center">
                    <div class="rounded-lg bg-gray-50 py-2">
                        <dt class="text-xs text-gray-500">{{ __('Proteína') }}</dt>
                        <dd class="text-sm sm:text-base font-semibold text-gray-900">{{ $gramos($objetivos['dia']['proteina_g']) }} g</dd>
                    </div>
                    <div class="rounded-lg bg-gray-50 py-2">
                        <dt class="text-xs text-gray-500">{{ __('Grasa') }}</dt>
                        <dd class="text-sm sm:text-base font-semibold text-gray-900">{{ $gramos($objetivos['dia']['grasa_g']) }} g</dd>
                    </div>
                    <div class="rounded-lg bg-gray-50 py-2">
                        <dt class="text-xs text-gray-500">{{ __('Carbohidratos') }}</dt>
                        <dd class="text-sm sm:text-base font-semibold text-gray-900">{{ $gramos($objetivos['dia']['carbohidratos_g']) }} g</dd>
                    </div>
                </dl>

                @if ($totalPlanificado > 0)
                    <p class="mt-4 text-sm text-gray-600">
                        {{ __('Planificado hasta ahora:') }}
                        <strong class="text-gray-900">{{ $kcal($totalPlanificado) }} kcal</strong>
                        · {{ $gramos($proteinaPlanificada) }} g {{ __('de proteína') }}
                    </p>
                @endif

                {{-- Peso del día: un dato más de este plan, no una pantalla aparte. --}}
                <form method="post" action="{{ route('planes.peso', $registroDiario) }}"
                      class="mt-4 flex flex-wrap items-end gap-3 border-t border-gray-100 pt-4">
                    @csrf
                    <div class="grow sm:grow-0">
                        <x-input-label for="peso_kg" :value="__('Tu peso hoy (kg)')" />
                        <input id="peso_kg" name="peso_kg" type="number" inputmode="decimal" step="0.01" min="20" max="400"
                               value="{{ old('peso_kg', $registroDiario->peso_kg) }}"
                               placeholder="{{ __('Ej.: 80.4') }}"
                               class="mt-1 block w-full sm:w-40 rounded-lg border-gray-300 text-base shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                    </div>
                    <button type="submit"
                            class="inline-flex min-h-[2.75rem] items-center justify-center rounded-lg border border-gray-300 bg-white px-5 text-sm font-semibold text-gray-700 hover:bg-gray-50 transition">
                        {{ __('Guardar peso') }}
                    </button>
                    <p class="w-full text-xs text-gray-500">
                        {{ __('Alimenta tu promedio móvil de 7 días. No cambia el peso de tu Calculadora Déficit.') }}
                    </p>
                </form>
            </div>

            {{-- ══ 1. Cálculo alimenticio ══ --}}
            <section class="space-y-3 sm:space-y-4">
                <div class="px-1">
                    <h3 class="text-base sm:text-lg font-semibold text-gray-900">{{ __('1. Cálculo alimenticio') }}</h3>
                    <p class="mt-1 text-sm text-gray-600">
                        {{ __('Cuéntanos en un párrafo qué tienes disponible para cada comida y pulsa una sola vez "Generar distribución": la IA reparte el día entero de una vez. Puedes escribirlo o dictarlo.') }}
                    </p>
                    <p class="mt-1 text-sm text-gray-500">
                        {{ __('No hace falta rellenar las tres. Si solo escribes el desayuno y el almuerzo, se reservan las calorías de la cena para cuando la escribas, y lo ya generado no se toca.') }}
                    </p>
                </div>

                <form method="post" action="{{ route('planes.distribucion', $registroDiario) }}" class="space-y-3 sm:space-y-4">
                    @csrf

                    @foreach ($comidas as $comida)
                        @php
                            $plan = $comida['plan'];
                            $comidaReal = $plan?->comidaReal;
                        @endphp

                        <div class="bg-white shadow-sm rounded-xl overflow-hidden">
                            <div class="flex flex-wrap items-center justify-between gap-2 border-b border-gray-100 px-4 py-3 sm:px-6">
                                <div class="flex items-center gap-2">
                                    <h4 class="text-base font-semibold text-gray-900 capitalize">{{ $comida['tipo'] }}</h4>
                                    <span @class([
                                        'inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-medium',
                                        'bg-gray-100 text-gray-600' => $comida['estado'] === 'pendiente',
                                        'bg-amber-100 text-amber-800' => $comida['estado'] === 'planificada',
                                        'bg-green-100 text-green-800' => $comida['estado'] === 'registrada',
                                    ])>{{ __($comida['estado']) }}</span>
                                </div>
                                <span class="text-xs sm:text-sm text-gray-500">
                                    {{ __('Objetivo:') }}
                                    <strong class="text-gray-700">{{ $kcal($comida['objetivos']['calorias']) }} kcal</strong>
                                    ({{ (int) round($comida['porcentaje'] * 100) }}%)
                                    · P {{ $gramos($comida['objetivos']['proteina_g']) }} g
                                </span>
                            </div>

                            <div class="p-4 sm:p-6 space-y-4">
                                @if ($comida['estado'] !== 'registrada')
                                    <label for="ingredientes-{{ $comida['tipo'] }}" class="block text-sm font-medium text-gray-700">
                                        {{ __('¿Qué tienes para el') }} {{ $comida['tipo'] }}?
                                    </label>

                                    <div class="relative">
                                        <textarea id="ingredientes-{{ $comida['tipo'] }}"
                                                  name="ingredientes[{{ $comida['tipo'] }}]"
                                                  rows="3"
                                                  data-dictado
                                                  placeholder="{{ __('Ej.: tengo dos huevos, media palta, pan integral y café sin azúcar') }}"
                                                  class="block w-full rounded-lg border-gray-300 pe-12 text-base shadow-sm focus:border-indigo-500 focus:ring-indigo-500">{{ old('ingredientes.'.$comida['tipo'], $comida['texto']) }}</textarea>

                                        <button type="button"
                                                data-boton-dictado="ingredientes-{{ $comida['tipo'] }}"
                                                hidden
                                                title="{{ __('Dictar por voz') }}"
                                                aria-label="{{ __('Dictar por voz') }}"
                                                class="absolute end-2 top-2 flex h-9 w-9 items-center justify-center rounded-full text-gray-400 hover:bg-gray-100 hover:text-indigo-600 transition">
                                            <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" aria-hidden="true">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 15a3 3 0 0 0 3-3V6a3 3 0 1 0-6 0v6a3 3 0 0 0 3 3Zm7-3a7 7 0 0 1-14 0m7 7v3" />
                                            </svg>
                                        </button>
                                    </div>
                                @endif

                                @if ($plan)
                                    <div class="rounded-lg border border-gray-200 bg-gray-50 p-4">
                                        <p class="text-sm font-medium text-gray-900">{{ $plan->descripcion }}</p>

                                        @if ($plan->preparacion)
                                            <p class="mt-1 text-sm text-gray-600">{{ $plan->preparacion }}</p>
                                        @endif

                                        @if (! empty($plan->ingredientes_detalle))
                                            <ul class="mt-3 divide-y divide-gray-200 text-sm">
                                                @foreach ($plan->ingredientes_detalle as $ingrediente)
                                                    <li class="flex flex-wrap items-baseline justify-between gap-x-3 py-2">
                                                        <span class="text-gray-800">
                                                            {{ $ingrediente['nombre'] }}
                                                            <span class="text-gray-500">
                                                                —
                                                                @if (! empty($ingrediente['porcion']))
                                                                    {{ $ingrediente['porcion'] }}
                                                                    ({{ $kcal($ingrediente['cantidad_g'] ?? 0) }} g)
                                                                @else
                                                                    {{ $kcal($ingrediente['cantidad_g'] ?? 0) }} g
                                                                @endif
                                                            </span>
                                                        </span>
                                                        <span class="text-xs text-gray-500 whitespace-nowrap">
                                                            {{ $kcal($ingrediente['calorias'] ?? 0) }} kcal ·
                                                            P {{ $gramos($ingrediente['proteina_g'] ?? 0) }} g
                                                        </span>
                                                    </li>
                                                @endforeach
                                            </ul>
                                        @endif

                                        <p class="mt-3 border-t border-gray-200 pt-3 text-sm text-gray-700">
                                            <strong>{{ $kcal($plan->calorias_estimadas) }} kcal</strong> ·
                                            P {{ $gramos($plan->proteina_g) }} g ·
                                            G {{ $gramos($plan->grasa_g) }} g ·
                                            C {{ $gramos($plan->carbohidratos_g) }} g
                                        </p>

                                        @if ($plan->notas_ia)
                                            <p class="mt-3 rounded-lg bg-amber-50 p-3 text-xs text-amber-800">{{ $plan->notas_ia }}</p>
                                        @endif
                                    </div>

                                    <div class="flex flex-wrap gap-2">
                                        @if ($comidaReal)
                                            <div class="w-full rounded-lg border border-green-200 bg-green-50 p-4 text-sm">
                                                <p class="font-medium text-green-900">{{ __('Lo que realmente comiste') }}</p>
                                                <p class="mt-1 text-green-800">
                                                    {{ $kcal($comidaReal->calorias_reales) }} kcal ·
                                                    P {{ $gramos($comidaReal->proteina_g) }} g ·
                                                    G {{ $gramos($comidaReal->grasa_g) }} g ·
                                                    C {{ $gramos($comidaReal->carbohidratos_g) }} g
                                                </p>
                                                @if ($comidaReal->notas)
                                                    <p class="mt-1 text-green-700">{{ $comidaReal->notas }}</p>
                                                @endif
                                                @if ($comidaReal->imagenUrl())
                                                    <img src="{{ $comidaReal->imagenUrl() }}" alt="{{ __('Evidencia visual') }}" class="mt-2 max-h-48 rounded-lg">
                                                @endif
                                            </div>
                                        @else
                                            <button type="submit" name="rehacer" value="{{ $comida['tipo'] }}"
                                                    class="inline-flex min-h-[2.75rem] items-center justify-center rounded-lg border border-gray-300 bg-white px-5 text-sm font-semibold text-gray-700 hover:bg-gray-50 transition">
                                                {{ __('Rehacer solo el') }} {{ $comida['tipo'] }}
                                            </button>
                                            <a href="{{ route('comida-real.create', $plan) }}"
                                               class="inline-flex min-h-[2.75rem] items-center justify-center rounded-lg border border-indigo-200 bg-indigo-50 px-5 text-sm font-semibold text-indigo-700 hover:bg-indigo-100 transition">
                                                {{ __('Registrar con detalle') }}
                                            </a>
                                        @endif
                                    </div>
                                @endif
                            </div>
                        </div>
                    @endforeach

                    <div class="bg-white shadow-sm rounded-xl p-4 sm:p-6">
                        <button type="submit"
                                class="w-full sm:w-auto inline-flex items-center justify-center gap-2 rounded-lg bg-indigo-600 px-5 py-3 text-sm font-semibold text-white hover:bg-indigo-700 transition">
                            <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="m5 3 1.5 3.5L10 8 6.5 9.5 5 13l-1.5-3.5L0 8l3.5-1.5L5 3Zm11 2 2 4.5L23 12l-5 2.5L16 19l-2-4.5L9 12l5-2.5L16 5Z" />
                            </svg>
                            {{ __('Generar distribución') }}
                        </button>
                        <p class="mt-2 text-xs text-gray-500">
                            {{ __('Se resuelven solo las comidas nuevas o cuyo texto hayas cambiado. Lo que ya está generado se respeta.') }}
                        </p>
                    </div>
                </form>
            </section>

            {{-- ══ 2. Actividad física ══ --}}
            <section class="space-y-3 sm:space-y-4">
                <h3 class="px-1 text-base sm:text-lg font-semibold text-gray-900">{{ __('2. Actividad física') }}</h3>

                <div class="bg-white shadow-sm rounded-xl p-4 sm:p-6">
                    <div class="flex flex-wrap items-baseline justify-between gap-2">
                        <p class="text-sm text-gray-600">
                            {{ __('Según tu Calculadora Déficit, hoy te conviene quemar') }}
                        </p>
                        <span class="text-xl font-bold text-indigo-600">
                            {{ $kcal($actividad['calorias_objetivo_actividad']) }} <span class="text-sm font-medium">kcal</span>
                        </span>
                    </div>
                    <p class="mt-1 text-xs text-gray-500">
                        {{ __('Equivale al 40% de tu déficit diario de') }} {{ $kcal($actividad['deficit_dieta_kcal']) }} {{ __('kcal. Elige una opción:') }}
                    </p>

                    <ul class="mt-4 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-2">
                        @foreach ($actividad['sugerencias'] as $sugerencia)
                            <li class="flex items-center justify-between gap-2 rounded-lg border border-gray-200 px-3 py-2.5">
                                <span class="text-sm font-medium text-gray-800 capitalize">{{ $sugerencia['tipo'] }}</span>
                                <span class="text-xs text-gray-500 text-end">
                                    {{ $sugerencia['duracion_min'] }} min<br>
                                    ≈ {{ $kcal($sugerencia['calorias_estimadas']) }} kcal
                                </span>
                            </li>
                        @endforeach
                    </ul>

                    @unless (collect($actividad['sugerencias'])->every(fn ($s) => $s['alcanza_objetivo']))
                        <p class="mt-3 text-xs text-gray-500">
                            {{ __('Las opciones de menor intensidad se muestran recortadas a 90 minutos: con esa duración quemas menos de tu objetivo.') }}
                        </p>
                    @endunless
                </div>

                <div class="bg-white shadow-sm rounded-xl p-4 sm:p-6">
                    <h4 class="text-sm font-semibold text-gray-900">{{ __('Reporta lo que hiciste') }}</h4>
                    <p class="mt-1 text-xs text-gray-500">
                        {{ __('Las calorías de tu dispositivo se ajustan automáticamente con el factor de corrección de cada tipo de actividad.') }}
                    </p>

                    <form method="post" action="{{ route('actividades.store', $registroDiario) }}" class="mt-4 space-y-4">
                        @csrf

                        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-3">
                            <div class="lg:col-span-1">
                                <x-input-label for="tipo_actividad" :value="__('Actividad')" />
                                <input id="tipo_actividad" name="tipo_actividad" type="text" list="tipos-de-actividad" required
                                       value="{{ old('tipo_actividad') }}"
                                       class="mt-1 block w-full rounded-lg border-gray-300 text-base shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                <datalist id="tipos-de-actividad">
                                    @foreach ($actividad['sugerencias'] as $sugerencia)
                                        <option value="{{ $sugerencia['tipo'] }}"></option>
                                    @endforeach
                                </datalist>
                            </div>
                            <div>
                                <x-input-label for="duracion_min" :value="__('Duración (min)')" />
                                <input id="duracion_min" name="duracion_min" type="number" inputmode="numeric" min="1" required
                                       value="{{ old('duracion_min') }}"
                                       class="mt-1 block w-full rounded-lg border-gray-300 text-base shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                            </div>
                            <div>
                                <x-input-label for="calorias_dispositivo" :value="__('Calorías quemadas')" />
                                <input id="calorias_dispositivo" name="calorias_dispositivo" type="number" inputmode="decimal" step="0.01" min="0" required
                                       value="{{ old('calorias_dispositivo') }}"
                                       class="mt-1 block w-full rounded-lg border-gray-300 text-base shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                            </div>
                            <div>
                                <x-input-label for="pasos" :value="__('Pasos (opcional)')" />
                                <input id="pasos" name="pasos" type="number" inputmode="numeric" min="0"
                                       value="{{ old('pasos') }}"
                                       class="mt-1 block w-full rounded-lg border-gray-300 text-base shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                            </div>
                            <div>
                                <x-input-label for="fuente" :value="__('Fuente')" />
                                <select id="fuente" name="fuente" required
                                        class="mt-1 block w-full rounded-lg border-gray-300 text-base shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                    <option value="manual" @selected(old('fuente') === 'manual')>{{ __('Manual') }}</option>
                                    <option value="dispositivo" @selected(old('fuente') === 'dispositivo')>{{ __('Dispositivo') }}</option>
                                </select>
                            </div>
                        </div>

                        <button type="submit"
                                class="w-full sm:w-auto inline-flex items-center justify-center rounded-lg bg-gray-800 px-5 py-3 text-sm font-semibold text-white hover:bg-gray-700 transition">
                            {{ __('Guardar actividad') }}
                        </button>
                    </form>

                    @if ($actividades->isNotEmpty())
                        <ul class="mt-5 divide-y divide-gray-100 border-t border-gray-100 pt-2 text-sm">
                            @foreach ($actividades as $registro)
                                <li class="flex flex-wrap items-baseline justify-between gap-x-3 py-2">
                                    <span class="text-gray-800 capitalize">
                                        {{ $registro->tipo }} — {{ $registro->duracion_min }} min
                                        @if ($registro->pasos)
                                            · {{ $kcal($registro->pasos) }} {{ __('pasos') }}
                                        @endif
                                    </span>
                                    <span class="text-xs text-gray-500">
                                        {{ $kcal($registro->calorias_dispositivo) }} × {{ $registro->factor_correccion }}
                                        = <strong class="text-gray-700">{{ $kcal($registro->calorias_ajustadas) }} kcal</strong>
                                    </span>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            </section>

            {{-- ══ 3. Cierre del día ══ --}}
            <section class="space-y-3 sm:space-y-4">
                <h3 class="px-1 text-base sm:text-lg font-semibold text-gray-900">{{ __('3. Cierre del día') }}</h3>

                <div class="bg-white shadow-sm rounded-xl p-4 sm:p-6">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <h4 class="text-sm font-semibold text-gray-900">
                                {{ $registroDiario->cerrado ? __('Resumen del cierre') : __('Vista previa (todavía sin cerrar)') }}
                            </h4>
                            <p class="mt-1 text-xs text-gray-500">
                                @if ($registroDiario->cerrado)
                                    {{ __('Cerrado el') }} {{ $registroDiario->cerrado_en?->format('d/m/Y H:i') }}.
                                    {{ __('Reábrelo para poder corregir comidas o actividad.') }}
                                @else
                                    {{ __('Al cerrar el día se congelan sus cifras y se actualizan tus indicadores y tu progreso.') }}
                                @endif
                            </p>
                        </div>

                        @if ($registroDiario->cerrado)
                            <form method="post" action="{{ route('cierre.reabrir', $registroDiario) }}">
                                @csrf
                                <button type="submit"
                                        class="inline-flex items-center justify-center rounded-lg border border-gray-300 bg-white px-5 py-3 text-sm font-semibold text-gray-700 hover:bg-gray-50 transition">
                                    {{ __('Reabrir mi día') }}
                                </button>
                            </form>
                        @endif
                    </div>

                    <dl class="mt-5 grid grid-cols-2 lg:grid-cols-4 gap-3">
                        <div class="rounded-lg bg-gray-50 p-3">
                            <dt class="text-xs text-gray-500">{{ __('Consumidas / objetivo') }}</dt>
                            <dd class="mt-0.5 text-sm font-semibold text-gray-900">
                                {{ $kcal($resumenCierre['calorias_consumidas']) }} / {{ $kcal($resumenCierre['calorias_objetivo']) }} kcal
                            </dd>
                        </div>
                        <div class="rounded-lg bg-gray-50 p-3">
                            <dt class="text-xs text-gray-500">{{ __('Gasto por actividad') }}</dt>
                            <dd class="mt-0.5 text-sm font-semibold text-gray-900">
                                {{ $kcal($resumenCierre['calorias_actividad_ajustada']) }} kcal
                            </dd>
                        </div>
                        <div class="rounded-lg bg-gray-50 p-3">
                            <dt class="text-xs text-gray-500">{{ __('Déficit estimado') }}</dt>
                            <dd class="mt-0.5 text-sm font-semibold {{ $resumenCierre['deficit_diario'] >= 0 ? 'text-green-700' : 'text-red-700' }}">
                                {{ $kcal($resumenCierre['deficit_diario']) }} kcal
                            </dd>
                        </div>
                        <div class="rounded-lg bg-gray-50 p-3">
                            <dt class="text-xs text-gray-500">{{ __('Proteína cumplida') }}</dt>
                            <dd class="mt-0.5 text-sm font-semibold text-gray-900">
                                {{ number_format($resumenCierre['cumplimiento_proteina_pct'], 1, ',', '.') }}%
                            </dd>
                        </div>
                    </dl>

                    @unless ($registroDiario->cerrado)
                        {{-- Feedback de cumplimiento: la única entrada del cierre. --}}
                        <form method="post" action="{{ route('cierre.cerrar', $registroDiario) }}" class="mt-5 border-t border-gray-100 pt-4 space-y-4">
                            @csrf

                            @if ($comidasPorConfirmar->isNotEmpty())
                                <div>
                                    <h4 class="text-sm font-semibold text-gray-900">{{ __('¿Cumpliste con lo sugerido?') }}</h4>
                                    <p class="mt-1 text-xs text-gray-500">
                                        {{ __('Marca la casilla si comiste lo planificado, o cuéntanos qué comiste de verdad y lo interpretamos por ti.') }}
                                    </p>
                                </div>

                                @foreach ($comidasPorConfirmar as $comida)
                                    <div class="rounded-lg border border-gray-200 p-3 sm:p-4">
                                        <div class="flex flex-wrap items-center justify-between gap-2">
                                            <p class="text-sm font-semibold text-gray-900 capitalize">{{ $comida['tipo'] }}</p>
                                            <span class="text-xs text-gray-500">
                                                {{ __('Sugerido:') }} {{ $kcal($comida['plan']->calorias_estimadas) }} kcal
                                            </span>
                                        </div>

                                        <label class="mt-2 flex items-center gap-2 text-sm text-gray-700">
                                            <input type="checkbox" name="feedback[{{ $comida['tipo'] }}][cumplio]" value="1"
                                                   class="h-5 w-5 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
                                            {{ __('Sí, comí lo que se sugirió') }}
                                        </label>

                                        <div class="relative mt-2">
                                            <textarea id="feedback-{{ $comida['tipo'] }}"
                                                      name="feedback[{{ $comida['tipo'] }}][texto]"
                                                      rows="2"
                                                      data-dictado
                                                      placeholder="{{ __('O cuéntanos qué comiste: “al final me comí un sándwich de pollo y una gaseosa”') }}"
                                                      class="block w-full rounded-lg border-gray-300 pe-12 text-base shadow-sm focus:border-indigo-500 focus:ring-indigo-500">{{ old('feedback.'.$comida['tipo'].'.texto') }}</textarea>

                                            <button type="button"
                                                    data-boton-dictado="feedback-{{ $comida['tipo'] }}"
                                                    hidden
                                                    title="{{ __('Dictar por voz') }}"
                                                    aria-label="{{ __('Dictar por voz') }}"
                                                    class="absolute end-2 top-2 flex h-9 w-9 items-center justify-center rounded-full text-gray-400 hover:bg-gray-100 hover:text-indigo-600 transition">
                                                <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" aria-hidden="true">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 15a3 3 0 0 0 3-3V6a3 3 0 1 0-6 0v6a3 3 0 0 0 3 3Zm7-3a7 7 0 0 1-14 0m7 7v3" />
                                                </svg>
                                            </button>
                                        </div>
                                    </div>
                                @endforeach
                            @endif

                            <button type="submit"
                                    class="w-full sm:w-auto inline-flex items-center justify-center rounded-lg bg-green-600 px-5 py-3 text-sm font-semibold text-white hover:bg-green-700 transition">
                                {{ __('Cerrar mi día') }}
                            </button>
                        </form>
                    @endunless

                    <div class="mt-5 border-t border-gray-100 pt-4">
                        <h4 class="text-sm font-semibold text-gray-900">{{ __('Recomendaciones') }}</h4>

                        @if ($resumenCierre['recomendaciones']->isEmpty())
                            <p class="mt-1 text-xs text-gray-500">
                                {{ __('Sin recomendaciones para este día. Los ajustes se basan en promedios móviles de 7 días y siempre requieren tu confirmación.') }}
                            </p>
                        @else
                            <ul class="mt-2 divide-y divide-gray-100 text-sm">
                                @foreach ($resumenCierre['recomendaciones'] as $recomendacion)
                                    <li class="py-3">
                                        <p class="text-gray-800">{{ $recomendacion->justificacion }}</p>
                                        <p class="mt-0.5 text-xs text-gray-500">
                                            {{ __($recomendacion->estado) }}
                                            @if ($recomendacion->calorias_objetivo_sugeridas)
                                                · {{ $kcal($recomendacion->calorias_objetivo_sugeridas) }} kcal
                                            @endif
                                        </p>

                                        @if ($recomendacion->estado === 'pendiente')
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
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                </div>
            </section>

            @endif
        </div>
    </div>

    @push('scripts')
        <script>
            // Dictado por voz con la Web Speech API del navegador: sin ninguna
            // dependencia nueva y sin enviar audio a ningún servicio propio.
            // Si el navegador no la soporta, los botones quedan ocultos y el
            // usuario escribe a mano (CLAUDE.md sección 4.12).
            document.addEventListener('DOMContentLoaded', function () {
                var Reconocimiento = window.SpeechRecognition || window.webkitSpeechRecognition;

                if (! Reconocimiento) {
                    return;
                }

                document.querySelectorAll('[data-boton-dictado]').forEach(function (boton) {
                    var campo = document.getElementById(boton.dataset.botonDictado);

                    if (! campo) {
                        return;
                    }

                    boton.hidden = false;

                    var reconocimiento = new Reconocimiento();
                    reconocimiento.lang = 'es-ES';
                    reconocimiento.interimResults = false;
                    reconocimiento.continuous = false;

                    var escuchando = false;

                    boton.addEventListener('click', function () {
                        if (escuchando) {
                            reconocimiento.stop();
                            return;
                        }

                        try {
                            reconocimiento.start();
                        } catch (error) {
                            return;
                        }

                        escuchando = true;
                        boton.classList.add('text-red-600', 'animate-pulse');
                    });

                    reconocimiento.addEventListener('result', function (evento) {
                        var texto = evento.results[0][0].transcript;
                        campo.value = campo.value ? campo.value.trim() + ' ' + texto : texto;
                        campo.dispatchEvent(new Event('input'));
                    });

                    ['end', 'error'].forEach(function (evento) {
                        reconocimiento.addEventListener(evento, function () {
                            escuchando = false;
                            boton.classList.remove('text-red-600', 'animate-pulse');
                        });
                    });
                });
            });
        </script>
    @endpush
</x-app-layout>
