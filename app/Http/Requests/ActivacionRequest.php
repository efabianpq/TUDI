<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Código de activación que el usuario introduce (CLAUDE.md sección 4.26).
 *
 * Aquí solo se valida la forma; si el código es el correcto lo decide
 * CuentaService, que lo compara en tiempo constante.
 */
class ActivacionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * El código se dicta y se copia y pega: llega con espacios y en minúsculas
     * tan a menudo como bien escrito.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'codigo' => mb_strtoupper(str_replace([' ', '-'], '', (string) $this->input('codigo'))),
        ]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'codigo' => ['required', 'string', 'size:8'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'codigo.required' => 'Escribe el código que te entregó el administrador.',
            'codigo.size' => 'El código de activación tiene 8 caracteres.',
        ];
    }
}
