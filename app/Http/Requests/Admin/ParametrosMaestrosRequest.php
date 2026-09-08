<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\Concerns\NormalizaDecimales;
use App\Services\ParametrosMaestrosService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Parámetros maestros que llegan del formulario de la consola (CLAUDE.md
 * sección 4.27).
 *
 * Las reglas se construyen a partir del catálogo, que es la única declaración
 * de qué parámetros existen: añadir uno al catálogo lo valida aquí sin tocar
 * este archivo. El servicio vuelve a comprobar los rangos al guardar, porque es
 * el único camino de escritura y también se usa fuera de HTTP.
 */
class ParametrosMaestrosRequest extends FormRequest
{
    use NormalizaDecimales;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->normalizarDecimales(array_map(
            fn (string $clave): string => 'parametros.'.$clave,
            array_keys(ParametrosMaestrosService::CATALOGO),
        ));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $reglas = ['parametros' => ['required', 'array']];

        foreach (ParametrosMaestrosService::CATALOGO as $clave => $definicion) {
            $reglas["parametros.{$clave}"] = [
                'required',
                $definicion['tipo'] === ParametrosMaestrosService::TIPO_ENTERO ? 'integer' : 'numeric',
                'min:'.$definicion['min'],
                'max:'.$definicion['max'],
            ];
        }

        return $reglas;
    }

    /**
     * Los nombres de los parámetros en los mensajes de error: sin esto Laravel
     * escribiría "parametros.recomendaciones.ajuste kcal".
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        $nombres = [];

        foreach (ParametrosMaestrosService::CATALOGO as $clave => $definicion) {
            $nombres["parametros.{$clave}"] = mb_strtolower($definicion['etiqueta']);
        }

        return $nombres;
    }
}
