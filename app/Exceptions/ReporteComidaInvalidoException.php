<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * No se puede reportar esa comida tal y como llega la petición (CLAUDE.md
 * sección 5.5).
 *
 * Excepción de dominio, no error de programación: ReporteComidaController la
 * traduce a un redirect con mensaje, nunca a un 500 (mismo criterio que
 * MealDistributionUnavailableException).
 */
class ReporteComidaInvalidoException extends RuntimeException
{
    /**
     * Ya se cerró esa comida. Reabrirla es una acción explícita del usuario,
     * no algo que un segundo envío deba hacer por su cuenta: si el reporte
     * sobrescribiera en silencio, un doble clic borraría lo que ya se contó.
     */
    public static function yaReportada(string $tipoComida): self
    {
        return new self(
            "Ya cerraste el {$tipoComida}. Si quieres cambiar lo que reportaste, reábrelo primero."
        );
    }

    /**
     * Se pulsó "cerrar" sin decir nada: ni "cumplí lo sugerido", ni un texto,
     * ni una comida frecuente. Una foto sola no dice qué se comió.
     */
    public static function sinContenido(string $tipoComida): self
    {
        return new self(
            "Para cerrar el {$tipoComida} dinos qué comiste: marca que cumpliste lo sugerido, ".
            'cuéntalo por escrito, o repite una de tus comidas frecuentes.'
        );
    }

    /**
     * "Cumplí lo sugerido" sobre una comida que nunca se planificó: no hay
     * ninguna sugerencia que dar por buena.
     */
    public static function sinPlan(string $tipoComida): self
    {
        return new self(
            "No hay ningún {$tipoComida} sugerido que dar por cumplido. Cuéntanos qué comiste ".
            'o ajusta primero tu plan.'
        );
    }

    /**
     * La comida frecuente que se quiso repetir no es del usuario o ya no
     * existe (un historial borrado entre que se pintó la pantalla y se pulsó).
     */
    public static function plantillaNoDisponible(): self
    {
        return new self(
            'Esa comida frecuente ya no está disponible. Cuéntanos qué comiste.'
        );
    }
}
