{{--
    Popup de dictado por voz (CLAUDE.md sección 4.21).

    Antes el único indicio de que el micrófono estaba abierto era que el botón
    parpadeaba, y en iOS ni eso: parecía grabar y nunca escribía nada. Ahora la
    grabación es una pantalla explícita, con el tiempo transcurrido, lo que se
    va reconociendo, y los dos botones para terminarla o descartarla.

    Vive en el layout, así que hay una sola instancia por página.
--}}
<div id="tudi-dictado" class="tudi-overlay" hidden role="dialog" aria-modal="true" aria-labelledby="tudi-dictado-titulo">
    <div class="tudi-panel w-full max-w-xs text-center">
        <span class="tudi-mic mx-auto" aria-hidden="true">
            <svg class="h-9 w-9" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 15a3 3 0 0 0 3-3V6a3 3 0 1 0-6 0v6a3 3 0 0 0 3 3Zm7-3a7 7 0 0 1-14 0m7 7v3" />
            </svg>
        </span>

        <p id="tudi-dictado-titulo" class="mt-5 text-lg font-semibold tracking-tudi-title text-tudi-on-dark">
            {{ __('Te escucho…') }}
        </p>

        <p class="tudi-num mt-1 text-tudi-lime" data-dictado-tiempo>0:00</p>

        {{-- Lo que se va reconociendo, cuando el navegador lo entrega en vivo. --}}
        <p class="mt-3 min-h-[3.5em] text-sm text-tudi-on-dark-2" data-dictado-parcial aria-live="polite">
            {{ __('Di en voz alta qué tienes para esta comida.') }}
        </p>

        <div class="mt-5 flex gap-2">
            <button type="button" data-dictado-cancelar
                    class="tudi-btn flex-1 border border-tudi-dark-4 bg-transparent text-tudi-on-dark-2">
                {{ __('Cancelar') }}
            </button>
            <button type="button" data-dictado-listo class="tudi-btn tudi-btn-lime flex-1">
                {{ __('Listo') }}
            </button>
        </div>
    </div>
</div>
