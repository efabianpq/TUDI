import Alpine from 'alpinejs';

import { iniciarCargando } from './tudi/cargando';
import { iniciarDictado } from './tudi/dictado';
import { iniciarFormularios } from './tudi/formularios';
import { iniciarInstalar } from './tudi/instalar';

window.Alpine = Alpine;

Alpine.start();

/**
 * Piezas transversales del rediseño: overlay de "procesando", dictado por voz,
 * guardado sin recargar y aviso de instalación. Todas son idempotentes y no
 * fallan si la página no trae los nodos que buscan, así que se arrancan aquí en
 * vez de repetirse en cada vista.
 */
document.addEventListener('DOMContentLoaded', () => {
    iniciarCargando();
    iniciarDictado();
    iniciarFormularios();
    iniciarInstalar();
});
