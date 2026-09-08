<?php

namespace App\Http\Requests;

use App\Services\MealPlanGeneratorService;
use App\Services\RepartoComidasService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Reparto de calorías entre las tres comidas de un día (CLAUDE.md sección 5.14).
 *
 * Llega en porcentajes enteros porque es lo que se teclea; la conversión a
 * proporción y la última palabra sobre si el reparto es válido las tiene
 * RepartoComidasService, que también lo valida fuera de HTTP. Aquí se valida
 * igualmente para que el error salga junto al campo y no como un 500.
 */
class RepartoComidasRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $minimo = (int) (RepartoComidasService::PROPORCION_MINIMA * 100);

        $reglas = [
            'reparto' => ['required', 'array'],
            // "Guardar como mi reparto habitual": lo adopta también el perfil,
            // para los días que se creen a partir de ahora.
            'como_habitual' => ['nullable', 'boolean'],
        ];

        foreach (array_keys(MealPlanGeneratorService::DISTRIBUCION_COMIDAS) as $tipoComida) {
            $reglas["reparto.{$tipoComida}"] = ['required', 'integer', "min:{$minimo}", 'max:100'];
        }

        return $reglas;
    }

    /**
     * Los tres porcentajes tienen que sumar 100: si no, el día repartiría más o
     * menos calorías de las que tiene por objetivo.
     */
    public function after(): array
    {
        return [
            function (Validator $validador): void {
                if ($validador->errors()->hasAny(['reparto', 'reparto.desayuno', 'reparto.almuerzo', 'reparto.cena'])) {
                    return;
                }

                $suma = array_sum(array_map('intval', (array) $this->input('reparto', [])));

                if ($suma !== 100) {
                    $validador->errors()->add(
                        'reparto',
                        __('Los tres porcentajes suman :suma%. Tienen que sumar 100%.', ['suma' => $suma]),
                    );
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $minimo = (int) (RepartoComidasService::PROPORCION_MINIMA * 100);

        return [
            'reparto.*.required' => 'Indica el porcentaje de las tres comidas.',
            'reparto.*.integer' => 'Usa porcentajes enteros, sin decimales.',
            'reparto.*.min' => "Cada comida necesita al menos el {$minimo}% del día.",
            'reparto.*.max' => 'Ninguna comida puede llevarse más del 100% del día.',
        ];
    }
}
