<?php

namespace App\Exceptions;

use InvalidArgumentException;

/**
 * Thrown when an input parameter of the nutritional algorithm falls outside the
 * ranges defined in CLAUDE.md sections 5 and 6, or when the deficit type is not
 * one of the supported values.
 */
class InvalidNutritionParameterException extends InvalidArgumentException
{
    public static function outOfRange(string $parametro, float $valor, float $min, float $max): self
    {
        return new self(sprintf(
            'El parámetro %s debe estar entre %s y %s; se recibió %s.',
            $parametro,
            $min,
            $max,
            $valor,
        ));
    }

    public static function unsupportedDeficitType(string $tipoDeficit): self
    {
        return new self(sprintf(
            'El tipo de déficit "%s" no está soportado; use "porcentaje" o "fijo".',
            $tipoDeficit,
        ));
    }
}
