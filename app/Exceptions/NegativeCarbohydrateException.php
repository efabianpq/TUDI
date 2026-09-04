<?php

namespace App\Exceptions;

use DomainException;

/**
 * Thrown when the target calories do not cover the protein + fat kcal, which
 * would leave a negative carbohydrate allowance (CLAUDE.md section 5:
 * "nunca persistir un plan con carbohidratos negativos").
 */
class NegativeCarbohydrateException extends DomainException
{
    public static function fromKcal(
        float $carbohidratosKcal,
        float $caloriasObjetivo,
        float $proteinaKcal,
        float $grasaKcal,
    ): self {
        return new self(sprintf(
            'Los carbohidratos resultantes son negativos (%.2f kcal): las calorías objetivo (%.2f kcal) '.
            'no cubren proteína (%.2f kcal) + grasa (%.2f kcal). '.
            'Reduce el déficit o los factores de proteína/grasa.',
            $carbohidratosKcal,
            $caloriasObjetivo,
            $proteinaKcal,
            $grasaKcal,
        ));
    }
}
