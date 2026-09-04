<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateIngredienteDisponibleRequest extends FormRequest
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
            'nombre' => ['required', 'string', 'max:255'],
            'cantidad_g' => ['required', 'numeric', 'gt:0'],
            'calorias_por_100g' => ['required', 'numeric', 'min:0'],
            'proteina_por_100g' => ['required', 'numeric', 'min:0'],
            'grasa_por_100g' => ['required', 'numeric', 'min:0'],
            'carbohidratos_por_100g' => ['required', 'numeric', 'min:0'],
        ];
    }
}
