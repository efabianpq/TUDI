<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class IngredienteDisponibleRequest extends FormRequest
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
            'ingredientes' => ['required', 'array', 'min:1'],
            'ingredientes.*.nombre' => ['required', 'string', 'max:255'],
            'ingredientes.*.cantidad_g' => ['required', 'numeric', 'gt:0'],
            'ingredientes.*.calorias_por_100g' => ['required', 'numeric', 'min:0'],
            'ingredientes.*.proteina_por_100g' => ['required', 'numeric', 'min:0'],
            'ingredientes.*.grasa_por_100g' => ['required', 'numeric', 'min:0'],
            'ingredientes.*.carbohidratos_por_100g' => ['required', 'numeric', 'min:0'],
        ];
    }
}
