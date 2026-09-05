<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('Registrar comida real') }} — <span class="capitalize">{{ $planComida->tipo_comida }}</span>
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-2xl mx-auto sm:px-6 lg:px-8 space-y-6">
            <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg">
                <p class="text-sm text-gray-600 mb-6">
                    {{ __('Planificado:') }}
                    {{ $planComida->calorias_estimadas }} kcal ·
                    P {{ $planComida->proteina_g }} g ·
                    G {{ $planComida->grasa_g }} g ·
                    C {{ $planComida->carbohidratos_g }} g
                </p>

                <form method="post" action="{{ route('comida-real.store', $planComida) }}" enctype="multipart/form-data" class="space-y-4">
                    @csrf

                    <div>
                        <x-input-label for="calorias_reales" :value="__('Calorías reales')" />
                        <x-text-input id="calorias_reales" name="calorias_reales" type="number" step="0.01" min="0" class="mt-1 block w-full" :value="old('calorias_reales')" required />
                        <x-input-error :messages="$errors->get('calorias_reales')" class="mt-2" />
                    </div>

                    <div>
                        <x-input-label for="proteina_g" :value="__('Proteína real (g)')" />
                        <x-text-input id="proteina_g" name="proteina_g" type="number" step="0.01" min="0" class="mt-1 block w-full" :value="old('proteina_g')" required />
                        <x-input-error :messages="$errors->get('proteina_g')" class="mt-2" />
                    </div>

                    <div>
                        <x-input-label for="grasa_g" :value="__('Grasa real (g)')" />
                        <x-text-input id="grasa_g" name="grasa_g" type="number" step="0.01" min="0" class="mt-1 block w-full" :value="old('grasa_g')" required />
                        <x-input-error :messages="$errors->get('grasa_g')" class="mt-2" />
                    </div>

                    <div>
                        <x-input-label for="carbohidratos_g" :value="__('Carbohidratos reales (g)')" />
                        <x-text-input id="carbohidratos_g" name="carbohidratos_g" type="number" step="0.01" min="0" class="mt-1 block w-full" :value="old('carbohidratos_g')" required />
                        <x-input-error :messages="$errors->get('carbohidratos_g')" class="mt-2" />
                    </div>

                    <div>
                        <x-input-label for="imagen" :value="__('Foto (evidencia visual)')" />
                        <input id="imagen" name="imagen" type="file" accept="image/*" class="mt-1 block w-full text-sm text-gray-600" />
                        <x-input-error :messages="$errors->get('imagen')" class="mt-2" />
                    </div>

                    <div>
                        <x-input-label for="notas" :value="__('Notas / ajustes (opcional)')" />
                        <textarea id="notas" name="notas" rows="3" class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm">{{ old('notas') }}</textarea>
                        <x-input-error :messages="$errors->get('notas')" class="mt-2" />
                    </div>

                    <div class="flex justify-end">
                        <x-primary-button>{{ __('Guardar comida real') }}</x-primary-button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</x-app-layout>
