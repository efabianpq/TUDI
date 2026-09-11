<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Cierre de UNA comida (CLAUDE.md sección 5.5): qué se comió de verdad en el
 * desayuno, el almuerzo o la cena.
 *
 * Todo es opcional aquí y ninguna combinación se rechaza en la validación: cuál
 * de los caminos manda —texto, comida frecuente o "cumplí lo sugerido"— y qué
 * pasa si no llega ninguno lo decide ReporteComidaService, que es quien reporta
 * también fuera de HTTP. La regla del proyecto es que el Form Request valida
 * forma y tamaño; el criterio vive en el servicio (sección 9).
 */
class ReporteComidaRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'cumplio' => ['nullable', 'boolean'],
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
            'texto.max' => 'Resume en 1000 caracteres o menos lo que comiste.',
            'imagen.image' => 'La evidencia de la comida tiene que ser una imagen.',
            'imagen.max' => 'La imagen de evidencia debe pesar 4 MB o menos.',
        ];
    }
}
