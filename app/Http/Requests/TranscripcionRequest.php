<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Audio dictado que llega desde el navegador para transcribir (CLAUDE.md
 * sección 4.21).
 *
 * El formato lo elige MediaRecorder según el navegador (webm/opus en Chrome y
 * Firefox, mp4/aac en Safari de iOS), así que se aceptan los contenedores que
 * esos navegadores producen en la práctica y que la Gemini API admite.
 */
class TranscripcionRequest extends FormRequest
{
    /**
     * Tipos MIME que puede llegar a producir MediaRecorder y que el proveedor
     * acepta. Se valida el contenido real del archivo (`mimetypes`), no la
     * extensión que declare el cliente.
     *
     * @var array<int, string>
     */
    public const MIMES_ACEPTADOS = [
        'audio/webm',
        'video/webm',
        'audio/ogg',
        'audio/mp4',
        'video/mp4',
        'audio/mpeg',
        'audio/aac',
        'audio/x-m4a',
        'audio/wav',
        'audio/x-wav',
    ];

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // 8 MB cubre de sobra un dictado de un minuto en cualquiera de los
            // códecs de arriba, y acota lo que un cliente puede llegar a subir.
            'audio' => ['required', 'file', 'max:8192', 'mimetypes:'.implode(',', self::MIMES_ACEPTADOS)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'audio.required' => 'No llegó ninguna grabación.',
            'audio.max' => 'La grabación es demasiado larga. Dicta frases más cortas.',
            'audio.mimetypes' => 'El formato de audio de tu navegador no está soportado. Escríbelo a mano.',
        ];
    }
}
