<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name', 'TUDI') }}</title>

        {{-- Mismo shell instalable que el área autenticada (sección 4.20): si se
             instala desde el login, la sesión sigue dentro de la app. --}}
        <link rel="manifest" href="{{ asset('manifest.webmanifest') }}">
        <meta name="theme-color" content="#171512">
        <meta name="mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
        <meta name="apple-mobile-web-app-title" content="tudi">
        <link rel="apple-touch-icon" href="{{ asset('icons/apple-touch-icon.png') }}">
        <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('icons/favicon-32.png') }}">

        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Instrument+Sans:wght@400..700&family=JetBrains+Mono:wght@400;500&display=swap">

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="min-h-[100dvh] bg-tudi-bg font-sans text-tudi-ink antialiased">
        {{-- Mismo descuento del safe area que el layout de la app (sección 5.12). --}}
        <div class="flex min-h-[100dvh] flex-col items-center justify-center px-4 pb-[calc(env(safe-area-inset-bottom)+2.5rem)] pt-[calc(env(safe-area-inset-top)+2.5rem)]">
            {{-- Sin sufijo Premium: login, registro y recuperación no anuncian
                 el plan de nadie (sección 5.26). --}}
            <a href="/" class="text-tudi-ink no-underline">
                <x-tudi.marca :size="46" :texto="26" :premium="false" />
            </a>

            <p class="tudi-meta mt-3">{{ __('Tu déficit, en un solo número.') }}</p>

            <div class="tudi-card mt-6 w-full max-w-md p-6 sm:p-7">
                {{ $slot }}
            </div>
        </div>
    </body>
</html>
