@props(['disabled' => false])

{{--
    `text-base` en móvil evita el zoom automático de iOS al enfocar un campo
    (Safari lo aplica por debajo de 16px) y `py-2.5` da un área táctil cómoda;
    en escritorio se vuelve al tamaño compacto original (CLAUDE.md sección 4.15).
--}}
<input @disabled($disabled) {{ $attributes->merge(['class' => 'border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-lg shadow-sm px-3 py-2.5 text-base sm:text-sm']) }}>
