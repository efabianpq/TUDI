<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        {{-- viewport-fit=cover para que la barra inferior respete el safe area de iOS. --}}
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <meta name="theme-color" content="#171512">
        <meta name="mobile-web-app-capable" content="yes">

        <title>{{ config('app.name', 'TUDI') }}</title>

        {{-- Instrument Sans (cifras y texto) + JetBrains Mono (etiquetas y macros). --}}
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Instrument+Sans:wght@400..700&family=JetBrains+Mono:wght@400;500&display=swap">

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="min-h-screen bg-tudi-bg font-sans text-tudi-ink antialiased">
        <div class="min-h-screen sm:flex">
            @include('layouts.navigation')

            <div class="min-w-0 flex-1">
                {{--
                    pb-28 deja sitio para la barra de navegación inferior en
                    móvil; en escritorio la navegación es la barra lateral.
                --}}
                <main class="mx-auto w-full max-w-6xl px-4 pb-28 pt-5 sm:px-8 sm:pb-12 sm:pt-8">
                    @isset($header)
                        <header class="mb-5 sm:mb-7">
                            {{ $header }}
                        </header>
                    @endisset

                    {{ $slot }}
                </main>
            </div>
        </div>

        @stack('scripts')
    </body>
</html>
