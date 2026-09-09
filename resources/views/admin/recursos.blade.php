{{--
    Material de apoyo del usuario (CLAUDE.md sección 5.15): el video y la guía
    en PDF que se muestran en la Calculadora Déficit. Qué recursos existen lo
    declara el catálogo de RecursosDidacticosService, no esta vista.
--}}
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
                <h1 class="truncate text-lg font-semibold tracking-tudi-title sm:text-2xl">{{ __('Material de apoyo') }}</h1>
            </div>
        </div>
    </x-slot>

    <x-tudi.flash :mensajes="[
        'recursos-guardados' => __('Material actualizado.'),
        'recurso-retirado' => __('Recurso retirado.'),
    ]" />

    <div class="mx-auto max-w-2xl space-y-5">
        <div class="tudi-panel on-dark">
            <p class="tudi-label">{{ __('Qué se publica aquí') }}</p>
            <p class="mt-2 text-sm leading-snug text-tudi-on-dark-2">
                {{ __('Lo que el usuario ve junto a "Tu objetivo diario" en la Calculadora Déficit. Si no publicas nada, esa pantalla se ve exactamente igual que ahora.') }}
            </p>
        </div>

        <form method="post" action="{{ route('admin.recursos.update') }}" enctype="multipart/form-data"
              class="space-y-5">
            @csrf

            {{-- ── Video ── --}}
            <section class="space-y-3">
                <p class="tudi-label px-1">{{ $catalogo['calculadora_video_url']['grupo'] }}</p>

                <div class="tudi-card p-5">
                    <label for="calculadora_video_url" class="block text-[15px] font-semibold tracking-tudi-title">
                        {{ $catalogo['calculadora_video_url']['etiqueta'] }}
                    </label>
                    <p class="mt-1 text-[13px] leading-snug text-tudi-ink-3">
                        {{ $catalogo['calculadora_video_url']['ayuda'] }}
                    </p>

                    <input id="calculadora_video_url"
                           name="calculadora_video_url"
                           type="url"
                           inputmode="url"
                           autocomplete="off"
                           placeholder="https://www.youtube.com/watch?v=…"
                           value="{{ old('calculadora_video_url', $recursos['calculadora_video_url']['valor'] ?? '') }}"
                           class="tudi-input mt-3">

                    <x-input-error :messages="$errors->get('calculadora_video_url')" class="mt-2" />

                    @if (($recursos['calculadora_video_url'] ?? null) && ! $videoIncrustado)
                        <p class="tudi-note mt-3">
                            {{ __('El enlace guardado no es de YouTube ni de Vimeo, así que no se puede incrustar y el usuario no verá ningún video.') }}
                        </p>
                    @endif

                    @if ($videoIncrustado)
                        <div class="mt-4 aspect-video w-full overflow-hidden rounded-tudi-sm bg-tudi-dark">
                            <iframe src="{{ $videoIncrustado }}"
                                    title="{{ __('Vista previa del video') }}"
                                    class="h-full w-full"
                                    loading="lazy"
                                    referrerpolicy="strict-origin-when-cross-origin"
                                    allowfullscreen></iframe>
                        </div>
                    @endif
                </div>
            </section>

            {{-- ── Guía en PDF ── --}}
            <section class="space-y-3">
                <p class="tudi-label px-1">{{ __('Guía descargable') }}</p>

                <div class="tudi-card p-5">
                    <label for="calculadora_guia_pdf" class="block text-[15px] font-semibold tracking-tudi-title">
                        {{ $catalogo['calculadora_guia_pdf']['etiqueta'] }}
                    </label>
                    <p class="mt-1 text-[13px] leading-snug text-tudi-ink-3">
                        {{ $catalogo['calculadora_guia_pdf']['ayuda'] }}
                    </p>

                    @if ($recursos['calculadora_guia_pdf'] ?? null)
                        <div class="mt-3 flex flex-wrap items-center justify-between gap-3 rounded-tudi-sm bg-tudi-card-inset p-3">
                            <a href="{{ $recursos['calculadora_guia_pdf']['url'] }}" target="_blank" rel="noopener"
                               class="tudi-link min-w-0 truncate text-sm">
                                {{ $recursos['calculadora_guia_pdf']['nombre_original'] ?? __('Guía publicada') }}
                            </a>
                            <span class="tudi-meta">{{ __('publicada') }}</span>
                        </div>
                    @endif

                    <label for="calculadora_guia_pdf"
                           class="mt-3 flex min-h-[48px] cursor-pointer items-center gap-2.5 rounded-tudi-sm border border-dashed border-tudi-input-border px-4 text-sm text-tudi-ink-2"
                           x-data="{ archivo: '' }">
                        <input id="calculadora_guia_pdf" name="calculadora_guia_pdf" type="file" accept="application/pdf"
                               class="sr-only" x-on:change="archivo = $event.target.files[0]?.name || ''">
                        <svg class="h-5 w-5 flex-none text-tudi-muted" fill="none" stroke="currentColor" stroke-width="1.7" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 16V6m0 0 4 4m-4-4-4 4M5 19h14" />
                        </svg>
                        <span class="truncate"
                              x-text="archivo || '{{ ($recursos['calculadora_guia_pdf'] ?? null) ? __('Reemplazar el PDF') : __('Elegir un PDF') }}'">
                            {{ ($recursos['calculadora_guia_pdf'] ?? null) ? __('Reemplazar el PDF') : __('Elegir un PDF') }}
                        </span>
                    </label>

                    <x-input-error :messages="$errors->get('calculadora_guia_pdf')" class="mt-2" />
                </div>
            </section>

            <button type="submit" class="tudi-btn tudi-btn-primary tudi-btn-block">
                {{ __('Guardar material') }}
            </button>
        </form>

        {{-- Retirar: fuera del formulario de guardar, porque son envíos
             distintos y un <form> no puede anidarse dentro de otro. --}}
        @foreach ($catalogo as $clave => $definicion)
            @if ($recursos[$clave] ?? null)
                <div x-data="{ confirmando: false }">
                    <button type="button" x-show="! confirmando" x-on:click="confirmando = true"
                            class="tudi-btn tudi-btn-ghost text-tudi-amber-ink">
                        {{ __('Retirar') }} · {{ $definicion['etiqueta'] }}
                    </button>

                    <form method="post" action="{{ route('admin.recursos.destroy', $clave) }}"
                          x-show="confirmando" style="display: none" class="flex flex-wrap gap-2">
                        @csrf
                        @method('delete')
                        <button type="submit" class="tudi-btn bg-tudi-amber text-tudi-ink">
                            {{ __('Sí, retirar') }} · {{ $definicion['etiqueta'] }}
                        </button>
                        <button type="button" x-on:click="confirmando = false" class="tudi-btn tudi-btn-ghost">
                            {{ __('Cancelar') }}
                        </button>
                    </form>
                </div>
            @endif
        @endforeach
    </div>
</x-app-layout>
