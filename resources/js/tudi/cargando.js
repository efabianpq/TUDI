/**
 * Overlay de "estamos procesando" (CLAUDE.md sección 4.19).
 *
 * Las acciones que dependen del proveedor de IA ("Generar distribución",
 * "Rehacer", "Cerrar mi día") tardan varios segundos y hasta ahora no daban
 * ninguna señal: el usuario volvía a pulsar el botón y gastaba otra llamada.
 * Cualquier formulario que declare `data-cargando="Mensaje"` levanta este
 * overlay al enviarse y bloquea su propio botón.
 *
 * Las pistas rotan para que una espera larga no parezca una pantalla colgada;
 * son las que declara el formulario en `data-cargando-pistas` (separadas por
 * "|"), o unas genéricas si no declara ninguna.
 */

const PISTAS_POR_DEFECTO = [
    'Esto puede tardar unos segundos.',
    'No cierres ni recargues la página.',
];

const MS_ENTRE_PISTAS = 3500;

let temporizador = null;

function overlay() {
    return document.getElementById('tudi-cargando');
}

/**
 * @param {string} titulo
 * @param {string[]} pistas
 */
export function mostrarCargando(titulo, pistas) {
    const caja = overlay();

    if (! caja) {
        return;
    }

    const listaDePistas = pistas && pistas.length ? pistas : PISTAS_POR_DEFECTO;

    caja.querySelector('[data-cargando-titulo]').textContent = titulo;

    const destino = caja.querySelector('[data-cargando-pista]');
    let indice = 0;

    destino.textContent = listaDePistas[0];
    caja.hidden = false;

    clearInterval(temporizador);
    temporizador = setInterval(() => {
        indice = (indice + 1) % listaDePistas.length;
        destino.textContent = listaDePistas[indice];
    }, MS_ENTRE_PISTAS);
}

export function ocultarCargando() {
    const caja = overlay();

    clearInterval(temporizador);
    temporizador = null;

    if (caja) {
        caja.hidden = true;
    }
}

/**
 * Lee la configuración del overlay declarada por el formulario (o por el botón
 * concreto que se pulsó, que puede pedir un mensaje distinto al del formulario).
 *
 * @param {HTMLFormElement} formulario
 * @param {Element|null} enviador
 * @returns {{titulo: string, pistas: string[]}|null}
 */
export function configuracionDeCarga(formulario, enviador) {
    const fuente = enviador?.dataset?.cargando ? enviador : formulario;
    const titulo = fuente?.dataset?.cargando;

    if (! titulo) {
        return null;
    }

    const pistas = (fuente.dataset.cargandoPistas || '')
        .split('|')
        .map((pista) => pista.trim())
        .filter(Boolean);

    return { titulo, pistas };
}

/**
 * Deshabilita los botones de envío del formulario mientras dura la petición,
 * para que un segundo clic no dispare otra llamada al proveedor.
 *
 * @param {HTMLFormElement} formulario
 * @param {boolean} bloqueado
 */
export function bloquearEnvios(formulario, bloqueado) {
    formulario.querySelectorAll('button[type="submit"], input[type="submit"]').forEach((boton) => {
        boton.disabled = bloqueado;
    });
}

/**
 * Los formularios que se envían de forma normal (con recarga) solo necesitan
 * levantar el overlay: la navegación se encarga del resto. Los que se envían
 * por fetch lo gestionan ellos mismos, así que se excluyen aquí.
 */
export function iniciarCargando() {
    document.addEventListener('submit', (evento) => {
        const formulario = evento.target;

        if (! (formulario instanceof HTMLFormElement) || formulario.hasAttribute('data-fetch') || evento.defaultPrevented) {
            return;
        }

        const configuracion = configuracionDeCarga(formulario, evento.submitter);

        if (! configuracion) {
            return;
        }

        mostrarCargando(configuracion.titulo, configuracion.pistas);

        // El bloqueo va en el siguiente tick: deshabilitar el botón dentro del
        // propio evento `submit` puede hacer que el navegador no incluya su
        // name/value en la petición (y "Rehacer" lo necesita).
        setTimeout(() => bloquearEnvios(formulario, true), 0);
    });

    // Al volver con el botón "atrás" la página puede restaurarse del bfcache
    // con el overlay puesto y los botones bloqueados.
    window.addEventListener('pageshow', (evento) => {
        if (evento.persisted) {
            ocultarCargando();
            document.querySelectorAll('form').forEach((formulario) => bloquearEnvios(formulario, false));
        }
    });
}
