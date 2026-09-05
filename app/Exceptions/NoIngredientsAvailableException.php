<?php

namespace App\Exceptions;

use DomainException;

/**
 * Thrown when a meal plan is requested for a day that has no reported
 * IngredienteDisponible: there is nothing to build the meals from, so the
 * generator fails in a controlled way instead of persisting empty meals.
 */
class NoIngredientsAvailableException extends DomainException
{
    public static function paraRegistroDiario(?int $registroDiarioId = null): self
    {
        return new self(sprintf(
            'No hay ingredientes disponibles reportados para el día%s. '.
            'Reporta tus ingredientes antes de generar el plan.',
            $registroDiarioId !== null ? sprintf(' (registro diario #%d)', $registroDiarioId) : '',
        ));
    }
}
