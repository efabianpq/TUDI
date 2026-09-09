<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        {{-- viewport-fit=cover para que la barra inferior respete el safe area de iOS. --}}
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        {{--
            Plan B del dictado por voz (CLAUDE.md sección 5.9), APAGADO por
            defecto: el camino normal es el reconocimiento nativo del navegador,
            que no cuesta nada. Sin esta meta, dictado.js no tiene a dónde
            mandar audio y no puede gastar una llamada al proveedor ni ocupar un
            worker de PHP-FPM.
        --}}
        @if (config('services.transcripcion.fallback_servidor'))
            <meta name="ruta-transcribir" content="{{ route('transcribir') }}">
        @endif

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
                <x-tudi.barra-superior />

                {{--
                    En móvil, `main` deja hueco arriba para la barra superior
                    fija y abajo para la de navegación; en escritorio la
                    navegación es la barra lateral y no hace falta ninguno.

                    El hueco de arriba incluye `env(safe-area-inset-top)`
                    (CLAUDE.md sección 5.12). Con `viewport-fit=cover` y la barra
                    de estado translúcida de iOS, la página empieza DEBAJO del
                    reloj y la señal: sin esto, la barra superior —y con ella el
                    menú de la cuenta— quedaba solapada con la del sistema y no
                    se podía pulsar. En el navegador, sin instalar, el inset es
                    cero y el espaciado es el de siempre.
                --}}
                <main class="mx-auto flex w-full max-w-6xl flex-col px-4 pb-28 pt-[calc(env(safe-area-inset-top)+3.75rem)] sm:px-8 sm:pb-12 sm:pt-8">
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
