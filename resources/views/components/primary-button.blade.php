{{-- Primario sobre fondo claro: carbón. La lima nunca rellena un botón sobre crema. --}}
<button {{ $attributes->merge(['type' => 'submit', 'class' => 'tudi-btn tudi-btn-primary']) }}>
    {{ $slot }}
</button>
