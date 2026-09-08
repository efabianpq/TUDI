<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\NormalizaDecimales;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class RegistroPesoRequest extends FormRequest
{
    use NormalizaDecimales;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->normalizarDecimales(['peso_kg']);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // Same plausible human range as registros_diarios.peso_kg's decimal(5,2).
            'peso_kg' => ['required', 'numeric', 'min:20', 'max:400'],
        ];
    }
}
