@props([
    'size' => 34,
    'inner' => 'var(--tudi-bg)',
    'track' => 'var(--tudi-ink)',
    'texto' => 22,
])

{{-- Isotipo + logotipo "tudi" (600, tracking −4%). --}}
<span {{ $attributes->merge(['class' => 'flex items-center gap-3']) }}>
    <x-tudi.isotipo :size="$size" :inner="$inner" :track="$track" />
    <span class="font-semibold leading-none tracking-[-0.04em]" style="font-size: {{ $texto }}px">tudi</span>
</span>
