<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\NormalizaDecimales;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ProfileParametersRequest extends FormRequest
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
        $this->normalizarDecimales([
            'peso_kg',
            'estatura_m',
            'valor_deficit',
            'proteina_factor',
            'grasa_factor',
        ]);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'peso_kg' => ['required', 'numeric', 'min:1', 'max:999.99'],
            'estatura_m' => ['required', 'numeric', 'min:0.5', 'max:2.99'],
            'edad' => ['required', 'integer', 'min:1', 'max:255'],
            'sexo' => ['required', 'in:masculino,femenino'],
            'nivel_actividad' => ['required', 'numeric', 'min:1.2', 'max:1.725'],
            'tipo_deficit' => ['required', 'in:porcentaje,fijo'],
            'valor_deficit' => ['required', 'numeric', 'min:0', 'max:9999.99'],
            'proteina_factor' => ['required', 'numeric', 'min:1.6', 'max:2.2'],
            'grasa_factor' => ['required', 'numeric', 'min:0.6', 'max:1.0'],
        ];
    }
}
