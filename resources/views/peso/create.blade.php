<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('Peso de hoy') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8 space-y-6">
            <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg">
                <header>
                    <h2 class="text-lg font-medium text-gray-900">
                        {{ __('Registrar peso') }}
                    </h2>
                    <p class="mt-1 text-sm text-gray-600">
                        {{ __('Se usa para calcular tu promedio móvil de 7 días en "Mi progreso" y para las recomendaciones automáticas. No cambia tu peso de perfil.') }}
                    </p>
                </header>

                <form method="post" action="{{ route('peso.store') }}" class="mt-6 space-y-6">
                    @csrf

                    <div class="sm:w-1/2">
                        <x-input-label for="peso_kg" :value="__('Peso (kg)')" />
                        <x-text-input id="peso_kg" name="peso_kg" type="number" step="0.01" min="20" max="400" class="mt-1 block w-full" :value="old('peso_kg', $pesoHoy)" required />
                        <x-input-error class="mt-2" :messages="$errors->get('peso_kg')" />
                    </div>

                    <div class="flex items-center gap-4">
                        <x-primary-button>{{ __('Guardar') }}</x-primary-button>

                        @if (session('status') === 'peso-guardado')
                            <p class="text-sm text-gray-600">{{ __('Guardado.') }}</p>
                        @endif
                    </div>
                </form>
            </div>
        </div>
    </div>
</x-app-layout>
