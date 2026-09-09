@php
    $usuario = Auth::user();
    $dias = $usuario?->diasDePruebaRestantes();

    // Un Premium pagante no necesita que le recuerden nada: la tira solo
    // aparece cuando hay algo que decir (sección 5.18).
    $mostrar = $usuario !== null && ! ($usuario->plan === \App\Models\User::PLAN_PREMIUM);
@endphp

@if ($mostrar)
    <div class="flex flex-wrap items-center gap-x-3 gap-y-2 rounded-tudi-md bg-tudi-surface px-4 py-3">
        <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
            @if ($dias !== null)
                <span class="tudi-chip tudi-chip-solid">{{ __('Prueba') }}</span>
                <span class="text-sm text-tudi-ink-2">
                    {{ trans_choice('Te queda :count día de Premium.|Te quedan :count días de Premium.', $dias) }}
                </span>
            @elseif ($usuario->pruebaTerminada())
                <span class="tudi-chip">{{ __('Gratis') }}</span>
                <span class="text-sm text-tudi-ink-2">
                    {{ __('Tu prueba terminó. Conservas todo tu historial.') }}
                </span>
            @else
                <span class="tudi-chip">{{ __('Gratis') }}</span>
                <span class="text-sm text-tudi-ink-2">
                    {{ __('La distribución con IA es parte de Premium.') }}
                </span>
            @endif
        </div>

        {{--
            "Actualizar a Premium" — CLAUDE.md sección 5.18. Todavía no hay
            pasarela de pago (esa es la siguiente sesión), así que este botón
            no lleva a ningún sitio: es a propósito, para que el piloto en
            tudeficitinteligente.online se vea cercano a la versión final sin
            prometer un cobro que aún no existe. En cuanto haya checkout, este
            es el único botón que hay que enlazar.
        --}}
        <button type="button"
                class="ml-auto inline-flex min-h-[36px] flex-none items-center rounded-tudi-pill bg-tudi-ink px-4 text-sm font-semibold text-tudi-on-dark">
            {{ __('Actualizar a Premium') }}
        </button>
    </div>
@endif
