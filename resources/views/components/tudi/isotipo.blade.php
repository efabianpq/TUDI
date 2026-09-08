@props([
    // Diámetro en px. El círculo interior es el 46% del total, como en el mockup.
    'size' => 34,
    // Color del círculo interior: el del fondo sobre el que se apoya la marca.
    'inner' => 'var(--tudi-bg)',
    // Resto del anillo: carbón sobre claro, gris carbón sobre panel oscuro.
    'track' => 'var(--tudi-ink)',
])

{{-- Isotipo: anillo de progreso con el corte del déficit (252° de lima). --}}
<span aria-hidden="true"
      class="grid flex-none place-items-center rounded-full"
      style="width: {{ $size }}px; height: {{ $size }}px; background: conic-gradient(var(--tudi-lime) 0 252deg, {{ $track }} 252deg 360deg)">
    <span class="rounded-full"
          style="width: {{ round($size * 0.46) }}px; height: {{ round($size * 0.46) }}px; background: {{ $inner }}"></span>
</span>
