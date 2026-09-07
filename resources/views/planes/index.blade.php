@php
    $kcal = fn ($valor) => number_format((float) $valor, 0, ',', '.');
@endphp

<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
            <h2 class="font-semibold text-lg sm:text-xl text-gray-800 leading-tight">
                {{ __('Planes diarios') }}
            </h2>
            <span class="text-sm text-gray-500">
                {{ trans_choice(':count plan|:count planes', $planes->total()) }}
            </span>
        </div>
    </x-slot>

    <div class="py-4 sm:py-8">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-4 sm:space-y-6">

            @if (session('error'))
                <div class="p-4 bg-red-50 border border-red-200 text-red-700 rounded-xl text-sm">
                    {{ session('error') }}
                </div>
            @endif

            @unless ($perfilCompleto)
                <div class="p-4 sm:p-6 bg-white shadow-sm rounded-xl">
                    <h3 class="text-base sm:text-lg font-medium text-gray-900">{{ __('Primero, tu objetivo calórico') }}</h3>
                    <p class="mt-2 text-sm text-gray-600">
                        {{ __('Completa tu Calculadora Déficit para saber cuántas calorías debes consumir cada día.') }}
                    </p>
                    <a href="{{ route('calculadora.edit') }}"
                       class="mt-4 inline-flex w-full sm:w-auto items-center justify-center rounded-lg bg-indigo-600 px-5 py-3 text-sm font-semibold text-white hover:bg-indigo-700 transition">
                        {{ __('Ir a la Calculadora Déficit') }}
                    </a>
                </div>
            @endunless

            {{-- ── El día de hoy: crear su plan o entrar al que ya existe ── --}}
            <div class="p-4 sm:p-6 bg-white shadow-sm rounded-xl">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h3 class="text-base sm:text-lg font-medium text-gray-900">{{ __('Hoy') }}</h3>
                        <p class="mt-1 text-sm text-gray-600">
                            {{ now()->format('d/m/Y') }} ·
                            {{ $registroDeHoy ? __('ya tienes un plan para hoy') : __('todavía no has creado el plan de hoy') }}
                        </p>
                    </div>

                    @if ($registroDeHoy)
                        <a href="{{ route('planes.show', $registroDeHoy) }}"
                           class="inline-flex w-full sm:w-auto items-center justify-center rounded-lg bg-indigo-600 px-5 py-3 text-sm font-semibold text-white hover:bg-indigo-700 transition">
                            {{ __('Abrir el plan de hoy') }}
                        </a>
                    @else
                        <form method="post" action="{{ route('planes.crear') }}" class="w-full sm:w-auto">
                            @csrf
                            <button type="submit"
                                    class="inline-flex w-full sm:w-auto items-center justify-center rounded-lg bg-indigo-600 px-5 py-3 text-sm font-semibold text-white hover:bg-indigo-700 transition">
                                {{ __('Crear plan diario') }}
                            </button>
                        </form>
                    @endif
                </div>
            </div>

            {{-- ── Histórico ── --}}
            <div class="bg-white shadow-sm rounded-xl overflow-hidden">
                <div class="border-b border-gray-100 px-4 py-3 sm:px-6">
                    <h3 class="text-base sm:text-lg font-medium text-gray-900">{{ __('Tus planes diarios') }}</h3>
                    <p class="mt-0.5 text-xs text-gray-500">
                        {{ __('Entra en cualquiera para ver o completar su detalle: comidas, actividad y cierre.') }}
                    </p>
                </div>

                @if ($planes->isEmpty())
                    <p class="px-4 py-8 sm:px-6 text-sm text-gray-600 text-center">
                        {{ __('Todavía no tienes ningún plan diario. Crea el de hoy para empezar.') }}
                    </p>
                @else
                    <ul class="divide-y divide-gray-100">
                        @foreach ($planes as $plan)
                            <li>
                                <a href="{{ route('planes.show', $plan) }}"
                                   class="flex flex-wrap items-center justify-between gap-x-4 gap-y-2 px-4 py-4 sm:px-6 hover:bg-gray-50 transition">
                                    <div class="min-w-0">
                                        <p class="flex items-center gap-2 text-sm font-semibold text-gray-900">
                                            {{ $plan->fecha->format('d/m/Y') }}
                                            <span class="text-xs font-normal text-gray-500 capitalize">
                                                {{ $plan->fecha->translatedFormat('l') }}
                                            </span>
                                            <span @class([
                                                'inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-medium',
                                                'bg-green-100 text-green-800' => $plan->cerrado,
                                                'bg-amber-100 text-amber-800' => ! $plan->cerrado,
                                            ])>{{ $plan->cerrado ? __('cerrado') : __('abierto') }}</span>
                                        </p>
                                        <p class="mt-1 text-xs text-gray-500">
                                            {{ $plan->comidas_registradas }}/{{ max($plan->comidas_planificadas, 3) }} {{ __('comidas registradas') }}
                                            · {{ trans_choice(':count actividad|:count actividades', $plan->actividades_registradas) }}
                                            @if ($plan->peso_kg)
                                                · {{ number_format((float) $plan->peso_kg, 1, ',', '.') }} kg
                                            @endif
                                        </p>
                                    </div>

                                    <div class="text-end">
                                        @if ($plan->cerrado)
                                            <p class="text-sm font-semibold {{ (float) $plan->deficit_diario >= 0 ? 'text-green-700' : 'text-red-700' }}">
                                                {{ $kcal($plan->deficit_diario) }} kcal
                                            </p>
                                            <p class="text-xs text-gray-500">
                                                {{ __('déficit') }} ·
                                                {{ $kcal($plan->calorias_consumidas) }}/{{ $kcal($plan->calorias_objetivo_dia) }} kcal
                                            </p>
                                        @else
                                            <p class="text-xs text-gray-500">{{ __('sin cerrar') }}</p>
                                        @endif
                                    </div>
                                </a>
                            </li>
                        @endforeach
                    </ul>

                    @if ($planes->hasPages())
                        <div class="border-t border-gray-100 px-4 py-3 sm:px-6">
                            {{ $planes->links() }}
                        </div>
                    @endif
                @endif
            </div>
        </div>
    </div>
</x-app-layout>
