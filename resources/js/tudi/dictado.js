/**
 * Dictado por voz (CLAUDE.md sección 4.21).
 *
 * Dos caminos, en este orden:
 *
 *  1. **Web Speech API del navegador** — el audio no sale del dispositivo y no
 *     cuesta ninguna llamada al proveedor. Es el camino preferente.
 *  2. **Grabar y transcribir en el servidor** — MediaRecorder captura el audio
 *     y `POST /transcribir` lo convierte a texto. Es el plan B para Safari de
 *     iOS, donde la Web Speech API existe pero no emite resultados: pedía el
 *     micrófono, parecía grabar y nunca escribía nada (el fallo reportado).
 *
 * Si el navegador no puede hacer ninguna de las dos, el botón del micrófono
 * queda oculto y el usuario escribe a mano, como hasta ahora.
 */

import { mostrarCargando, ocultarCargando } from './cargando';

/** Tope de grabación: pasado esto se cierra sola y se transcribe lo grabado. */
const SEGUNDOS_MAXIMOS = 60;

/** Contenedores que pedimos a MediaRecorder, del preferido al aceptable. */
const FORMATOS = [
    { mime: 'audio/webm;codecs=opus', extension: 'webm' },
    { mime: 'audio/webm', extension: 'webm' },
    { mime: 'audio/mp4', extension: 'mp4' },
    { mime: 'audio/aac', extension: 'aac' },
];

function esIos() {
    return /iphone|ipad|ipod/i.test(navigator.userAgent)
        || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
}

function ClaseDeReconocimiento() {
    return window.SpeechRecognition || window.webkitSpeechRecognition;
}

/**
 * En iOS la clase existe pero no funciona, así que allí se va directo al plan B.
 */
function hayReconocimiento() {
    return Boolean(ClaseDeReconocimiento()) && ! esIos();
}

function hayGrabacion() {
    return Boolean(navigator.mediaDevices?.getUserMedia && window.MediaRecorder && window.fetch)
        && Boolean(rutaDeTranscripcion());
}

function rutaDeTranscripcion() {
    return document.querySelector('meta[name="ruta-transcribir"]')?.content || '';
}

function tokenCsrf() {
    return document.querySelector('meta[name="csrf-token"]')?.content || '';
}

function formatoSoportado() {
    return FORMATOS.find((formato) => window.MediaRecorder.isTypeSupported?.(formato.mime)) || null;
}

// ── El popup ────────────────────────────────────────────────────────────────

const popup = {
    caja: null,
    intervalo: null,
    alTerminar: null,
    alCancelar: null,

    inicializar() {
        this.caja = document.getElementById('tudi-dictado');

        if (! this.caja) {
            return;
        }

        this.caja.querySelector('[data-dictado-listo]')
            .addEventListener('click', () => this.alTerminar?.());
        this.caja.querySelector('[data-dictado-cancelar]')
            .addEventListener('click', () => this.alCancelar?.());
    },

    abrir({ alTerminar, alCancelar }) {
        if (! this.caja) {
            return;
        }

        this.alTerminar = alTerminar;
        this.alCancelar = alCancelar;
        this.parcial('Di en voz alta qué tienes para esta comida.');
        this.caja.hidden = false;

        const desde = Date.now();
        this.tiempo(0);

        clearInterval(this.intervalo);
        this.intervalo = setInterval(() => {
            const segundos = Math.floor((Date.now() - desde) / 1000);
            this.tiempo(segundos);

            if (segundos >= SEGUNDOS_MAXIMOS) {
                this.alTerminar?.();
            }
        }, 250);
    },

    cerrar() {
        clearInterval(this.intervalo);
        this.intervalo = null;
        this.alTerminar = null;
        this.alCancelar = null;

        if (this.caja) {
            this.caja.hidden = true;
        }
    },

    tiempo(segundos) {
        const destino = this.caja?.querySelector('[data-dictado-tiempo]');

        if (destino) {
            destino.textContent = `${Math.floor(segundos / 60)}:${String(segundos % 60).padStart(2, '0')}`;
        }
    },

    parcial(texto) {
        const destino = this.caja?.querySelector('[data-dictado-parcial]');

        if (destino) {
            destino.textContent = texto;
        }
    },
};

// ── Escritura en el campo ───────────────────────────────────────────────────

function insertarTexto(campo, texto) {
    const limpio = (texto || '').trim();

    if (limpio === '') {
        return;
    }

    campo.value = campo.value.trim() ? `${campo.value.trim()} ${limpio}` : limpio;
    // Alpine y cualquier otro observador del campo tienen que enterarse.
    campo.dispatchEvent(new Event('input', { bubbles: true }));
    campo.dispatchEvent(new Event('change', { bubbles: true }));
}

function avisar(mensaje) {
    const avisos = document.getElementById('tudi-avisos');

    if (! avisos) {
        return;
    }

    const nota = document.createElement('div');
    nota.className = 'tudi-note mb-4';
    nota.setAttribute('role', 'alert');
    nota.innerHTML = '<p></p>';
    nota.querySelector('p').textContent = mensaje;

    avisos.prepend(nota);
    setTimeout(() => nota.remove(), 8000);
}

// ── Camino 1: Web Speech API ────────────────────────────────────────────────

