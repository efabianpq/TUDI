@props(['disabled' => false])

{{-- Campo de texto del sistema TUDI (.tudi-input): 48px de alto y 16px de
     tipografía, que es lo que evita el zoom automático de iOS al enfocar. --}}
<input @disabled($disabled) {{ $attributes->merge(['class' => 'tudi-input']) }}>
