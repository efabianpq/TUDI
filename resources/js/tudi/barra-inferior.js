/**
 * La barra inferior de móvil se retira mientras se escribe (CLAUDE.md 5.12).
 *
 * ── Por qué hace falta ──────────────────────────────────────────────────────
 *
 * `.tudi-tabbar` es `position: fixed; bottom: 0`, y eso la ancla al viewport de
 * maquetación, no a la pantalla. Cuando el teclado del sistema se abre, Chrome
 * en Android encoge ese viewport, así que la barra sube con él y se queda
 * flotando a media pantalla: se ve "despegada" justo en el plan diario, que es
 * la única pantalla llena de campos (ingredientes, peso, actividad, reportes).
 *
 * Taparlo con `interactive-widget` no sirve: `resizes-visual` la dejaría fija
 * pero DETRÁS del teclado, y `resizes-content` es justo el comportamiento que
 * la sube. La única salida honesta es que no esté: mientras se escribe, tres
 * destinos de navegación no aportan nada y sí roban alto útil.
 *
 * Se detecta por foco y no por la altura del `visualViewport` porque un teclado
 * físico (tablet con funda) no cambia la altura y aun así conviene el mismo
 * comportamiento; y porque `focusin`/`focusout` burbujean, así que un campo que
 * aparezca después —el reporte de una comida, el peso del día— queda cubierto
 * sin volver a registrar nada.
 */
const SELECTOR_CAMPOS = 'input:not([type="checkbox"]):not([type="radio"]):not([type="file"]), textarea, select';

export function iniciarBarraInferior() {
    const raiz = document.documentElement;

    document.addEventListener('focusin', (evento) => {
        if (evento.target.matches?.(SELECTOR_CAMPOS)) {
            raiz.classList.add('tudi-escribiendo');
        }
    });

    document.addEventListener('focusout', () => {
        /*
         * En el siguiente tick, no en este: al saltar de un campo a otro el
         * `focusout` del primero llega ANTES del `focusin` del segundo, y sin
         * esperar la barra reaparecería un instante entre campo y campo.
         */
        setTimeout(() => {
            if (!document.activeElement?.matches?.(SELECTOR_CAMPOS)) {
                raiz.classList.remove('tudi-escribiendo');
            }
        }, 0);
    });
}
