@php
    // Los parámetros se muestran agrupados por el `grupo` que declara el
    // catálogo, que es también la única fuente de qué existe (sección 4.27).
    // preserveKeys: la clave del catálogo es lo que identifica al parámetro en
    // el formulario y en `$valores`; sin esto groupBy reindexa a 0,1,2…
    $porGrupo = collect($catalogo)->groupBy('grupo', preserveKeys: true);
@endphp

<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between gap-3">
            <div class="flex min-w-0 items-center gap-3">
                <a href="{{ route('admin.inicio') }}"
                   class="grid h-11 w-11 flex-none place-items-center rounded-full text-tudi-muted no-underline hover:bg-tudi-surface"
                   aria-label="{{ __('Volver a Administración') }}">
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7" />
                    </svg>
                </a>
                <h1 class="truncate text-lg font-semibold tracking-tudi-title sm:text-2xl">{{ __('Parámetros maestros') }}</h1>
            </div>
        </div>
    </x-slot>

    <x-tudi.flash :mensajes="[
        'parametros-guardados' => __('Parámetros guardados.'),
        'parametros-restablecidos' => __('Parámetros devueltos a sus valores de fábrica.'),
    ]" />

    <form method="post" action="{{ route('admin.parametros.update') }}" class="mx-auto max-w-2xl space-y-5">
        @csrf
        @method('put')

        <div class="tudi-panel on-dark">
            <p class="tudi-label">{{ __('Qué se ajusta aquí') }}</p>
            <p class="mt-2 text-sm leading-snug text-tudi-on-dark-2">
                {{ __('Los umbrales con los que el sistema decide si sugiere subir o bajar el objetivo calórico de un usuario, y cuánta actividad le propone cada día. Los cambios afectan a los cierres a partir de ahora; los ya calculados no se rehacen.') }}
            </p>
        </div>

        @foreach ($porGrupo as $grupo => $parametros)
            <section class="space-y-3">
                <p class="tudi-label px-1">{{ $grupo }}</p>

                <div class="tudi-card divide-y divide-tudi-divider">
                    @foreach ($parametros as $clave => $definicion)
                        <div class="p-5">
                            <label for="{{ $clave }}" class="block text-[15px] font-semibold tracking-tudi-title">
                                {{ $definicion['etiqueta'] }}
                            </label>
                            <p class="mt-1 text-[13px] leading-snug text-tudi-ink-3">{{ $definicion['ayuda'] }}</p>

                            <div class="mt-3 flex items-center gap-3">
                                {{-- Decimal: inputmode, no type=number (sección 4.24). --}}
                                <input id="{{ $clave }}"
                                       name="parametros[{{ $clave }}]"
                                       type="text"
                                       inputmode="decimal"
                                       autocomplete="off"
                                       required
                                       value="{{ old('parametros.'.$clave, $valores[$clave]) }}"
                                       class="tudi-input tudi-num max-w-[140px] text-center text-[20px]">

                                <span class="tudi-meta">{{ $definicion['unidad'] }}</span>

                                <span class="tudi-meta ms-auto text-end">
                                    {{ __('de fábrica') }} {{ $definicion['defecto'] }}<br>
                                    {{ __('entre') }} {{ $definicion['min'] }} {{ __('y') }} {{ $definicion['max'] }}
                                </span>
                            </div>

                            <x-input-error :messages="$errors->get('parametros.'.$clave)" class="mt-2" />
                        </div>
                    @endforeach
                </div>
            </section>
        @endforeach

        <div class="flex flex-wrap gap-2">
            <button type="submit" class="tudi-btn tudi-btn-primary flex-1">{{ __('Guardar parámetros') }}</button>
        </div>
    </form>

    <form method="post" action="{{ route('admin.parametros.restablecer') }}"
          class="mx-auto mt-3 max-w-2xl" x-data="{ confirmando: false }">
        @csrf
        <button type="button" x-show="! confirmando" x-on:click="confirmando = true"
                class="tudi-btn tudi-btn-ghost tudi-btn-block">
            {{ __('Devolver todo a los valores de fábrica') }}
        </button>
        <div x-show="confirmando" style="display: none" class="flex gap-2">
            <button type="submit" class="tudi-btn bg-tudi-amber text-tudi-ink flex-1">
                {{ __('Sí, restablecer') }}
            </button>
            <button type="button" x-on:click="confirmando = false" class="tudi-btn tudi-btn-ghost">
                {{ __('Cancelar') }}
            </button>
        </div>
    </form>
</x-app-layout>
