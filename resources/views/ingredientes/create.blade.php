<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-lg sm:text-xl text-tudi-ink leading-tight">
            {{ __('Ingredientes disponibles de hoy') }}
        </h2>
    </x-slot>

    <div class="py-6 sm:py-12">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">
            <div class="p-4 sm:p-8 bg-tudi-card shadow-sm rounded-tudi-lg">
                <section x-data="ingredientesForm()">
                    <header>
                        <h2 class="text-lg font-medium text-tudi-ink">
                            {{ __('Reportar ingredientes') }}
                        </h2>
                        <p class="mt-1 text-sm text-tudi-ink-3">
                            {{ __('Agrega todos los ingredientes que tienes disponibles hoy.') }}
                        </p>
                    </header>

                    <form method="post" action="{{ route('ingredientes.store') }}" class="mt-6 space-y-6">
                        @csrf

                        <template x-for="(ingrediente, index) in ingredientes" :key="index">
                            <div class="grid grid-cols-1 sm:grid-cols-6 gap-4 border-b pb-4">
                                <div class="sm:col-span-2">
                                    <x-input-label :value="__('Nombre')" />
                                    <x-text-input type="text" class="mt-1 block w-full" :name="null" x-bind:name="`ingredientes[${index}][nombre]`" x-model="ingrediente.nombre" required />
                                </div>
                                <div>
                                    <x-input-label :value="__('Cantidad (g)')" />
                                    <x-text-input type="number" step="0.01" min="0.01" class="mt-1 block w-full" :name="null" x-bind:name="`ingredientes[${index}][cantidad_g]`" x-model="ingrediente.cantidad_g" required />
                                </div>
                                <div>
                                    <x-input-label :value="__('Kcal/100g')" />
                                    <x-text-input type="number" step="0.01" min="0" class="mt-1 block w-full" :name="null" x-bind:name="`ingredientes[${index}][calorias_por_100g]`" x-model="ingrediente.calorias_por_100g" required />
                                </div>
                                <div>
                                    <x-input-label :value="__('Proteína/100g')" />
                                    <x-text-input type="number" step="0.01" min="0" class="mt-1 block w-full" :name="null" x-bind:name="`ingredientes[${index}][proteina_por_100g]`" x-model="ingrediente.proteina_por_100g" required />
                                </div>
                                <div>
                                    <x-input-label :value="__('Grasa/100g')" />
                                    <x-text-input type="number" step="0.01" min="0" class="mt-1 block w-full" :name="null" x-bind:name="`ingredientes[${index}][grasa_por_100g]`" x-model="ingrediente.grasa_por_100g" required />
                                </div>
                                <div>
                                    <x-input-label :value="__('Carbs/100g')" />
                                    <x-text-input type="number" step="0.01" min="0" class="mt-1 block w-full" :name="null" x-bind:name="`ingredientes[${index}][carbohidratos_por_100g]`" x-model="ingrediente.carbohidratos_por_100g" required />
                                </div>
                                <div class="sm:col-span-6">
                                    <button type="button" class="text-sm text-tudi-amber-ink" @click="quitar(index)">{{ __('Quitar fila') }}</button>
                                </div>
                            </div>
                        </template>

                        <x-input-error class="mt-2" :messages="$errors->get('ingredientes')" />

                        <button type="button" class="text-sm text-tudi-lime-700" @click="agregar()">{{ __('+ Agregar ingrediente') }}</button>

                        <div class="flex items-center gap-4">
                            <x-primary-button>{{ __('Guardar') }}</x-primary-button>

                            @if (session('status') === 'ingredientes-guardados')
                                <p class="text-sm text-tudi-ink-3">{{ __('Guardado.') }}</p>
                            @endif
                        </div>
                    </form>
                </section>
            </div>

            @if ($ingredientes->isNotEmpty())
                <div class="p-4 sm:p-8 bg-tudi-card shadow-sm rounded-tudi-lg">
                    <h2 class="text-lg font-medium text-tudi-ink">{{ __('Ya reportados hoy') }}</h2>
                    <ul class="mt-4 divide-y">
                        @foreach ($ingredientes as $ingrediente)
                            <li class="py-2 flex items-center justify-between">
                                <span>{{ $ingrediente->nombre }} — {{ $ingrediente->cantidad_g }} g</span>
                                <form method="post" action="{{ route('ingredientes.destroy', $ingrediente) }}">
                                    @csrf
                                    @method('delete')
                                    <button type="submit" class="text-sm text-tudi-amber-ink">{{ __('Eliminar') }}</button>
                                </form>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </div>
    </div>

    <script>
        function ingredientesForm() {
            return {
                ingredientes: [
                    { nombre: '', cantidad_g: '', calorias_por_100g: '', proteina_por_100g: '', grasa_por_100g: '', carbohidratos_por_100g: '' },
                ],
                agregar() {
                    this.ingredientes.push({ nombre: '', cantidad_g: '', calorias_por_100g: '', proteina_por_100g: '', grasa_por_100g: '', carbohidratos_por_100g: '' });
                },
                quitar(index) {
                    this.ingredientes.splice(index, 1);
                },
            };
        }
    </script>
</x-app-layout>