function dictarConElNavegador(campo) {
    const reconocimiento = new (ClaseDeReconocimiento())();
    reconocimiento.lang = 'es-CO';
    reconocimiento.interimResults = true;
    reconocimiento.continuous = true;

    let definitivo = '';
    let cancelado = false;
    let hubo = false;

    reconocimiento.addEventListener('result', (evento) => {
        let provisional = '';

        for (let i = evento.resultIndex; i < evento.results.length; i += 1) {
            const trozo = evento.results[i][0].transcript;

            if (evento.results[i].isFinal) {
                definitivo += trozo;
            } else {
                provisional += trozo;
            }
        }

        hubo = true;
        popup.parcial(`${definitivo}${provisional}`.trim());
    });

    reconocimiento.addEventListener('error', (evento) => {
        cancelado = true;
        popup.cerrar();

        if (evento.error === 'no-speech') {
            avisar('No se escuchó nada. Acerca el micrófono y vuelve a intentarlo.');

            return;
        }

        if (evento.error === 'aborted') {
            return;
        }

        // Permiso denegado, sin micrófono o el servicio de reconocimiento del
        // navegador no está disponible: se reintenta grabando y transcribiendo.
        if (hayGrabacion()) {
            dictarConElServidor(campo);

            return;
        }

        avisar('Tu navegador no pudo usar el micrófono. Escríbelo a mano.');
    });

    reconocimiento.addEventListener('end', () => {
        if (cancelado) {
            return;
        }

        popup.cerrar();

        if (hubo) {
            insertarTexto(campo, definitivo);
        }
    });

    popup.abrir({
        alTerminar: () => reconocimiento.stop(),
        alCancelar: () => {
            cancelado = true;
            reconocimiento.abort();
            popup.cerrar();
        },
    });

    try {
        reconocimiento.start();
    } catch {
        cancelado = true;
        popup.cerrar();

        if (hayGrabacion()) {
            dictarConElServidor(campo);
        }
    }
}

// ── Camino 2: grabar y transcribir en el servidor ───────────────────────────

async function dictarConElServidor(campo) {
    let pista;

    try {
        pista = await navigator.mediaDevices.getUserMedia({ audio: true });
    } catch {
        avisar('No se pudo abrir el micrófono. Revisa el permiso del navegador o escríbelo a mano.');

        return;
    }

    const formato = formatoSoportado();
    const grabadora = new MediaRecorder(pista, formato ? { mimeType: formato.mime } : undefined);
    const trozos = [];
    let cancelado = false;

    const soltarMicrofono = () => pista.getTracks().forEach((canal) => canal.stop());

    grabadora.addEventListener('dataavailable', (evento) => {
        if (evento.data.size > 0) {
            trozos.push(evento.data);
        }
    });

    grabadora.addEventListener('stop', async () => {
        soltarMicrofono();
        popup.cerrar();

        if (cancelado || trozos.length === 0) {
            return;
        }

        await transcribir(campo, new Blob(trozos, { type: grabadora.mimeType || formato?.mime }), formato);
    });

    popup.abrir({
        alTerminar: () => grabadora.state !== 'inactive' && grabadora.stop(),
        alCancelar: () => {
            cancelado = true;

            if (grabadora.state !== 'inactive') {
                grabadora.stop();
            } else {
                soltarMicrofono();
                popup.cerrar();
            }
        },
    });

    popup.parcial('Grabando. Pulsa "Listo" cuando termines.');
    grabadora.start();
}

async function transcribir(campo, audio, formato) {
    mostrarCargando('Transcribiendo lo que dijiste…', ['Esto tarda un par de segundos.']);

    try {
        const datos = new FormData();
        datos.append('audio', audio, `dictado.${formato?.extension || 'webm'}`);

        const respuesta = await fetch(rutaDeTranscripcion(), {
            method: 'POST',
            body: datos,
            credentials: 'same-origin',
            headers: { 'X-CSRF-TOKEN': tokenCsrf(), Accept: 'application/json' },
        });

        const cuerpo = await respuesta.json().catch(() => ({}));

        if (! respuesta.ok) {
            avisar(cuerpo.error || cuerpo.message || 'No se pudo transcribir el audio. Escríbelo a mano.');

            return;
        }

        insertarTexto(campo, cuerpo.texto);
    } catch {
        avisar('No se pudo contactar con el servidor para transcribir. Escríbelo a mano.');
    } finally {
        ocultarCargando();
    }
}

// ── Conexión de los botones ─────────────────────────────────────────────────

/**
 * Revela y conecta los botones de dictado que haya dentro de `raiz`. Es
 * idempotente: se vuelve a llamar cuando el plan diario reemplaza trozos del
 * DOM sin recargar la página.
 *
 * @param {ParentNode} raiz
 */
export function conectarDictado(raiz = document) {
    if (! hayReconocimiento() && ! hayGrabacion()) {
        return;
    }

    raiz.querySelectorAll('[data-boton-dictado]').forEach((boton) => {
        const campo = document.getElementById(boton.dataset.botonDictado);

        if (! campo || boton.dataset.dictadoListo) {
            return;
        }

        boton.dataset.dictadoListo = '1';
        boton.hidden = false;

        boton.addEventListener('click', () => {
            if (hayReconocimiento()) {
                dictarConElNavegador(campo);
            } else {
                dictarConElServidor(campo);
            }
        });
    });
}

export function iniciarDictado() {
    popup.inicializar();
    conectarDictado(document);
}
