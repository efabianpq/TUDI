<x-app-layout>
    <x-slot name="header">
        <div class="min-w-0">
            <h1 class="text-xl font-semibold tracking-tudi-display sm:text-3xl">
                {{ __('Buen día') }}, {{ str(Auth::user()->name)->before(' ') }}
            </h1>
            <p class="mt-1 text-sm text-tudi-ink-3 first-letter:uppercase">{{ now()->translatedFormat('l d \d\e F') }}</p>
        </div>
    </x-slot>

    <x-tudi.flash :mensajes="[
        'recomendacion-confirmada' => __('Tu objetivo calórico quedó actualizado.'),
        'recomendacion-rechazada' => __('Tu objetivo calórico no cambió.'),
    ]" />

    <div class="space-y-8">

        {{-- Bloque 0 — Avisos: estado del plan, una línea, sin bloquear nada (sección 5.18). --}}
        <x-tudi.plan />

        {{--
            "Ayer no reportaste la cena" (CLAUDE.md sección 5.24). Una línea con
            el enlace al día, para que se pueda arreglar: un hueco sin reportar
            entra luego en el promedio móvil como calorías que nunca se comieron.
        --}}
        @if ($avisoAyer)
            <div class="tudi-card flex flex-wrap items-center justify-between gap-3 border-tudi-amber bg-tudi-amber-bg p-4">
                <p class="text-sm text-tudi-amber-ink">
                    {{ __('Ayer te quedó sin reportar:') }}
                    <span class="font-semibold">{{ implode(', ', $avisoAyer['comidas']) }}</span>.
                </p>
                <a href="{{ route('planes.show', $avisoAyer['registro']) }}"
                   class="tudi-btn tudi-btn-secondary text-[13px] no-underline">
                    {{ __('Completar ayer') }}
                </a>
            </div>
        @endif

        @if ($primerLogin)
            {{-- Bloque 1 — Bienvenida: sustituye Hoy / Tu tendencia / Tu seguimiento enteros (sección 5.8). --}}
            @include('dashboard.partials.bienvenida')
        @else
            @include('dashboard.partials.hoy')
            @include('dashboard.partials.tendencia')
            @include('dashboard.partials.seguimiento')
        @endif
    </div>
</x-app-layout>
