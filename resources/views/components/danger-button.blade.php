{{-- Acción destructiva: ámbar, el color de aviso del sistema (no hay rojo en la paleta). --}}
<button {{ $attributes->merge(['type' => 'submit', 'class' => 'tudi-btn bg-tudi-amber text-tudi-ink hover:bg-tudi-amber-ink hover:text-tudi-on-dark']) }}>
    {{ $slot }}
</button>
