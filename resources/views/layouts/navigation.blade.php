@php
    // Objetivo calórico vigente (CLAUDE.md sección 4.10): es lo principal que
    // el usuario tiene que cumplir, así que se muestra en todas las páginas
    // junto a su nombre — sección 4.14.
    $objetivoCalorico = Auth::user()?->calorias_objetivo;

    // Tres destinos, no cinco (CLAUDE.md sección 4.17): "Mi progreso" se
    // fusionó con Inicio y "Registrar peso" pasó a ser un campo del plan
    // diario, así que ninguno de los dos necesita ya su propia entrada.
    $enlacesPrincipales = [
        ['ruta' => 'dashboard', 'patron' => 'dashboard', 'texto' => __('Inicio'), 'textoLargo' => __('Inicio')],
        ['ruta' => 'calculadora.edit', 'patron' => 'calculadora.*', 'texto' => __('Calculadora'), 'textoLargo' => __('Calculadora Déficit')],
        ['ruta' => 'planes.index', 'patron' => 'planes.*', 'texto' => __('Planes'), 'textoLargo' => __('Planes diarios')],
    ];
@endphp

<nav x-data="{ open: false }" class="bg-white border-b border-gray-100 sticky top-0 z-30">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex justify-between items-center h-16 gap-3">
            <div class="flex items-center min-w-0">
                <a href="{{ route('dashboard') }}" class="shrink-0 flex items-center">
                    <x-application-logo class="block h-8 w-auto fill-current text-gray-800" />
                </a>

                {{-- Enlaces principales: solo escritorio. En móvil viven en la barra inferior. --}}
                <div class="hidden sm:flex sm:space-x-6 sm:-my-px sm:ms-8">
                    @foreach ($enlacesPrincipales as $enlace)
                        <x-nav-link :href="route($enlace['ruta'])" :active="request()->routeIs($enlace['patron'])">
                            {{ $enlace['textoLargo'] }}
                        </x-nav-link>
                    @endforeach
                </div>
            </div>

            <div class="flex items-center gap-2 sm:gap-4 min-w-0">
                {{-- Objetivo calórico: visible en todas las páginas, también en móvil. --}}
                @if ($objetivoCalorico !== null)
                    <a href="{{ route('calculadora.edit') }}"
                       class="shrink-0 inline-flex items-center gap-1.5 rounded-full bg-indigo-50 px-3 py-1.5 text-indigo-700 hover:bg-indigo-100 transition"
                       title="{{ __('Tu objetivo calórico diario') }}">
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 3v2m0 14v2m9-9h-2M5 12H3m14.7-6.7-1.4 1.4M7.7 16.3l-1.4 1.4m0-11.4 1.4 1.4m8.6 8.6 1.4 1.4M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                        </svg>
                        <span class="text-sm font-semibold whitespace-nowrap">
                            {{ number_format((float) $objetivoCalorico, 0, ',', '.') }}
                            <span class="font-normal">{{ __('kcal/día') }}</span>
                        </span>
                    </a>
                @else
                    <a href="{{ route('calculadora.edit') }}"
                       class="shrink-0 inline-flex items-center rounded-full bg-amber-50 px-3 py-1.5 text-sm font-medium text-amber-800 hover:bg-amber-100 transition">
                        {{ __('Calcula tu objetivo') }}
                    </a>
                @endif

                <x-dropdown align="right" width="48">
                    <x-slot name="trigger">
                        <button class="inline-flex items-center gap-1 rounded-md px-2 py-2 text-sm font-medium text-gray-500 hover:text-gray-700 focus:outline-none transition">
                            <span class="hidden sm:inline max-w-[10rem] truncate">{{ Auth::user()->name }}</span>
                            <span class="sm:hidden flex h-8 w-8 items-center justify-center rounded-full bg-gray-100 text-gray-700 font-semibold">
                                {{ mb_strtoupper(mb_substr(Auth::user()->name, 0, 1)) }}
                            </span>
                            <svg class="fill-current h-4 w-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" aria-hidden="true">
                                <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" />
                            </svg>
                        </button>
                    </x-slot>

                    <x-slot name="content">
                        <div class="px-4 py-2 border-b border-gray-100 sm:hidden">
                            <div class="font-medium text-sm text-gray-800 truncate">{{ Auth::user()->name }}</div>
                            <div class="text-xs text-gray-500 truncate">{{ Auth::user()->email }}</div>
                        </div>

                        <x-dropdown-link :href="route('calculadora.edit')">
                            {{ __('Calculadora Déficit') }}
                        </x-dropdown-link>

                        <x-dropdown-link :href="route('profile.edit')">
                            {{ __('Mi cuenta') }}
                        </x-dropdown-link>

                        <form method="POST" action="{{ route('logout') }}">
                            @csrf

                            <x-dropdown-link :href="route('logout')"
                                    onclick="event.preventDefault();
                                                this.closest('form').submit();">
                                {{ __('Cerrar sesión') }}
                            </x-dropdown-link>
                        </form>
                    </x-slot>
                </x-dropdown>
            </div>
        </div>
    </div>
</nav>

{{--
    Barra de navegación inferior, estilo app móvil (CLAUDE.md sección 4.15).
    Solo se muestra por debajo del breakpoint `sm`; en escritorio los mismos
    destinos están en la barra superior.
--}}
<nav class="sm:hidden fixed bottom-0 inset-x-0 z-40 bg-white border-t border-gray-200 pb-[env(safe-area-inset-bottom)]"
     aria-label="{{ __('Navegación principal') }}">
    <div class="grid grid-cols-3">
        @foreach ($enlacesPrincipales as $enlace)
            @php $activo = request()->routeIs($enlace['patron']); @endphp
            <a href="{{ route($enlace['ruta']) }}"
               @class([
                   'flex flex-col items-center justify-center gap-0.5 py-2 min-h-[3.5rem] text-[11px] font-medium transition',
                   'text-indigo-600' => $activo,
                   'text-gray-500 hover:text-gray-700' => ! $activo,
               ])
               @if ($activo) aria-current="page" @endif>
                <svg class="h-6 w-6" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" aria-hidden="true">
                    @switch($enlace['ruta'])
                        @case('dashboard')
                            <path stroke-linecap="round" stroke-linejoin="round" d="m3 11 9-8 9 8M5 10v10h14V10" />
                            @break
                        @case('calculadora.edit')
                            <path stroke-linecap="round" stroke-linejoin="round" d="M8 3h8a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2Zm1 4h6M9 12h.01M12 12h.01M15 12h.01M9 16h.01M12 16h.01M15 16h.01" />
                            @break
                        @case('planes.index')
                            <path stroke-linecap="round" stroke-linejoin="round" d="M5 4h14a1 1 0 0 1 1 1v15l-4-2-4 2-4-2-4 2V5a1 1 0 0 1 1-1Zm3 5h8M8 13h5" />
                            @break
                        @default
                            <path stroke-linecap="round" stroke-linejoin="round" d="M4 19V9m5 10V5m5 14v-7m5 7V8" />
                    @endswitch
                </svg>
                <span>{{ $enlace['texto'] }}</span>
            </a>
        @endforeach
    </div>
</nav>
