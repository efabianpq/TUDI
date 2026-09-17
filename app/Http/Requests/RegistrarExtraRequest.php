<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Registro de un extra del día (CLAUDE.md sección 5.27): una gaseosa, una
 * cerveza, un postre de media tarde, unas galletas.
 *
 * Mismas reglas que ReporteComidaRequest menos `cumplio`, que aquí no existe: un
 * extra no se planifica, así que no hay nada sugerido que dar por cumplido.
 *
 * Igual que allí, ninguna combinación se rechaza en la validación: qué camino
 * manda —texto o extra frecuente— y qué pasa si no llega ninguno lo decide
 * ReporteComidaService, que es quien registra también fuera de HTTP. El Form
 * Request valida forma y tamaño; el criterio vive en el servicio (sección 9).
 */
class RegistrarExtraRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Mismo tope que las notas de ComidaReal: es donde acaba guardado.
            'texto' => ['nullable', 'string', 'max:1000'],
            // Id de una ComidaReal anterior del propio usuario, para repetirla
            // sin gastar una llamada (sección 5.22). Que sea SUYA lo comprueba
            // ComidasFrecuentesService, no esta regla.
            'repetir' => ['nullable', 'integer', 'min:1'],
            'imagen' => ['nullable', 'image', 'max:4096'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'texto.max' => 'Resume en 1000 caracteres o menos lo que tomaste o picaste.',
            'imagen.image' => 'La evidencia del extra tiene que ser una imagen.',
            'imagen.max' => 'La imagen de evidencia debe pesar 4 MB o menos.',
        ];
    }
}
