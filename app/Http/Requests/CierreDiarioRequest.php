<?php

namespace App\Http\Requests;

use App\Services\MealPlanGeneratorService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Feedback de cumplimiento que acompaña al cierre del día (CLAUDE.md sección
 * 4.16): por cada comida planificada, o bien "sí, lo cumplí", o bien un texto
 * contando qué se comió realmente.
 *
 * Todo es opcional: cerrar el día sin decir nada sigue siendo válido — las
 * comidas sin registrar simplemente no suman calorías consumidas.
 */
class CierreDiarioRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $reglas = ['feedback' => ['nullable', 'array']];

        foreach (array_keys(MealPlanGeneratorService::DISTRIBUCION_COMIDAS) as $tipoComida) {
            $reglas["feedback.{$tipoComida}"] = ['nullable', 'array'];
            $reglas["feedback.{$tipoComida}.cumplio"] = ['nullable', 'boolean'];
            // Mismo tope que las notas de ComidaReal (sección 4.3): es el sitio
            // donde acaba guardado este texto.
            $reglas["feedback.{$tipoComida}.texto"] = ['nullable', 'string', 'max:1000'];
        }

        return $reglas;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'feedback.*.texto.max' => 'Resume en 1000 caracteres o menos lo que comiste en cada comida.',
        ];
    }
}
