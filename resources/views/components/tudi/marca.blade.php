@props([
    'size' => 34,
    'inner' => 'var(--tudi-bg)',
    'track' => 'var(--tudi-ink)',
    'texto' => 22,
    /*
     * Sufijo "Premium" (CLAUDE.md sección 5.26), al modo de YouTube: el
     * logotipo no cambia, se le añade la palabra a la derecha en el color de
     * acento. Por defecto se decide solo, con el plan de quien tiene la sesión
     * abierta; las pantallas públicas (landing, login, registro) lo pasan a
     * `false` a mano porque ahí no se anuncia el plan de nadie.
     */
    'premium' => null,
    // Color del sufijo: lima 700 sobre crema (la única lima con contraste).
    // Sobre un panel carbón se pasa `var(--tudi-lime)`.
    'premiumColor' => 'var(--tudi-lime-700)',
])

@php
    $esPremium = $premium ?? (auth()->user()?->tienePremium() ?? false);

    /*
     * Proporción tomada del handoff (`logo/tudi-logo-premium.svg`): el
     * logotipo va a 24px y el sufijo a 15px sobre la misma línea base.
     */
    $tamanoSufijo = max(9, (int) round($texto * 0.625));
@endphp

{{-- Isotipo + logotipo "tudi" (600, tracking −4%), con el sufijo si toca. --}}
<span {{ $attributes->merge(['class' => 'flex items-baseline gap-3']) }}>
    {{-- El isotipo no tiene línea base propia: se centra contra el logotipo. --}}
    <span class="flex-none self-center">
        <x-tudi.isotipo :size="$size" :inner="$inner" :track="$track" />
    </span>

    <span class="font-semibold leading-none tracking-[-0.04em]" style="font-size: {{ $texto }}px">tudi</span>

    @if ($esPremium)
        <span class="font-semibold leading-none tracking-[-0.01em]"
              style="font-size: {{ $tamanoSufijo }}px; color: {{ $premiumColor }}; margin-inline-start: {{ round($texto * -0.09) }}px">Premium</span>
    @endif
</span>
