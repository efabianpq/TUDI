/**
 * Dictado por voz (CLAUDE.md sección 5.9).
 *
 * ── Reconocimiento nativo, sin coste ────────────────────────────────────────
 *
 * El único camino normal es la **Web Speech API del propio navegador**: quien
 * reconoce la voz es el sistema operativo (Windows, Android, macOS e iOS lo
 * traen de fábrica), el audio no sale del dispositivo y no cuesta ninguna
 * llamada a un proveedor.
 *
 * **iOS también entra por aquí.** Safari sí soporta `webkitSpeechRecognition`,
 * pero ignora `continuous = true`: corta la sesión sola en cuanto detecta una
 * pausa y dispara `end` con lo poco que llevara. Antes eso se leía como "en
 * iOS no funciona" y se caía al plan B de servidor. La solución nativa es
 * reconocer en tramos y **reengancharlos**: `continuous = false` y, al recibir
 * `end`, arrancar otra sesión mientras el usuario no haya pulsado "Listo". El
 * texto definitivo se va acumulando entre tramos, así que el usuario dicta
 * seguido y no nota el corte.
 *
 * ── El plan B, apagado por defecto ──────────────────────────────────────────
 *
 * Grabar con MediaRecorder y transcribir en el servidor sigue implementado,
 * pero solo se ofrece si el despliegue lo enciende a propósito
 * (`TRANSCRIPCION_FALLBACK_SERVIDOR=true`): cada dictado sería una llamada
 * facturable al proveedor y ocuparía un worker de PHP-FPM mientras dura. Sin
 * él, la ruta `/transcribir` ni siquiera se anuncia en el HTML.
 *
 * Si el navegador no puede con el reconocimiento nativo y el plan B está
 * apagado, el botón del micrófono queda oculto y el usuario escribe a mano.
 */

import { mostrarCargando, ocultarCargando } from './cargando';

/** Tope de grabación: pasado esto se cierra sola y se transcribe lo grabado. */
const SEGUNDOS_MAXIMOS = 60;

/**
 * Cuántos tramos seguidos sin reconocer nada aceptamos antes de rendirnos.
 * Sin este tope, un micrófono mudo reengancharía sesiones para siempre.
 */
const TRAMOS_MUDOS_MAXIMOS = 3;

/** Contenedores que pedimos a MediaRecorder, del preferido al aceptable. */
const FORMATOS = [
    { mime: 'audio/webm;codecs=opus', extension: 'webm' },
    { mime: 'audio/webm', extension: 'webm' },
    { mime: 'audio/mp4', extension: 'mp4' },
    { mime: 'audio/aac', extension: 'aac' },
];

/**
 * Safari corta la sesión de reconocimiento por su cuenta en cada pausa, así
 * que allí hay que reenganchar tramos (ver la cabecera del archivo). No es una
 * exclusión: iOS usa el mismo reconocimiento nativo que los demás.
 */
function necesitaReenganche() {
    return /iphone|ipad|ipod/i.test(navigator.userAgent)
        || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1)
        || (/safari/i.test(navigator.userAgent) && ! /chrome|chromium|edg/i.test(navigator.userAgent));
}

function ClaseDeReconocimiento() {
    return window.SpeechRecognition || window.webkitSpeechRecognition;
}

function hayReconocimiento() {
    return Boolean(ClaseDeReconocimiento());
}

/**
 * El plan B solo existe si el despliegue lo encendió: sin la meta con la ruta,
 * no hay a dónde mandar el audio y no se gasta ninguna llamada al proveedor.
 */
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

// ── Reconocimiento nativo del navegador ─────────────────────────────────────

/**
 * Dicta usando el reconocedor del sistema operativo.
 *
 * En los navegadores que respetan `continuous = true` (Chrome y Edge, de
 * escritorio y de Android) es una sola sesión de principio a fin. En Safari,
 * que la corta en cada pausa, se reenganchan tramos hasta que el usuario pulsa
 * "Listo" — de ahí `definitivo`, que sobrevive entre tramos, y el tope de
 * tramos mudos, que evita reenganchar para siempre con un micrófono callado.
 */
