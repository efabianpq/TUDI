@php
    $kcal = fn ($valor) => number_format((float) $valor, 0, ',', '.');
@endphp

<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between gap-3">
            <h1 class="text-lg font-semibold tracking-tudi-title sm:text-2xl">{{ __('Planes diarios') }}</h1>
            <span class="tudi-meta">{{ trans_choice(':count plan|:count planes', $planes->total()) }}</span>
        </div>
    </x-slot>

    <x-tudi.flash />

    <div class="space-y-5">
        @unless ($perfilCompleto)
            <div class="tudi-panel">
                <p class="tudi-label">{{ __('Calculadora Déficit') }}</p>
                <p class="mt-3 text-tudi-on-dark-2">{{ __('Completa tu Calculadora Déficit para saber cuántas calorías debes consumir cada día.') }}</p>
                <a href="{{ route('calculadora.edit') }}" class="tudi-btn tudi-btn-lime tudi-btn-block mt-5 no-underline">
                    {{ __('Ir a la Calculadora Déficit') }}
                </a>
            </div>
        @endunless

        {{-- ── Hoy ── --}}
        <div class="tudi-card flex flex-wrap items-center justify-between gap-3 p-5">
            <div>
                <p class="tudi-label">{{ __('Hoy') }}</p>
                <p class="mt-1 text-lg font-semibold tracking-tudi-title">{{ now()->format('d/m/Y') }}</p>
            </div>

            @if ($registroDeHoy)
                <a href="{{ route('planes.show', $registroDeHoy) }}"
                   class="tudi-btn tudi-btn-primary w-full no-underline sm:w-auto">
                    {{ __('Abrir el plan de hoy') }}
                </a>
            @else
                <form method="post" action="{{ route('planes.crear') }}" class="w-full sm:w-auto">
                    @csrf
                    <button type="submit" class="tudi-btn tudi-btn-primary tudi-btn-block sm:w-auto">
                        {{ __('Crear plan diario') }}
                    </button>
                </form>
            @endif
        </div>

        {{-- ── Histórico ── --}}
        @if ($planes->isEmpty())
            <p class="px-1 text-sm text-tudi-muted">{{ __('Todavía no tienes ningún plan diario.') }}</p>
        @else
            <ul class="space-y-2">
                @foreach ($planes as $plan)
                    <li>
                        <a href="{{ route('planes.show', $plan) }}"
                           class="tudi-card flex items-center justify-between gap-4 p-4 text-tudi-ink no-underline hover:bg-tudi-card-inset">
                            <span class="min-w-0">
                                <span class="flex items-center gap-2">
                                    <span @class([
                                        'h-2 w-2 flex-none rounded-full',
                                        'bg-tudi-lime' => $plan->cerrado,
                                        'bg-tudi-input-border' => ! $plan->cerrado,
                                    ])></span>
                                    <span class="font-semibold tracking-tudi-title">{{ $plan->fecha->format('d/m/Y') }}</span>
                                    <span class="tudi-meta capitalize">{{ $plan->fecha->translatedFormat('l') }}</span>
                                    <span class="sr-only">{{ $plan->cerrado ? __('cerrado') : __('abierto') }}</span>
                                </span>
                                <span class="tudi-meta mt-1 block">
                                    {{ $plan->comidas_registradas }}/{{ max($plan->comidas_planificadas, 3) }} {{ __('comidas') }}
                                    @if ($plan->peso_kg)
                                        · {{ number_format((float) $plan->peso_kg, 1, ',', '.') }} kg
                                    @endif
                                </span>
                            </span>

                            <span class="flex-none text-end">
                                @if ($plan->cerrado)
                                    <span @class([
                                        'tudi-num block text-lg',
                                        'text-tudi-lime-700' => (float) $plan->deficit_diario >= 0,
                                        'text-tudi-amber-ink' => (float) $plan->deficit_diario < 0,
                                    ])>{{ $kcal($plan->deficit_diario) }}</span>
                                    <span class="tudi-label">{{ __('kcal de déficit') }}</span>
                                @else
                                    <span class="tudi-meta">{{ __('sin cerrar') }}</span>
                                @endif
                            </span>
                        </a>
                    </li>
                @endforeach
            </ul>

            @if ($planes->hasPages())
                <div class="tudi-card p-4">
                    {{ $planes->links() }}
                </div>
            @endif
        @endif
    </div>
</x-app-layout>
