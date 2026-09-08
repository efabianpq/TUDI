@props([
    // Mapa `status` de sesión => mensaje ya traducido.
    'mensajes' => [],
])

@if (session('error'))
    <div class="tudi-note mb-4" role="alert">
        <p>{{ session('error') }}</p>
    </div>
@endif

@if ($errors->any())
    <div class="tudi-note mb-4" role="alert">
        <ul class="space-y-1">
            @foreach ($errors->all() as $mensaje)
                <li>{{ $mensaje }}</li>
            @endforeach
        </ul>
    </div>
@endif

@if (isset($mensajes[session('status')]))
    <div class="mb-4 flex items-center gap-3 rounded-tudi-sm bg-tudi-surface px-4 py-3 text-sm text-tudi-ink-2" role="status">
        <span class="h-2 w-2 flex-none rounded-full bg-tudi-lime"></span>
        {{ $mensajes[session('status')] }}
    </div>
@endif
