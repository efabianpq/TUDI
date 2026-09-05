<?php

namespace App\Exceptions;

use DomainException;

/**
 * Thrown when confirming or rechazando una RecomendacionSistema que ya salió
 * de estado "pendiente". Una recomendación solo se procesa una vez: aceptarla
 * dos veces podría reaplicar un ajuste de calorías_objetivo que el usuario ya
 * confirmó, y rechazar una ya confirmada dejaría el estado inconsistente.
 */
class RecomendacionYaProcesadaException extends DomainException
{
    public static function alConfirmar(?int $recomendacionId = null): self
    {
        return new self(sprintf(
            'Esta recomendación%s ya fue procesada y no se puede confirmar de nuevo.',
            self::referencia($recomendacionId),
        ));
    }

    public static function alRechazar(?int $recomendacionId = null): self
    {
        return new self(sprintf(
            'Esta recomendación%s ya fue procesada y no se puede rechazar de nuevo.',
            self::referencia($recomendacionId),
        ));
    }

    private static function referencia(?int $recomendacionId): string
    {
        return $recomendacionId !== null ? sprintf(' (#%d)', $recomendacionId) : '';
    }
}
