/**
 * Guardado sin recargar la página y acordeón de comidas (CLAUDE.md sección
 * 4.18), extraídos de la vista del plan diario para poder compartirlos.
 *
 * El formulario marcado `data-fetch` se manda por fetch; la respuesta del
 * redirect trae la página ya recalculada y solo se reemplazan los trozos que
 * declara `data-fetch-secciones`. Sin JavaScript —o ante cualquier fallo— el
 * mismo formulario se envía de forma normal, así que no hay ningún endpoint
 * JSON nuevo que mantener.
 */

import { bloquearEnvios, configuracionDeCarga, mostrarCargando, ocultarCargando } from './cargando';
import { conectarDictado } from './dictado';

const SECCIONES_POR_DEFECTO = '#tudi-avisos,#panel-objetivo,#lista-comidas,#seccion-actividad,#seccion-cierre';

/**
 * Acordeón: una sola comida abierta a la vez. Cada tarjeta lleva dentro todo lo
 * de esa comida —los ingredientes, lo planificado y su cierre (CLAUDE.md
 * sección 5.5)—, así que abrir una y cerrar el resto es exactamente lo que hace
 * falta para no tener media pantalla de formularios a la vez.
 *
 * El evento \`toggle\` no burbujea, así que se escucha en fase de captura.
 */
function iniciarAcordeon() {
    document.addEventListener('toggle', (evento) => {
        const detalle = evento.target;

        if (! detalle.matches?.('details[data-comida]') || ! detalle.open) {
            return;
        }

        document.querySelectorAll('details[data-comida]').forEach((otro) => {
            if (otro !== detalle) {
                otro.open = false;
            }
        });
    }, true);
}

function comidaAbierta() {
    return document.querySelector('details[data-comida][open]')?.dataset.comida || null;
}

function restaurarComidaAbierta(tipo) {
    if (! tipo) {
        return;
    }

    document.querySelectorAll('details[data-comida]').forEach((detalle) => {
        detalle.open = detalle.dataset.comida === tipo;
    });
}
function iniciarEnvioPorFetch() {
    document.addEventListener('submit', async (evento) => {
        const formulario = evento.target;

        if (! (formulario instanceof HTMLFormElement)
            || ! formulario.hasAttribute('data-fetch')
            || ! window.fetch
            || ! window.DOMParser) {
            return;
        }

        evento.preventDefault();

        const datos = new FormData(formulario);
        // `submitter` no existe en navegadores viejos: sin él se perdería el
        // valor de "Rehacer".
        const enviador = evento.submitter || document.activeElement;

        if (enviador?.name && enviador.form === formulario) {
            datos.append(enviador.name, enviador.value);
        }

        const abierta = comidaAbierta();
        const carga = configuracionDeCarga(formulario, enviador);

        if (carga) {
            mostrarCargando(carga.titulo, carga.pistas);
        }

        bloquearEnvios(formulario, true);
        document.body.setAttribute('aria-busy', 'true');

        try {
            const respuesta = await fetch(formulario.action, {
                method: 'POST',
                body: datos,
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'text/html' },
            });

            if (! respuesta.ok) {
                throw new Error('respuesta no válida');
            }

            const recibido = new DOMParser().parseFromString(await respuesta.text(), 'text/html');
            const secciones = (formulario.dataset.fetchSecciones || SECCIONES_POR_DEFECTO).split(',');

            secciones.forEach((selector) => {
                const nuevo = recibido.querySelector(selector.trim());
                const actual = document.querySelector(selector.trim());

                if (nuevo && actual) {
                    actual.replaceWith(nuevo);
                }
            });

            restaurarComidaAbierta(abierta);
            conectarDictado(document);
        } catch {
            // Cualquier problema: se envía como un formulario normal.
            formulario.removeAttribute('data-fetch');
            formulario.submit();

            return;
        } finally {
            document.body.removeAttribute('aria-busy');
            ocultarCargando();
        }

        bloquearEnvios(formulario, false);
    });
}

export function iniciarFormularios() {
    iniciarAcordeon();
    iniciarEnvioPorFetch();
}
