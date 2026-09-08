/**
 * Aviso de instalación en la pantalla de inicio (CLAUDE.md sección 4.20).
 *
 * En Android/Chrome se puede disparar el instalador nativo con el evento
 * `beforeinstallprompt`; en iOS no existe ese evento y solo cabe explicar el
 * gesto ("Compartir → Añadir a pantalla de inicio"). En los dos casos el aviso
 * desaparece en cuanto la app corre ya en modo standalone.
 */

const CLAVE_DESCARTADO = 'tudi:instalar-descartado';

function yaEstaInstalada() {
    return window.matchMedia('(display-mode: standalone)').matches
        // iOS expone su propia bandera y no soporta display-mode: standalone.
        || window.navigator.standalone === true;
}

function esIos() {
    return /iphone|ipad|ipod/i.test(window.navigator.userAgent)
        // iPadOS se identifica como Mac; el touch lo delata.
        || (window.navigator.platform === 'MacIntel' && window.navigator.maxTouchPoints > 1);
}

export function iniciarInstalar() {
    const aviso = document.getElementById('tudi-instalar');

    if (! aviso || yaEstaInstalada() || localStorage.getItem(CLAVE_DESCARTADO) === '1') {
        return;
    }

    const instruccion = aviso.querySelector('[data-instalar-instruccion]');
    const aceptar = aviso.querySelector('[data-instalar-aceptar]');

    aviso.querySelector('[data-instalar-cerrar]').addEventListener('click', () => {
        aviso.hidden = true;
        localStorage.setItem(CLAVE_DESCARTADO, '1');
    });

    if (esIos()) {
        instruccion.textContent = 'Compartir → Añadir a pantalla de inicio';
        aviso.hidden = false;

        return;
    }

    // Chrome/Edge: se guarda el evento y se dispara el instalador nativo al
    // pulsar "Instalar". Si el navegador nunca lo emite, el aviso no aparece —
    // mejor eso que prometer un botón que no hace nada.
    window.addEventListener('beforeinstallprompt', (evento) => {
        evento.preventDefault();

        instruccion.textContent = 'Se abre a pantalla completa, sin barra de direcciones.';
        aceptar.hidden = false;
        aviso.hidden = false;

        aceptar.addEventListener('click', async () => {
            aviso.hidden = true;
            localStorage.setItem(CLAVE_DESCARTADO, '1');
            evento.prompt();
            await evento.userChoice;
        }, { once: true });
    }, { once: true });

    window.addEventListener('appinstalled', () => {
        aviso.hidden = true;
        localStorage.setItem(CLAVE_DESCARTADO, '1');
    });
}
