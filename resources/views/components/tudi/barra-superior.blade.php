{{--
    Barra superior fija, común a toda la plataforma (CLAUDE.md sección 5.12).

    Es el espejo de la barra inferior: vive en el layout, no en cada vista, así
    que la marca, la fecha y el acceso a la cuenta están siempre en el mismo
    sitio. Antes cada pantalla montaba su propia cabecera y solo algunas
    incluían el menú del avatar, así que desde el plan diario —por ejemplo— no
    había forma de llegar a "Mi cuenta" ni de cerrar sesión.

    Solo en móvil (`sm:hidden`): en escritorio la barra lateral ya tiene la
    marca, la tarjeta de usuario y el botón de cerrar sesión, y repetirlas
    arriba sería ruido.

    `pt-[env(safe-area-inset-top)]` es lo que la mantiene pulsable cuando la app
    está instalada en iOS, donde la página empieza debajo del reloj.
--}}
<header class="tudi-topbar fixed inset-x-0 top-0 z-40 pt-[env(safe-area-inset-top)] sm:hidden">
    <div class="flex items-center justify-between gap-3 px-4 py-2">
        <a href="{{ route('dashboard') }}" class="flex-none text-tudi-ink no-underline"
           aria-label="{{ __('Ir a Inicio') }}">
            <x-tudi.marca :size="26" :texto="17" />
        </a>

        <div class="flex items-center gap-1">
            <span class="tudi-meta uppercase">{{ now()->translatedFormat('d M') }}</span>
            <x-tudi.avatar-menu />
        </div>
    </div>
</header>
