@props([
    // true cuando el menú se apoya sobre un panel carbón (cabecera de Inicio).
    'dark' => false,
])

@php
    $usuario = Auth::user();
    $inicial = mb_strtoupper(mb_substr($usuario?->name ?? '?', 0, 1));
@endphp

<div class="relative flex-none" x-data="{ abierto: false }" @click.outside="abierto = false" @keydown.escape="abierto = false">
    <button type="button"
            x-on:click="abierto = ! abierto"
            :aria-expanded="abierto ? 'true' : 'false'"
            aria-haspopup="true"
            class="grid h-11 w-11 place-items-center rounded-full">
        <span @class([
            'grid h-8 w-8 place-items-center rounded-full text-sm font-semibold',
            'bg-tudi-dark-4 text-tudi-on-dark' => $dark,
            'bg-tudi-surface text-tudi-ink' => ! $dark,
        ])>{{ $inicial }}</span>
        <span class="sr-only">{{ __('Tu cuenta') }}</span>
    </button>

    <div x-show="abierto"
         x-transition
         style="display: none"
         class="absolute end-0 z-50 mt-1 w-52 overflow-hidden rounded-tudi-md border border-tudi-border bg-tudi-card shadow-tudi-md">
        <p class="truncate border-b border-tudi-divider px-4 py-3 text-sm font-semibold">{{ $usuario?->name }}</p>

        <a href="{{ route('profile.edit') }}"
           class="flex min-h-[44px] items-center px-4 text-sm text-tudi-ink-2 no-underline hover:bg-tudi-card-inset">
            {{ __('Mi cuenta') }}
        </a>

        {{-- La consola no cabe en la barra inferior de móvil (sección 4.26). --}}
        @if ($usuario?->esAdministrador())
            <a href="{{ route('admin.inicio') }}"
               class="flex min-h-[44px] items-center px-4 text-sm text-tudi-ink-2 no-underline hover:bg-tudi-card-inset">
                {{ __('Administración') }}
            </a>
        @endif

        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit"
                    class="flex min-h-[44px] w-full items-center px-4 text-start text-sm text-tudi-ink-2 hover:bg-tudi-card-inset">
                {{ __('Cerrar sesión') }}
            </button>
        </form>
    </div>
</div>
