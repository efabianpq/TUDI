<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ActividadFisicaRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'tipo_actividad' => ['required', 'string', 'max:255'],
            'duracion_min' => ['required', 'integer', 'min:1'],
            'calorias_dispositivo' => ['required', 'numeric', 'min:0'],
            'pasos' => ['nullable', 'integer', 'min:0'],
            'fuente' => ['required', 'string', 'in:manual,dispositivo'],
        ];
    }
}
