@php
    // Objetivo calórico vigente (CLAUDE.md sección 4.10): es la cifra que rige
    // todo lo demás, así que acompaña al nombre del usuario en la barra lateral.
    $objetivoCalorico = Auth::user()?->calorias_objetivo;

    // Tres destinos, no cinco (CLAUDE.md sección 4.17): "Mi progreso" se
    // fusionó con Inicio y "Registrar peso" pasó a ser un campo del plan
    // diario, así que ninguno de los dos necesita ya su propia entrada.
    $enlacesPrincipales = [
        ['ruta' => 'dashboard', 'patron' => 'dashboard', 'texto' => __('Inicio'), 'textoLargo' => __('Inicio')],
        ['ruta' => 'calculadora.edit', 'patron' => 'calculadora.*', 'texto' => __('Calculadora'), 'textoLargo' => __('Calculadora déficit')],
        ['ruta' => 'planes.index', 'patron' => 'planes.*', 'texto' => __('Planes'), 'textoLargo' => __('Planes diarios')],
    ];

    $iconos = [
        'dashboard' => 'm3 11 9-8 9 8M5 10v10h14V10',
        'calculadora.edit' => 'M8 3h8a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2Zm1 4h6M9 12h.01M12 12h.01M15 12h.01M9 16h.01M12 16h.01M15 16h.01',
        'planes.index' => 'M5 4h14a1 1 0 0 1 1 1v15l-4-2-4 2-4-2-4 2V5a1 1 0 0 1 1-1Zm3 5h8M8 13h5',
    ];
@endphp

{{-- ── Escritorio: barra lateral de 232px ── --}}
<nav class="hidden w-[232px] flex-none flex-col gap-6 self-stretch bg-tudi-surface p-4 pt-6 sm:sticky sm:top-0 sm:flex sm:h-screen"
     aria-label="{{ __('Navegación principal') }}">
    <a href="{{ route('dashboard') }}" class="px-2 text-tudi-ink no-underline">
        <x-tudi.marca :size="34" inner="var(--tudi-surface)" />
    </a>

    <div class="flex flex-col gap-1.5">
        @foreach ($enlacesPrincipales as $enlace)
            @php $activo = request()->routeIs($enlace['patron']); @endphp
            <a href="{{ route($enlace['ruta']) }}"
               class="tudi-side-link"
               @if ($activo) aria-current="page" @endif>
                <svg class="h-5 w-5 flex-none" fill="none" stroke="currentColor" stroke-width="1.7" viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="{{ $iconos[$enlace['ruta']] }}" />
                </svg>
                {{ $enlace['textoLargo'] }}
            </a>
        @endforeach
    </div>

    <div class="flex-1"></div>

    {{-- Tarjeta de usuario: nombre y objetivo diario. --}}
    <a href="{{ route('profile.edit') }}"
       class="flex items-center gap-3 rounded-tudi-md bg-tudi-card-inset p-3 text-tudi-ink no-underline hover:bg-tudi-card">
        <span class="grid h-8 w-8 flex-none place-items-center rounded-full bg-tudi-lime text-sm font-semibold text-tudi-ink">
            {{ mb_strtoupper(mb_substr(Auth::user()?->name ?? '?', 0, 1)) }}
        </span>
        <span class="min-w-0">
            <span class="block truncate text-sm font-semibold">{{ Auth::user()?->name }}</span>
            @if ($objetivoCalorico !== null)
                <span class="tudi-meta block">{{ number_format((float) $objetivoCalorico, 0, ',', '.') }} {{ __('kcal/día') }}</span>
            @else
                <span class="tudi-meta block text-tudi-amber-ink">{{ __('sin objetivo') }}</span>
            @endif
        </span>
    </a>

    <form method="POST" action="{{ route('logout') }}">
        @csrf
        <button type="submit" class="tudi-side-link w-full">
            <svg class="h-5 w-5 flex-none" fill="none" stroke="currentColor" stroke-width="1.7" viewBox="0 0 24 24" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M15 17v2a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h7a2 2 0 0 1 2 2v2m3 5H9m9 0-3-3m3 3-3 3" />
            </svg>
            {{ __('Cerrar sesión') }}
        </button>
    </form>
</nav>

{{-- ── Móvil: barra inferior fija con los mismos tres destinos ── --}}
<nav class="tudi-tabbar fixed inset-x-0 bottom-0 z-40 pb-[calc(env(safe-area-inset-bottom)+16px)] sm:hidden"
     aria-label="{{ __('Navegación principal') }}">
    @foreach ($enlacesPrincipales as $enlace)
        @php $activo = request()->routeIs($enlace['patron']); @endphp
        <a href="{{ route($enlace['ruta']) }}" @if ($activo) aria-current="page" @endif>
            <svg class="h-6 w-6" fill="none" stroke="currentColor" stroke-width="1.7" viewBox="0 0 24 24" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="{{ $iconos[$enlace['ruta']] }}" />
            </svg>
            {{ $enlace['texto'] }}
        </a>
    @endforeach
</nav>
