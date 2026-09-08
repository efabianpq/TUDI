<?php

namespace App\Services;

use App\Models\RegistroDiario;
use App\Models\User;
use InvalidArgumentException;

/**
 * Reparto de calorías entre desayuno, almuerzo y cena (CLAUDE.md sección 5.14).
 *
 * El 25/40/35 de MealPlanGeneratorService::DISTRIBUCION_COMIDAS sigue siendo la
 * única declaración de qué comidas hay y del reparto de fábrica. Este servicio
 * solo resuelve cuál de los tres posibles rige en cada momento:
 *
 *   el del día  →  el habitual del usuario  →  el de fábrica
 *
 * Nada más lo decide, y ningún otro punto del código vuelve a leer
 * DISTRIBUCION_COMIDAS para saber cuánto le toca a cada comida.
 *
 * ── Por qué no es un parámetro maestro ─────────────────────────────────────
 *
 * Porque no es un umbral de criterio del administrador sino una preferencia del
 * usuario, y porque cambia por día: quien cena poco un martes no está cambiando
 * la configuración del producto. La sección 5.11 sigue excluyéndolo del catálogo
 * de ParametrosMaestrosService por esa razón.
 *
 * ── Qué NO hace ────────────────────────────────────────────────────────────
 *
 * Cambiar el reparto no recalcula ningún PlanComida ya generado: sus macros
 * están persistidos. El reparto dimensiona los objetivos que se muestran y el
 * presupuesto de lo que queda por generar, nunca lo ya resuelto.
 */
class RepartoComidasService
{
    /**
     * Proporción mínima que puede recibir una comida. Cero dejaría una comida
     * sin presupuesto y el generador no tendría nada que repartirle; el usuario
     * que quiere saltarse una comida simplemente no escribe sus ingredientes.
     */
    public const PROPORCION_MINIMA = 0.05;

    /**
     * Tolerancia al comparar la suma con 1.0: el formulario trabaja en enteros
     * de porcentaje, así que la suma es exacta, pero un reparto persistido con
     * decimales no tiene por qué serlo al céntimo.
     */
    private const TOLERANCIA = 0.005;

    /**
     * El reparto vigente para un día concreto.
     *
     * @return array<string, float> proporciones (0–1) por tipo de comida, en el orden de DISTRIBUCION_COMIDAS
     */
    public function paraElDia(RegistroDiario $registroDiario): array
    {
        return $this->normalizar($registroDiario->reparto_comidas)
            ?? $this->habitual($registroDiario->usuario);
    }

    /**
     * El reparto habitual del usuario, o el de fábrica si no ha guardado uno.
     *
     * @return array<string, float>
     */
    public function habitual(?User $usuario): array
    {
        return $this->normalizar($usuario?->reparto_comidas) ?? $this->deFabrica();
    }

    /**
     * @return array<string, float>
     */
    public function deFabrica(): array
    {
        return MealPlanGeneratorService::DISTRIBUCION_COMIDAS;
    }

    /**
     * ¿Es este reparto el de fábrica? Lo usa la interfaz para no anunciar como
     * "personalizado" un día que nunca se tocó.
     *
     * @param  array<string, float>  $reparto
     */
    public function esDeFabrica(array $reparto): bool
    {
        foreach ($this->deFabrica() as $tipoComida => $proporcion) {
            if (abs(($reparto[$tipoComida] ?? 0.0) - $proporcion) > self::TOLERANCIA) {
                return false;
            }
        }

        return true;
    }

    /**
     * Guarda el reparto de un día y, opcionalmente, lo adopta como el habitual
     * del usuario para los días siguientes.
     *
     * Guardar el reparto de fábrica limpia la columna en vez de persistirlo:
     * "sin reparto propio" y "el de fábrica" son lo mismo, y así un cambio
     * futuro del valor de fábrica alcanza a quien nunca lo personalizó.
     *
     * @param  array<string, int|float>  $porcentajes  porcentajes enteros (25/40/35), tal y como llegan del formulario
     *
     * @throws InvalidArgumentException si falta una comida, si alguna queda por debajo del mínimo o si no suman 100
     */
    public function guardar(RegistroDiario $registroDiario, array $porcentajes, bool $comoHabitual = false): array
    {
        $reparto = $this->desdePorcentajes($porcentajes);

        $registroDiario->update([
            'reparto_comidas' => $this->esDeFabrica($reparto) ? null : $reparto,
        ]);

        if ($comoHabitual) {
            $registroDiario->usuario->update([
                'reparto_comidas' => $this->esDeFabrica($reparto) ? null : $reparto,
            ]);
        }

        return $reparto;
    }

    /**
     * Traduce los porcentajes enteros del formulario a proporciones validadas.
     *
     * La validación vive aquí y no solo en el Form Request porque el reparto lo
     * consumen el generador y el cierre fuera de HTTP: un reparto que no sume
     * 1.0 desdibujaría el objetivo calórico del día entero.
     *
     * @param  array<string, int|float>  $porcentajes
     * @return array<string, float>
     *
     * @throws InvalidArgumentException
     */
    public function desdePorcentajes(array $porcentajes): array
    {
        $reparto = [];

        foreach (array_keys($this->deFabrica()) as $tipoComida) {
            if (! array_key_exists($tipoComida, $porcentajes)) {
                throw new InvalidArgumentException("Falta el porcentaje del {$tipoComida}.");
            }

            $proporcion = (float) $porcentajes[$tipoComida] / 100;

            if ($proporcion < self::PROPORCION_MINIMA) {
                throw new InvalidArgumentException(
                    'Cada comida necesita al menos el '.(int) (self::PROPORCION_MINIMA * 100).'% del día.'
                );
            }

            $reparto[$tipoComida] = $proporcion;
        }

        if (abs(array_sum($reparto) - 1.0) > self::TOLERANCIA) {
            throw new InvalidArgumentException('Los tres porcentajes tienen que sumar 100%.');
        }

        return $reparto;
    }

    /**
     * Un reparto persistido, o null si no hay ninguno o si el que hay no es
     * utilizable (una fila manipulada a mano, un tipo de comida que ya no
     * existe): en ese caso se cae al siguiente escalón en vez de repartir mal.
     *
     * @return array<string, float>|null
     */
    private function normalizar(mixed $reparto): ?array
    {
        if (! is_array($reparto) || $reparto === []) {
            return null;
        }

        $normalizado = [];

        foreach (array_keys($this->deFabrica()) as $tipoComida) {
            if (! isset($reparto[$tipoComida]) || ! is_numeric($reparto[$tipoComida])) {
                return null;
            }

            $normalizado[$tipoComida] = (float) $reparto[$tipoComida];
        }

        if (abs(array_sum($normalizado) - 1.0) > self::TOLERANCIA) {
            return null;
        }

        return $normalizado;
    }
}
