<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        {{-- viewport-fit=cover para que la barra inferior respete el safe area de iOS. --}}
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        {{-- Plan B del dictado por voz: lo lee resources/js/tudi/dictado.js. --}}
        <meta name="ruta-transcribir" content="{{ route('transcribir') }}">

        <title>{{ config('app.name', 'TUDI') }}</title>

        {{--
            Instalable como app (CLAUDE.md sección 4.20): con el manifest y las
            metas de Apple, "Añadir a pantalla de inicio" abre TUDI a pantalla
            completa —sin barra de URL ni botones del navegador— en todas las
            pantallas por igual, que es lo que se pedía.
        --}}
        <link rel="manifest" href="{{ asset('manifest.webmanifest') }}">
        <meta name="theme-color" content="#171512">
        <meta name="mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
        <meta name="apple-mobile-web-app-title" content="tudi">
        <link rel="apple-touch-icon" href="{{ asset('icons/apple-touch-icon.png') }}">
        <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('icons/favicon-32.png') }}">

        {{-- Instrument Sans (cifras y texto) + JetBrains Mono (etiquetas y macros). --}}
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Instrument+Sans:wght@400..700&family=JetBrains+Mono:wght@400;500&display=swap">

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    {{--
        min-h-[100dvh] (no 100vh): en móvil, `dvh` descuenta la cromática del
        navegador de verdad, así que el alto útil es el mismo en toda la app y
        ninguna pantalla se queda "corta" respecto de otra.
    --}}
    <body class="min-h-[100dvh] bg-tudi-bg font-sans text-tudi-ink antialiased">
        <div class="min-h-[100dvh] sm:flex">
            @include('layouts.navigation')

            <div class="min-w-0 flex-1">
                {{--
                    pb-28 deja sitio para la barra de navegación inferior en
                    móvil; en escritorio la navegación es la barra lateral.
                --}}
                <main class="mx-auto flex w-full max-w-6xl flex-col px-4 pb-28 pt-5 sm:px-8 sm:pb-12 sm:pt-8">
                    @isset($header)
                        <header class="mb-5 sm:mb-7">
                            {{ $header }}
                        </header>
                    @endisset

                    {{ $slot }}
                </main>
            </div>
        </div>

        <x-tudi.instalar />
        <x-tudi.cargando />
        <x-tudi.dictado />

        @stack('scripts')
    </body>
</html>
