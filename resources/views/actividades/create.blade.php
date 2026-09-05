<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('Actividad física de hoy') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg">
                <header>
                    <h2 class="text-lg font-medium text-gray-900">
                        {{ __('Registrar actividad') }}
                    </h2>
                    <p class="mt-1 text-sm text-gray-600">
                        {{ __('Las calorías del dispositivo se ajustan automáticamente con el factor de corrección de su tipo de actividad.') }}
                    </p>
                </header>

                <form method="post" action="{{ route('actividades.store') }}" class="mt-6 space-y-6">
                    @csrf

                    <div class="grid grid-cols-1 sm:grid-cols-5 gap-4">
                        <div>
                            <x-input-label for="tipo_actividad" :value="__('Tipo de actividad')" />
                            <x-text-input id="tipo_actividad" name="tipo_actividad" type="text" class="mt-1 block w-full" :value="old('tipo_actividad')" required />
                            <x-input-error class="mt-2" :messages="$errors->get('tipo_actividad')" />
                        </div>
                        <div>
                            <x-input-label for="duracion_min" :value="__('Duración (min)')" />
                            <x-text-input id="duracion_min" name="duracion_min" type="number" min="1" class="mt-1 block w-full" :value="old('duracion_min')" required />
                            <x-input-error class="mt-2" :messages="$errors->get('duracion_min')" />
                        </div>
                        <div>
                            <x-input-label for="calorias_dispositivo" :value="__('Calorías (dispositivo)')" />
                            <x-text-input id="calorias_dispositivo" name="calorias_dispositivo" type="number" step="0.01" min="0" class="mt-1 block w-full" :value="old('calorias_dispositivo')" required />
                            <x-input-error class="mt-2" :messages="$errors->get('calorias_dispositivo')" />
                        </div>
                        <div>
                            <x-input-label for="pasos" :value="__('Pasos')" />
                            <x-text-input id="pasos" name="pasos" type="number" min="0" class="mt-1 block w-full" :value="old('pasos')" />
                            <x-input-error class="mt-2" :messages="$errors->get('pasos')" />
                        </div>
                        <div>
                            <x-input-label for="fuente" :value="__('Fuente')" />
                            <select id="fuente" name="fuente" class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm" required>
                                <option value="manual" @selected(old('fuente') === 'manual')>{{ __('Manual') }}</option>
                                <option value="dispositivo" @selected(old('fuente') === 'dispositivo')>{{ __('Dispositivo') }}</option>
                            </select>
                            <x-input-error class="mt-2" :messages="$errors->get('fuente')" />
                        </div>
                    </div>

                    <div class="flex items-center gap-4">
                        <x-primary-button>{{ __('Guardar') }}</x-primary-button>

                        @if (session('status') === 'actividad-guardada')
                            <p class="text-sm text-gray-600">{{ __('Guardado.') }}</p>
                        @endif
                    </div>
                </form>
            </div>

            @if ($actividades->isNotEmpty())
                <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg">
                    <h2 class="text-lg font-medium text-gray-900">{{ __('Actividades de hoy') }}</h2>
                    <ul class="mt-4 divide-y">
                        @foreach ($actividades as $actividad)
                            <li class="py-2 flex items-center justify-between">
                                <span>
                                    {{ $actividad->tipo }} — {{ $actividad->duracion_min }} min
                                    @if ($actividad->pasos)
                                        — {{ $actividad->pasos }} {{ __('pasos') }}
                                    @endif
                                </span>
                                <span class="text-sm text-gray-600">
                                    {{ $actividad->calorias_dispositivo }} kcal × {{ $actividad->factor_correccion }}
                                    = <strong>{{ $actividad->calorias_ajustadas }} kcal</strong>
                                </span>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </div>
    </div>
</x-app-layout>
