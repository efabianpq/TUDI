<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('Plan de comidas de hoy') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            @if (session('error'))
                <div class="p-4 bg-red-50 border border-red-200 text-red-700 rounded-lg">
                    {{ session('error') }}
                </div>
            @endif

            @if (session('status') === 'plan-generado')
                <div class="p-4 bg-green-50 border border-green-200 text-green-700 rounded-lg">
                    {{ __('Tu plan de hoy se generó correctamente.') }}
                </div>
            @endif

            @if (session('status') === 'comida-real-guardada')
                <div class="p-4 bg-green-50 border border-green-200 text-green-700 rounded-lg">
                    {{ __('Tu comida real se registró correctamente.') }}
                </div>
            @endif

            <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg">
                <div class="flex items-center justify-between gap-4">
                    <div>
                        <h3 class="text-lg font-medium text-gray-900">{{ __('Generar mi plan de hoy') }}</h3>
                        <p class="mt-1 text-sm text-gray-600">
                            {{ __('Se reparten tus calorías objetivo entre desayuno, almuerzo y cena usando los ingredientes que reportaste hoy.') }}
                            ({{ __('Ingredientes reportados:') }} {{ $ingredientes->count() }})
                        </p>
                    </div>
                    <form method="post" action="{{ route('planes.generar') }}">
                        @csrf
                        <x-primary-button>{{ __('Generar mi plan de hoy') }}</x-primary-button>
                    </form>
                </div>
            </div>

            @if ($planes->isEmpty())
                <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg">
                    <p class="text-sm text-gray-600">
                        {{ __('Todavía no hay un plan generado para hoy.') }}
                        <a href="{{ route('ingredientes.create') }}" class="text-indigo-600 underline">{{ __('Reportar ingredientes') }}</a>
                    </p>
                </div>
            @else
                @foreach ($planes as $plan)
                    <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg">
                        <div class="flex items-baseline justify-between">
                            <h3 class="text-lg font-medium text-gray-900 capitalize">{{ $plan->tipo_comida }}</h3>
                            <span class="text-sm text-gray-600">
                                {{ $plan->calorias_estimadas }} kcal ·
                                P {{ $plan->proteina_g }} g ·
                                G {{ $plan->grasa_g }} g ·
                                C {{ $plan->carbohidratos_g }} g
                            </span>
                        </div>

                        <p class="mt-1 text-sm text-gray-600">{{ $plan->descripcion }}</p>

                        @if (! empty($plan->ingredientes_detalle))
                            <ul class="mt-4 divide-y text-sm">
                                @foreach ($plan->ingredientes_detalle as $ingrediente)
                                    <li class="py-2 flex items-center justify-between">
                                        <span>{{ $ingrediente['nombre'] }} — {{ number_format($ingrediente['cantidad_g'], 0) }} g</span>
                                        <span class="text-gray-500">
                                            {{ number_format($ingrediente['calorias'], 0) }} kcal ·
                                            {{ number_format($ingrediente['proteina_g'], 1) }} g {{ __('proteína') }}
                                        </span>
                                    </li>
                                @endforeach
                            </ul>
                        @endif

                        <div class="mt-4 pt-4 border-t">
                            @if ($plan->comidaReal)
                                <p class="text-sm text-gray-700">
                                    {{ __('Comida real registrada:') }}
                                    {{ $plan->comidaReal->calorias_reales }} kcal ·
                                    P {{ $plan->comidaReal->proteina_g }} g ·
                                    G {{ $plan->comidaReal->grasa_g }} g ·
                                    C {{ $plan->comidaReal->carbohidratos_g }} g
                                </p>
                                @if ($plan->comidaReal->notas)
                                    <p class="mt-1 text-sm text-gray-500">{{ $plan->comidaReal->notas }}</p>
                                @endif
                                @if ($plan->comidaReal->imagenUrl())
                                    <img src="{{ $plan->comidaReal->imagenUrl() }}" alt="{{ __('Evidencia visual') }}" class="mt-2 max-h-48 rounded-lg">
                                @endif
                            @else
                                <a href="{{ route('comida-real.create', $plan) }}" class="text-sm text-indigo-600 underline">
                                    {{ __('Registrar comida real') }}
                                </a>
                            @endif
                        </div>
                    </div>
                @endforeach

                <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg text-sm text-gray-700">
                    {{ __('Total planificado:') }}
                    <strong>{{ number_format($planes->sum(fn ($plan) => (float) $plan->calorias_estimadas), 0) }} kcal</strong> ·
                    {{ number_format($planes->sum(fn ($plan) => (float) $plan->proteina_g), 1) }} g {{ __('de proteína') }}
                </div>
            @endif
        </div>
    </div>
</x-app-layout>
