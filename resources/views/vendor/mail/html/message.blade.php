{{--
    Cabecera e identidad de marca de los correos transaccionales (CLAUDE.md
    sección 5.26): el isotipo (public/icons/icon-192.png, ya sincronizado en
    producción — ningún archivo nuevo que enlazar) junto al wordmark "tudi" en
    texto, no como imagen, porque muchos clientes de correo bloquean imágenes
    por defecto y el texto sigue siendo legible aunque no carguen.
--}}
<x-mail::layout>
{{-- Header --}}
<x-slot:header>
<x-mail::header :url="config('app.url')">
<table role="presentation" cellpadding="0" cellspacing="0" style="margin: 0 auto;">
<tr>
<td style="vertical-align: middle; padding-right: 10px;">
<img src="{{ asset('icons/icon-192.png') }}" width="32" height="32" alt="" style="display: block; border-radius: 999px;">
</td>
<td style="vertical-align: middle;">
<span style="font-size: 21px; font-weight: 700; color: #171512; letter-spacing: -0.03em;">tudi</span>
</td>
</tr>
</table>
</x-mail::header>
</x-slot:header>

{{-- Body --}}
{!! $slot !!}

{{-- Subcopy --}}
@isset($subcopy)
<x-slot:subcopy>
<x-mail::subcopy>
{!! $subcopy !!}
</x-mail::subcopy>
</x-slot:subcopy>
@endisset

{{-- Footer --}}
<x-slot:footer>
<x-mail::footer>
© {{ date('Y') }} {{ config('app.name') }}. {{ __('Todos los derechos reservados.') }}
</x-mail::footer>
</x-slot:footer>
</x-mail::layout>
