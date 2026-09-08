{{--
    Overlay de "estamos procesando" (CLAUDE.md sección 4.19).

    Vive en el layout, así que existe una sola vez por página y cualquier
    formulario lo puede levantar declarando `data-cargando="…"`. El texto y la
    lista de pistas los escribe JavaScript al abrirlo; lo que va aquí es solo el
    valor por defecto para el caso en que un formulario no declare ninguno.
--}}
<div id="tudi-cargando" class="tudi-overlay" hidden role="alertdialog" aria-modal="true"
     aria-labelledby="tudi-cargando-titulo" aria-describedby="tudi-cargando-pista">
    <div class="tudi-panel w-full max-w-xs text-center">
        <div class="tudi-spinner mx-auto" aria-hidden="true"></div>

        <p id="tudi-cargando-titulo" data-cargando-titulo
           class="mt-5 text-lg font-semibold tracking-tudi-title text-tudi-on-dark">
            {{ __('Procesando…') }}
        </p>

        {{-- Las pistas rotan cada pocos segundos para que una espera larga no parezca colgada. --}}
        <p id="tudi-cargando-pista" data-cargando-pista class="tudi-meta mt-2 min-h-[2.5em]" aria-live="polite"></p>
    </div>
</div>
