<?php

namespace App\Http\Requests\Admin;

use App\Services\RecursosDidacticosService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Publicación de los recursos didácticos desde la consola (CLAUDE.md sección
 * 5.15). Todo es opcional: guardar sin tocar nada deja lo que ya hubiera
 * publicado, y vaciar el campo de la URL retira el video.
 */
class RecursosDidacticosRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'calculadora_video_url' => ['nullable', 'string', 'url', 'max:2048'],
            'calculadora_guia_pdf' => ['nullable', 'file', 'mimes:pdf', 'max:'.RecursosDidacticosService::PDF_MAX_KB],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'calculadora_video_url.url' => 'El enlace del video no parece una dirección válida.',
            'calculadora_guia_pdf.mimes' => 'La guía tiene que ser un archivo PDF.',
            'calculadora_guia_pdf.max' => 'La guía debe pesar '.(int) (RecursosDidacticosService::PDF_MAX_KB / 1024).' MB o menos.',
        ];
    }
}
