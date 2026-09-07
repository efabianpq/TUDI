<?php

namespace App\Http\Requests;

use App\Services\MealPlanGeneratorService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Valida los textos libres de ingredientes del día antes de mandárselos al
 * motor de distribución (CLAUDE.md sección 4.12).
 *
 * Llegan las tres comidas de una vez porque hay un único botón "Generar
 * distribución": ninguna es obligatoria por separado, pero al menos una tiene
 * que traer algo escrito.
 *
 * Solo valida forma y tamaño: no intenta comprobar que el texto describa
 * alimentos de verdad — de eso se encarga el propio modelo, que devuelve un
 * aviso legible cuando no reconoce ninguno.
 */
class DistribucionDiaRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $tiposDeComida = array_keys(MealPlanGeneratorService::DISTRIBUCION_COMIDAS);

        $reglas = [
            'ingredientes' => ['required', 'array'],
            // "Rehacer solo esta comida": fuerza a regenerar una comida cuyo
            // texto no ha cambiado. Opcional; sin él solo se generan las
            // comidas nuevas o cuyo texto se editó.
            'rehacer' => ['nullable', 'string', Rule::in($tiposDeComida)],
        ];

        foreach ($tiposDeComida as $tipoComida) {
            // El máximo acota lo que se envía al proveedor: 2000 caracteres
            // son de sobra para describir los alimentos de una comida.
            $reglas["ingredientes.{$tipoComida}"] = ['nullable', 'string', 'min:3', 'max:2000'];
        }

        return $reglas;
    }

    /**
     * Al menos una comida tiene que traer texto: generar el día entero en
     * blanco no tendría nada que repartir.
     */
    public function after(): array
    {
        return [
            function (Validator $validador): void {
                $textos = array_filter(
                    (array) $this->input('ingredientes', []),
                    fn ($texto): bool => is_string($texto) && trim($texto) !== '',
                );

                if ($textos === []) {
                    $validador->errors()->add(
                        'ingredientes',
                        __('Escribe o dicta qué tienes para al menos una de las comidas.'),
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
        return [
            'ingredientes.required' => 'Escribe o dicta qué tienes para al menos una de las comidas.',
            'ingredientes.*.min' => 'Describe con un poco más de detalle los ingredientes de esa comida.',
            'ingredientes.*.max' => 'El texto es demasiado largo: resume los ingredientes de cada comida en 2000 caracteres o menos.',
        ];
    }
}
