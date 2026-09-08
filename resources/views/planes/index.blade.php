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

    <x-tudi.flash :mensajes="['plan-eliminado' => __('Plan diario eliminado.')]" />

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
            {{--
                El enlace y el botón de eliminar son hermanos, no anidados: un
                <form> dentro de un <a> no es HTML válido y el navegador lo
                reordena por su cuenta (CLAUDE.md sección 5.16).
            --}}
            <ul class="space-y-2">
                @foreach ($planes as $plan)
                    <li class="tudi-card flex items-center gap-2 p-2" x-data="{ confirmando: false }">
                        <a href="{{ route('planes.show', $plan) }}"
                           class="flex min-w-0 flex-1 items-center justify-between gap-4 rounded-tudi-sm p-2 text-tudi-ink no-underline hover:bg-tudi-card-inset">
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

                        {{-- Eliminar arrastra todo el día en cascada, así que
                             pide confirmación explícita antes de enviarse. --}}
                        <button type="button" x-show="! confirmando" x-on:click="confirmando = true"
                                aria-label="{{ __('Eliminar el plan del') }} {{ $plan->fecha->format('d/m/Y') }}"
                                class="grid h-11 w-11 flex-none place-items-center rounded-full text-tudi-muted hover:bg-tudi-surface hover:text-tudi-amber-ink">
                            <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.7" viewBox="0 0 24 24" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M4 7h16M9 7V5a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2m-9 0 1 13a1 1 0 0 0 1 1h8a1 1 0 0 0 1-1l1-13" />
                            </svg>
                        </button>

                        <form method="post" action="{{ route('planes.destroy', $plan) }}"
                              x-show="confirmando" style="display: none" class="flex flex-none gap-1.5">
                            @csrf
                            @method('delete')
                            <button type="submit" class="tudi-btn bg-tudi-amber text-tudi-ink">{{ __('Eliminar') }}</button>
                            <button type="button" x-on:click="confirmando = false" class="tudi-btn tudi-btn-ghost">{{ __('No') }}</button>
                        </form>
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
