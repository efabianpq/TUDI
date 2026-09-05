<?php

namespace App\Exceptions;

use DomainException;

/**
 * Thrown when an operation would modify a RegistroDiario that is already
 * closed: closing it a second time, or logging new data (a ComidaReal, an
 * ActividadFisica) against it. A closed day is a frozen snapshot — the only
 * way past it is an explicit reopen by the user (DailyClosureService::reabrir).
 */
class DayAlreadyClosedException extends DomainException
{
    public static function alCerrar(?int $registroDiarioId = null): self
    {
        return new self(sprintf(
            'El día%s ya está cerrado. Reábrelo si necesitas volver a calcular su cierre.',
            self::referencia($registroDiarioId),
        ));
    }

    public static function alRegistrarComida(?int $registroDiarioId = null): self
    {
        return new self(sprintf(
            'El día%s ya está cerrado: no se pueden registrar más comidas. '.
            'Reábrelo desde el cierre diario si necesitas modificarlo.',
            self::referencia($registroDiarioId),
        ));
    }

    public static function alRegistrarActividad(?int $registroDiarioId = null): self
    {
        return new self(sprintf(
            'El día%s ya está cerrado: no se pueden registrar más actividades. '.
            'Reábrelo desde el cierre diario si necesitas modificarlo.',
            self::referencia($registroDiarioId),
        ));
    }

    private static function referencia(?int $registroDiarioId): string
    {
        return $registroDiarioId !== null ? sprintf(' (registro diario #%d)', $registroDiarioId) : '';
    }
}
