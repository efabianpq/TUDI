<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-lg sm:text-xl text-gray-800 leading-tight">
            {{ __('Calculadora Déficit') }}
        </h2>
    </x-slot>

    <div class="py-6 sm:py-12">
        <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 space-y-4 sm:space-y-6">

            @if (session('error'))
                <div class="p-4 bg-red-50 border border-red-200 text-red-700 rounded-xl text-sm">
                    {{ session('error') }}
                </div>
            @endif

            {{-- El resultado de la calculadora: la cifra que rige todo lo demás. --}}
            <div class="p-5 sm:p-6 bg-indigo-600 text-white rounded-xl">
                <p class="text-sm font-medium text-indigo-100">{{ __('Tu objetivo calórico diario') }}</p>
                @if ($user->calorias_objetivo !== null)
                    <p class="mt-1 text-3xl sm:text-4xl font-bold">
                        {{ number_format((float) $user->calorias_objetivo, 0, ',', '.') }}
                        <span class="text-lg font-medium">kcal</span>
                    </p>
                    <p class="mt-2 text-sm text-indigo-100">
                        {{ __('Es lo que debes consumir cada día. Se recalcula al guardar estos datos.') }}
                    </p>
                @else
                    <p class="mt-1 text-lg font-semibold">{{ __('Todavía sin calcular') }}</p>
                    <p class="mt-2 text-sm text-indigo-100">
                        {{ __('Completa los datos de abajo y guárdalos para obtener tu objetivo.') }}
                    </p>
                @endif
            </div>

            <div class="p-4 sm:p-8 bg-white shadow-sm rounded-xl">
                <div class="max-w-xl">
                    <section>
                        <header>
                            <h2 class="text-lg font-medium text-gray-900">
                                {{ __('Tus datos') }}
                            </h2>

                            <p class="mt-1 text-sm text-gray-600">
                                {{ __('Con estos datos se calcula cuántas calorías debes consumir al día y cómo se reparten entre desayuno, almuerzo y cena.') }}
                            </p>
                        </header>

                        <form method="post" action="{{ route('calculadora.update') }}" class="mt-6 space-y-6">
                            @csrf
                            @method('put')

                            <div>
                                <x-input-label for="peso_kg" :value="__('Peso (kg)')" />
                                <x-text-input id="peso_kg" name="peso_kg" type="number" step="0.01" class="mt-1 block w-full" :value="old('peso_kg', $user->peso_kg)" required />
                                <x-input-error class="mt-2" :messages="$errors->get('peso_kg')" />
                            </div>

                            <div>
                                <x-input-label for="estatura_m" :value="__('Estatura (m)')" />
                                <x-text-input id="estatura_m" name="estatura_m" type="number" step="0.01" class="mt-1 block w-full" :value="old('estatura_m', $user->estatura_m)" required />
                                <x-input-error class="mt-2" :messages="$errors->get('estatura_m')" />
                            </div>

                            <div>
                                <x-input-label for="edad" :value="__('Edad')" />
                                <x-text-input id="edad" name="edad" type="number" step="1" class="mt-1 block w-full" :value="old('edad', $user->edad)" required />
                                <x-input-error class="mt-2" :messages="$errors->get('edad')" />
                            </div>

                            <div>
                                <x-input-label for="sexo" :value="__('Sexo')" />
                                <select id="sexo" name="sexo" required class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-lg shadow-sm px-3 py-2.5 text-base sm:text-sm">
                                    <option value="">{{ __('Selecciona una opción') }}</option>
                                    <option value="masculino" @selected(old('sexo', $user->sexo) === 'masculino')>{{ __('Masculino') }}</option>
                                    <option value="femenino" @selected(old('sexo', $user->sexo) === 'femenino')>{{ __('Femenino') }}</option>
                                </select>
                                <x-input-error class="mt-2" :messages="$errors->get('sexo')" />
                            </div>

                            <div>
                                <x-input-label for="nivel_actividad" :value="__('Nivel de actividad (1.2 - 1.725)')" />
                                <x-text-input id="nivel_actividad" name="nivel_actividad" type="number" step="0.001" min="1.2" max="1.725" class="mt-1 block w-full" :value="old('nivel_actividad', $user->nivel_actividad)" required />
                                <x-input-error class="mt-2" :messages="$errors->get('nivel_actividad')" />
                            </div>

                            <div>
                                <x-input-label for="tipo_deficit" :value="__('Tipo de déficit')" />
                                <select id="tipo_deficit" name="tipo_deficit" required class="mt-1 block w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-lg shadow-sm px-3 py-2.5 text-base sm:text-sm">
                                    <option value="">{{ __('Selecciona una opción') }}</option>
                                    <option value="porcentaje" @selected(old('tipo_deficit', $user->tipo_deficit) === 'porcentaje')>{{ __('Porcentaje') }}</option>
                                    <option value="fijo" @selected(old('tipo_deficit', $user->tipo_deficit) === 'fijo')>{{ __('Fijo') }}</option>
                                </select>
                                <x-input-error class="mt-2" :messages="$errors->get('tipo_deficit')" />
                            </div>

                            <div>
                                <x-input-label for="valor_deficit" :value="__('Valor del déficit')" />
                                <x-text-input id="valor_deficit" name="valor_deficit" type="number" step="0.01" class="mt-1 block w-full" :value="old('valor_deficit', $user->valor_deficit)" required />
                                <x-input-error class="mt-2" :messages="$errors->get('valor_deficit')" />
                            </div>

                            <div>
                                <x-input-label for="proteina_factor" :value="__('Factor de proteína (1.6 - 2.2 g/kg)')" />
                                <x-text-input id="proteina_factor" name="proteina_factor" type="number" step="0.01" min="1.6" max="2.2" class="mt-1 block w-full" :value="old('proteina_factor', $user->proteina_factor)" required />
                                <x-input-error class="mt-2" :messages="$errors->get('proteina_factor')" />
                            </div>

                            <div>
                                <x-input-label for="grasa_factor" :value="__('Factor de grasa (0.6 - 1.0 g/kg)')" />
                                <x-text-input id="grasa_factor" name="grasa_factor" type="number" step="0.01" min="0.6" max="1.0" class="mt-1 block w-full" :value="old('grasa_factor', $user->grasa_factor)" required />
                                <x-input-error class="mt-2" :messages="$errors->get('grasa_factor')" />
                            </div>

                            <div class="flex items-center gap-4">
                                <x-primary-button>{{ __('Guardar') }}</x-primary-button>

                                @if (session('status') === 'parametros-updated')
                                    <p
                                        x-data="{ show: true }"
                                        x-show="show"
                                        x-transition
                                        x-init="setTimeout(() => show = false, 2000)"
                                        class="text-sm text-gray-600"
                                    >{{ __('Guardado.') }}</p>
                                @endif
                            </div>
                        </form>
                    </section>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
