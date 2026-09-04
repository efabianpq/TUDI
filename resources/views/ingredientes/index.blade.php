<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('Ingredientes del') }} {{ $registroDiario->fecha->format('d/m/Y') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg">
                @if ($ingredientes->isEmpty())
                    <p class="text-sm text-gray-600">{{ __('No hay ingredientes reportados para este día.') }}</p>
                @else
                    <ul class="divide-y">
                        @foreach ($ingredientes as $ingrediente)
                            <li class="py-3 flex items-center justify-between">
                                <span>{{ $ingrediente->nombre }} — {{ $ingrediente->cantidad_g }} g ({{ $ingrediente->calorias_por_100g }} kcal/100g)</span>
                                <form method="post" action="{{ route('ingredientes.destroy', $ingrediente) }}">
                                    @csrf
                                    @method('delete')
                                    <button type="submit" class="text-sm text-red-600">{{ __('Eliminar') }}</button>
                                </form>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </div>
    </div>
</x-app-layout>