function dictarConElNavegador(campo) {
    const porTramos = necesitaReenganche();

    let definitivo = '';
    // Lo que el reconocedor todavía no ha confirmado como definitivo. En
    // iOS Safari, pulsar "Listo" no siempre dispara el evento 'end' a
    // tiempo (a veces no llega nunca si el navegador estaba a mitad de un
    // reenganche), así que no basta con esperarlo: hay que insertar con lo
    // que se tenga en ese instante, confirmado o no.
    let parcialActual = '';
    let terminado = false;
    let cancelado = false;
    let cerrado = false;
    let huboAlgo = false;
    let tramosMudos = 0;
    let reconocimiento = null;

    const cerrarConTexto = () => {
        if (cerrado) {
            return;
        }

        cerrado = true;
        popup.cerrar();

        const texto = huboAlgo ? `${definitivo} ${parcialActual}`.trim() : '';

        if (texto) {
            insertarTexto(campo, texto);
        }
    };

    const nuevaSesion = () => {
        const sesion = new (ClaseDeReconocimiento())();
        sesion.lang = 'es-CO';
        sesion.interimResults = true;
        // Safari ignora `true` y corta igual; pedirle `false` deja explícito
        // que aquí el que mantiene la continuidad es el reenganche.
        sesion.continuous = ! porTramos;

        let huboEnEsteTramo = false;

        sesion.addEventListener('result', (evento) => {
            let provisional = '';

            for (let i = evento.resultIndex; i < evento.results.length; i += 1) {
                const trozo = evento.results[i][0].transcript;

                if (evento.results[i].isFinal) {
                    // Entre tramos hace falta el espacio: cada sesión empieza
                    // su transcripción de cero y no sabe qué se dijo antes.
                    definitivo = definitivo ? `${definitivo.trim()} ${trozo.trim()}` : trozo;
                } else {
                    provisional += trozo;
                }
            }

            huboAlgo = true;
            huboEnEsteTramo = true;
            tramosMudos = 0;
            parcialActual = provisional;
            popup.parcial(`${definitivo} ${provisional}`.trim());
        });

        sesion.addEventListener('error', (evento) => {
            // Una vez que el usuario pulsó "Listo" o "Cancelar" ya no importa lo
            // que le pase a esta sesión: el texto (si lo había) ya se insertó
            // o se descartó.
            if (terminado || cancelado) {
                return;
            }

            // "no-speech" y "aborted" son el final normal de un tramo en
            // Safari, no un fallo: se dejan para que `end` decida.
            if (evento.error === 'no-speech' || evento.error === 'aborted') {
                return;
            }

            terminado = true;
            cancelado = true;
            popup.cerrar();

            if (evento.error === 'not-allowed' || evento.error === 'service-not-allowed') {
                avisar('No se pudo usar el micrófono. Revisa el permiso del navegador o escríbelo a mano.');

                return;
            }

            // El reconocedor del sistema no está disponible (sin red en algunos
            // Android, servicio de voz desactivado). Solo si el despliegue
            // encendió el plan B se intenta transcribir en el servidor.
            if (hayGrabacion()) {
                dictarConElServidor(campo);

                return;
            }

            avisar('Tu navegador no pudo reconocer la voz. Escríbelo a mano.');
        });

        sesion.addEventListener('end', () => {
            if (terminado || cancelado) {
                if (! cancelado) {
                    cerrarConTexto();
                }

                return;
            }

            if (! porTramos) {
                terminado = true;
                cerrarConTexto();

                return;
            }

            if (! huboEnEsteTramo) {
                tramosMudos += 1;
            }

            if (tramosMudos >= TRAMOS_MUDOS_MAXIMOS) {
                terminado = true;

                if (! huboAlgo) {
                    popup.cerrar();
                    avisar('No se escuchó nada. Acerca el micrófono y vuelve a intentarlo.');

                    return;
                }

                cerrarConTexto();

                return;
            }

            // Reenganche: otro tramo, conservando lo dictado hasta ahora.
            reconocimiento = nuevaSesion();
            arrancar(reconocimiento);
        });

        return sesion;
    };

    const arrancar = (sesion) => {
        try {
            sesion.start();
        } catch {
            // `start()` sobre una sesión que el navegador todavía no cerró:
            // se reintenta en el siguiente tick en vez de perder el dictado.
            setTimeout(() => {
                if (terminado || cancelado) {
                    return;
                }

                try {
                    sesion.start();
                } catch {
                    terminado = true;
                    popup.cerrar();

                    if (hayGrabacion()) {
                        dictarConElServidor(campo);
                    }
                }
            }, 250);
        }
    };

    popup.abrir({
        alTerminar: () => {
            terminado = true;
            cerrarConTexto();

            try {
                reconocimiento?.stop();
            } catch {
                // Ya se insertó el texto; parar el micrófono es un extra.
            }
        },
        alCancelar: () => {
            terminado = true;
            cancelado = true;
            reconocimiento?.abort();
            popup.cerrar();
        },
    });

    reconocimiento = nuevaSesion();
    arrancar(reconocimiento);
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
