@props([
    // 'proteina' | 'grasa' | 'carbohidratos'
    'tipo',
    // Gramos ya formateados, o null para mostrar solo la etiqueta.
    'valor' => null,
    /*
     * Cómo se etiqueta el macro (CLAUDE.md sección 5.12):
     *
     *  - 'icono'   — el icono ilustrado del branding, para los chips compactos
     *                sobre crema, que es donde antes iba una inicial suelta.
     *  - 'palabra' — la palabra completa, para los paneles carbón: el arte va
     *                sobre su propio fondo crema y no se apoya en oscuro.
     */
    'variante' => 'icono',
])

@php
    $macros = [
        'proteina' => ['nombre' => __('Proteína'), 'icono' => 'proteina.png'],
        'grasa' => ['nombre' => __('Grasas'), 'icono' => 'grasa.png'],
        'carbohidratos' => ['nombre' => __('Carbohidratos'), 'icono' => 'carbohidratos.png'],
    ];

    $macro = $macros[$tipo];
@endphp

@if ($variante === 'palabra')
    <span {{ $attributes }}>{{ $macro['nombre'] }}@if ($valor !== null) <span class="tudi-num">{{ $valor }}</span>@endif</span>
@else
    {{--
        El arte del branding trae su propio fondo crema; recortado en círculo
        se apoya sobre la tarjeta sin parecer una imagen pegada encima.
    --}}
    <span {{ $attributes->merge(['class' => 'inline-flex items-center gap-1.5']) }}>
        <img src="{{ asset('icons/macros/'.$macro['icono']) }}"
             alt="{{ $macro['nombre'] }}"
             width="20" height="20" loading="lazy"
             class="h-5 w-5 flex-none rounded-full object-cover">
        @if ($valor !== null)
            <span>{{ $valor }}</span>
        @endif
    </span>
@endif
