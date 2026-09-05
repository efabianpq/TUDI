<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ComidaRealRequest extends FormRequest
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
            'calorias_reales' => ['required', 'numeric', 'min:0'],
            'proteina_g' => ['required', 'numeric', 'min:0'],
            'grasa_g' => ['required', 'numeric', 'min:0'],
            'carbohidratos_g' => ['required', 'numeric', 'min:0'],
            'imagen' => ['nullable', 'image', 'max:4096'],
            'notas' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
