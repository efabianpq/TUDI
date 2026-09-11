<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

/**
 * Cuota diaria de llamadas al proveedor de IA (CLAUDE.md sección 5.20).
 *
 * ── Por qué hace falta un límite si Premium es "ilimitado" ──────────────────
 *
 * Cada "Ajustar mi plan" y cada reporte de comida contado con texto libre es
 * una llamada facturable a la API, y además ocupa un worker de PHP-FPM mientras
 * dura (sección 5.13). Nada impedía abrir y cerrar la misma comida veinte veces
 * seguidas para ver qué macros salían: sale caro y degrada la aplicación para
 * todos los demás. El límite es el mismo mecanismo que usan los planes de pago
 * de las propias herramientas de IA — no quita la función, acota cuántas veces
 * al día se puede usar.
 *
 * ── Qué se cuenta y qué no ──────────────────────────────────────────────────
 *
 * Solo lo que llama de verdad al proveedor:
 *
 *  - `distribucion` — "Ajustar mi plan" (una llamada por pulsación, con las
 *    tres comidas dentro).
 *  - `reporte` — cerrar una comida contando por escrito qué se comió.
 *
 * Cerrar una comida con "sí, cumplí lo sugerido", repetir una comida frecuente
 * (sección 5.22), cerrar el día, dictar por voz y todo lo demás no gastan
 * cuota, porque no cuestan una llamada. Esa es también la salida honesta para
 * quien agota el día: se sigue pudiendo registrar todo, solo que sin IA.
 *
 * ── Se cobra al pedir, no al acertar ───────────────────────────────────────
 *
 * La unidad se descuenta **antes** de llamar. Un intento que falla en el
 * proveedor ya ha consumido tokens en la mayoría de los casos, y cobrar solo
 * los aciertos convertiría un fallo repetido en una llamada gratis infinita,
 * que es exactamente el escenario que esto evita.
 *
 * ── Dónde se guarda ─────────────────────────────────────────────────────────
 *
 * En la caché, con una clave por usuario, concepto y fecha local, que expira
 * sola al día siguiente. No hace falta tabla: el contador no es un dato del
 * dominio, no se consulta históricamente y perderlo (un `cache:clear`) solo
 * regala el resto del día, nunca corrompe nada.
 */
class CuotaIaService
{
    /** "Ajustar mi plan": una llamada con las tres comidas dentro. */
    public const CONCEPTO_DISTRIBUCION = 'distribucion';

    /** Cerrar una comida contando por escrito qué se comió. */
    public const CONCEPTO_REPORTE = 'reporte';

    /**
     * Los conceptos que existen y el parámetro maestro que fija su límite
     * (sección 5.11). Única declaración de los dos.
     *
     * @var array<string, string>
     */
    public const PARAMETRO_POR_CONCEPTO = [
        self::CONCEPTO_DISTRIBUCION => 'ia_limite_distribuciones_dia',
        self::CONCEPTO_REPORTE => 'ia_limite_reportes_dia',
    ];

    /**
     * Valores de fábrica. Doce ajustes de plan al día son cuatro por comida:
     * de sobra para un uso normal, corto para un bucle de pruebas. Los reportes
     * van más altos porque un día tiene tres comidas y cada una puede corregirse
     * un par de veces sin que eso sea abuso.
     */
    public const LIMITE_DISTRIBUCIONES_DIA = 12;

    public const LIMITE_REPORTES_DIA = 15;

    public function __construct(
        private readonly ParametrosMaestrosService $parametros,
    ) {}

    /**
     * Llamadas de ese concepto que le quedan hoy al usuario.
     */
    public function restantes(User $usuario, string $concepto): int
    {
        return max(0, $this->limite($concepto) - $this->consumidas($usuario, $concepto));
    }

    public function consumidas(User $usuario, string $concepto): int
    {
        return (int) Cache::get($this->clave($usuario, $concepto), 0);
    }

    /**
     * Límite diario vigente del concepto, ajustable por el administrador.
     */
    public function limite(string $concepto): int
    {
        return (int) $this->parametros->valor($this->parametroDe($concepto));
    }

    public function agotada(User $usuario, string $concepto): bool
    {
        return $this->restantes($usuario, $concepto) <= 0;
    }

    /**
     * Descuenta una llamada. Devuelve false —sin descontar— si ya no quedaba
     * ninguna; quien llama decide qué excepción de dominio lanzar, porque el
     * mensaje depende de para qué se pedía.
     */
    public function consumir(User $usuario, string $concepto): bool
    {
        if ($this->agotada($usuario, $concepto)) {
            return false;
        }

        $clave = $this->clave($usuario, $concepto);

        // `add` + `increment` en vez de `put`: así el contador conserva su
        // expiración de medianoche en lugar de reiniciarla en cada llamada.
        Cache::add($clave, 0, $this->segundosHastaMedianoche());
        Cache::increment($clave);

        return true;
    }

    /**
     * Devuelve el contador de un usuario a cero. Solo lo usa la consola de
     * administración: no hay ningún camino desde la aplicación por el que un
     * usuario se reponga cuota a sí mismo.
     */
    public function reiniciar(User $usuario, string $concepto): void
    {
        Cache::forget($this->clave($usuario, $concepto));
    }

    private function parametroDe(string $concepto): string
    {
        return self::PARAMETRO_POR_CONCEPTO[$concepto] ?? throw new InvalidArgumentException(
            "Concepto de cuota desconocido: {$concepto}"
        );
    }

    /**
     * La clave lleva la fecha **local** (sección 2: America/Bogota): el día del
     * usuario es el mismo que decide qué es "hoy" en todo el dominio.
     */
    private function clave(User $usuario, string $concepto): string
    {
        $this->parametroDe($concepto);

        return sprintf('cuota_ia:%d:%s:%s', $usuario->id, $concepto, now()->toDateString());
    }

    private function segundosHastaMedianoche(): int
    {
        return max(60, (int) now()->diffInSeconds(now()->endOfDay()));
    }
}
