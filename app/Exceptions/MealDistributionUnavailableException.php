<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * El proveedor de distribución de comidas (Claude Haiku 4.5) no pudo producir
 * una distribución: falta la clave de API, la llamada falló, o la respuesta no
 * cumplió el contrato esperado.
 *
 * Es una excepción de dominio, no un error de programación: PlanComidaController
 * la traduce a un redirect con mensaje, nunca a un 500 (mismo criterio que
 * NoIngredientsAvailableException — CLAUDE.md sección 4.2).
 */
class MealDistributionUnavailableException extends RuntimeException
{
    public static function sinCredenciales(): self
    {
        return new self(
            'La generación de distribuciones con IA no está configurada. '.
            'Define ANTHROPIC_API_KEY en el archivo .env para activarla.'
        );
    }

    public static function porFalloDelProveedor(string $detalle): self
    {
        return new self(
            'No se pudo generar la distribución en este momento. Inténtalo de nuevo en unos segundos. '.
            "(Detalle: {$detalle})"
        );
    }

    public static function porRespuestaInvalida(string $detalle): self
    {
        return new self(
            'La distribución generada no se pudo interpretar. Inténtalo de nuevo o describe los ingredientes con más detalle. '.
            "(Detalle: {$detalle})"
        );
    }

    public static function sinIngredientes(string $tipoComida): self
    {
        return new self(
            "Escribe o dicta primero los ingredientes que tienes para el {$tipoComida}."
        );
    }

    public static function comidaYaRegistrada(string $tipoComida): self
    {
        return new self(
            "Ya registraste lo que comiste en el {$tipoComida}: su distribución no se puede regenerar."
        );
    }

    /**
     * Se pulsó "Generar distribución" sin nada nuevo que repartir: ninguna
     * comida tiene texto sin resolver, y las que ya tienen distribución se
     * respetan tal cual (CLAUDE.md sección 4.12).
     */
    public static function nadaQueDistribuir(): self
    {
        return new self(
            'No hay nada nuevo que distribuir: escribe qué tienes para alguna comida que aún no '.
            'esté resuelta, o edita el texto de una para rehacerla.'
        );
    }
}
