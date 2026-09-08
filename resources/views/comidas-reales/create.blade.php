@php
    $gramos = fn ($valor) => number_format((float) $valor, 1, ',', '.');
@endphp

<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-3">
            <a href="{{ route('planes.show', $planComida->registroDiario) }}"
               class="grid h-11 w-11 flex-none place-items-center rounded-full text-tudi-muted no-underline hover:bg-tudi-surface"
               aria-label="{{ __('Volver al plan del día') }}">
                <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7" />
                </svg>
            </a>
            <h1 class="text-lg font-semibold capitalize tracking-tudi-title sm:text-2xl">
                {{ $planComida->tipo_comida }}
            </h1>
        </div>
    </x-slot>

    <x-tudi.flash />

    <div class="mx-auto max-w-2xl space-y-4">
        <div class="tudi-card p-5">
            <p class="tudi-label">{{ __('Planificado') }}</p>
            <div class="mt-2 flex flex-wrap items-center gap-2">
                <span class="tudi-chip tudi-chip-solid">{{ number_format((float) $planComida->calorias_estimadas, 0, ',', '.') }} kcal</span>
                <span class="tudi-chip">P {{ $gramos($planComida->proteina_g) }}</span>
                <span class="tudi-chip">G {{ $gramos($planComida->grasa_g) }}</span>
                <span class="tudi-chip">C {{ $gramos($planComida->carbohidratos_g) }}</span>
            </div>
        </div>

        <form method="post" action="{{ route('comida-real.store', $planComida) }}" enctype="multipart/form-data"
              class="tudi-card space-y-4 p-5">
            @csrf

            <div class="grid gap-3 sm:grid-cols-2">
                <div>
                    <x-input-label for="calorias_reales" :value="__('Calorías')" />
                    <x-text-input id="calorias_reales" name="calorias_reales" type="number" step="0.01" min="0"
                                  class="mt-1.5" :value="old('calorias_reales', round((float) $planComida->calorias_estimadas))" required />
                    <x-input-error :messages="$errors->get('calorias_reales')" class="mt-2" />
                </div>

                <div>
                    <x-input-label for="proteina_g" :value="__('Proteína (g)')" />
                    <x-text-input id="proteina_g" name="proteina_g" type="number" step="0.01" min="0"
                                  class="mt-1.5" :value="old('proteina_g', round((float) $planComida->proteina_g, 1))" required />
                    <x-input-error :messages="$errors->get('proteina_g')" class="mt-2" />
                </div>

                <div>
                    <x-input-label for="grasa_g" :value="__('Grasa (g)')" />
                    <x-text-input id="grasa_g" name="grasa_g" type="number" step="0.01" min="0"
                                  class="mt-1.5" :value="old('grasa_g', round((float) $planComida->grasa_g, 1))" required />
                    <x-input-error :messages="$errors->get('grasa_g')" class="mt-2" />
                </div>

                <div>
                    <x-input-label for="carbohidratos_g" :value="__('Carbohidratos (g)')" />
                    <x-text-input id="carbohidratos_g" name="carbohidratos_g" type="number" step="0.01" min="0"
                                  class="mt-1.5" :value="old('carbohidratos_g', round((float) $planComida->carbohidratos_g, 1))" required />
                    <x-input-error :messages="$errors->get('carbohidratos_g')" class="mt-2" />
                </div>
            </div>

            <div>
                <x-input-label for="notas" :value="__('Notas')" />
                <textarea id="notas" name="notas" rows="2" class="tudi-input mt-1.5"
                          placeholder="{{ __('cambié el arroz por quinoa') }}">{{ old('notas') }}</textarea>
                <x-input-error :messages="$errors->get('notas')" class="mt-2" />
            </div>

            <div>
                <x-input-label for="imagen" :value="__('Foto')" />
                <input id="imagen" name="imagen" type="file" accept="image/*"
                       class="mt-1.5 block w-full text-sm text-tudi-ink-3 file:me-3 file:min-h-[44px] file:rounded-tudi-pill file:border-0 file:bg-tudi-surface file:px-4 file:text-sm file:font-semibold file:text-tudi-ink">
                <x-input-error :messages="$errors->get('imagen')" class="mt-2" />
            </div>

            <x-primary-button class="tudi-btn-block">{{ __('Registrar') }}</x-primary-button>
        </form>
    </div>
</x-app-layout>
