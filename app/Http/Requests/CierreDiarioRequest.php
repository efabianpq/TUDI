<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Cierre del día (CLAUDE.md sección 5.5).
 *
 * Ya no lleva el feedback comida a comida: desde el cierre por comida, lo que
 * se comió se reporta según se come (ReporteComidaController) y cerrar el día
 * solo consolida. Lo único que llega aquí es la confirmación de que se quiere
 * cerrar aun quedando comidas sin reportar — el control de validación de la
 * sección 5.5, que existe para que nadie congele por descuido un día con menos
 * calorías de las que comió.
 */
class CierreDiarioRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'confirmar_sin_reportar' => ['nullable', 'boolean'],
        ];
    }
}
