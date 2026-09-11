<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * El proveedor de distribución de comidas (OpenAI) no pudo producir una
 * distribución: falta la clave de API, la llamada falló, o la respuesta no
 * cumplió el contrato esperado.
 *
 * Es una excepción de dominio, no un error de programación: PlanComidaController
 * la traduce a un redirect con mensaje, nunca a un 500 (mismo criterio que
 * NoIngredientsAvailableException — CLAUDE.md sección 4.2).
 */
class MealDistributionUnavailableException extends RuntimeException
{
    public static function sinCredenciales(string $variableEnv = 'OPENAI_API_KEY'): self
    {
        return new self(
            'La generación de distribuciones con IA no está configurada. '.
            "Define {$variableEnv} en el archivo .env para activarla."
        );
    }

    public static function porFalloDelProveedor(string $detalle): self
    {
        return new self(
            'No se pudo generar la distribución en este momento. Inténtalo de nuevo en unos segundos. '.
            "(Detalle: {$detalle})"
        );
    }

    /**
     * El usuario está en el plan Gratis (CLAUDE.md sección 5.18). No es un
     * fallo: es la respuesta correcta, y por eso el mensaje explica qué plan
     * hace falta en vez de disculparse por un error que no ha ocurrido.
     *
     * `$pruebaTerminada` distingue "se te acabó la prueba" de "esto nunca
     * estuvo incluido en tu plan": son dos situaciones distintas para quien lee.
     */
    public static function requierePremium(bool $pruebaTerminada = false): self
    {
        return new self(
            $pruebaTerminada
                ? 'Tu prueba de Premium terminó. Distribuir tus comidas con IA y estimar lo que '.
                  'comiste son funciones de Premium; el resto de TUDI sigue siendo tuyo, con todo tu historial.'
                : 'Distribuir tus comidas con IA es parte de Premium. Puedes seguir escribiendo lo que '.
                  'tienes y registrando tu día en el plan Gratis.'
        );
    }

    /**
     * El usuario gastó su cuota diaria de llamadas a la IA (CLAUDE.md sección
     * 5.20). Tampoco es un fallo: el mensaje dice cuántas eran y, sobre todo,
     * qué SÍ se puede seguir haciendo hoy sin IA, que es lo que necesita leer
     * quien se queda a media tarde sin distribuciones.
     */
    public static function cuotaDiariaAgotada(string $concepto, int $limite): self
    {
        return new self(
            $concepto === 'reporte'
                ? "Llegaste a tus {$limite} reportes con IA de hoy. Puedes seguir cerrando tus comidas ".
                  'con "cumplí lo sugerido" o repitiendo una comida frecuente, que no gastan cuota. '.
                  'Mañana vuelves a tener todas.'
                : "Llegaste a tus {$limite} ajustes de plan de hoy. Tu plan y tus reportes siguen ".
                  'igual, y mañana vuelves a tener todos.'
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
