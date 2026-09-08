{{--
    Aviso para instalar TUDI en la pantalla de inicio (CLAUDE.md sección 4.20).

    Mientras la app se abre desde el navegador, la barra de URL y los botones
    del navegador ocupan pantalla y hacen que se perciba como una web. Instalada
    (`display: standalone` del manifest) desaparecen, y desaparecen igual en
    todas las pantallas.

    Nace oculto: JavaScript solo lo muestra si el navegador NO está ya en modo
    standalone y el usuario no lo ha descartado antes.
--}}
<div id="tudi-instalar" hidden
     class="fixed inset-x-0 bottom-0 z-50 px-4 pb-[calc(env(safe-area-inset-bottom)+88px)] sm:hidden">
    <div class="tudi-panel flex items-center gap-3 p-4">
        <x-tudi.isotipo :size="34" inner="var(--tudi-dark)" track="var(--tudi-dark-4)" />

        <div class="min-w-0 flex-1">
            <p class="text-sm font-semibold tracking-tudi-title text-tudi-on-dark">
                {{ __('Añade tudi a tu pantalla de inicio') }}
            </p>
            <p class="tudi-meta mt-0.5" data-instalar-instruccion></p>
        </div>

        <button type="button" data-instalar-aceptar hidden
                class="tudi-btn tudi-btn-lime flex-none px-4 text-sm">
            {{ __('Instalar') }}
        </button>

        <button type="button" data-instalar-cerrar
                class="grid h-11 w-11 flex-none place-items-center rounded-full text-tudi-on-dark-2"
                aria-label="{{ __('Descartar') }}">
            <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="m6 6 12 12M18 6 6 18" />
            </svg>
        </button>
    </div>
</div>
