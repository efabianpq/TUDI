<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * El proveedor de transcripción de audio no pudo convertir a texto lo que el
 * usuario dictó: falta la clave de API, la llamada falló, o la respuesta no
 * trajo texto.
 *
 * Es una excepción de dominio, no un error de programación: TranscripcionController
 * la traduce a un 422 con mensaje legible, nunca a un 500 (regla 6 de la
 * sección 11, mismo criterio que MealDistributionUnavailableException).
 */
class TranscripcionNoDisponibleException extends RuntimeException
{
    public static function sinCredenciales(string $variableEnv = 'GEMINI_API_KEY'): self
    {
        return new self(
            'El dictado por voz no está configurado en el servidor. '.
            "Define {$variableEnv} en el archivo .env para activarlo."
        );
    }

    public static function porFalloDelProveedor(string $detalle): self
    {
        return new self(
            'No se pudo transcribir el audio en este momento. Inténtalo de nuevo o escríbelo a mano. '.
            "(Detalle: {$detalle})"
        );
    }

    /**
     * El audio llegó bien y el modelo respondió, pero no había nada que
     * transcribir (silencio, ruido, o el micrófono no capturó voz).
     */
    public static function sinVoz(): self
    {
        return new self(
            'No se escuchó nada en la grabación. Acerca el micrófono y vuelve a intentarlo.'
        );
    }
}
