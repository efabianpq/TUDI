<?php

namespace App\Services;

use App\Exceptions\InvalidNutritionParameterException;
use App\Exceptions\NegativeCarbohydrateException;
use App\Models\RegistroDiario;
use App\Models\User;

/**
 * El objetivo nutricional **con el que se trabajó un día concreto**
 * (CLAUDE.md sección 5.25).
 *
 * ── Por qué existe ───────────────────────────────────────────────────────────
 *
 * Hasta ahora, cualquier día abierto derivaba sus objetivos del perfil actual
 * del usuario. Eso significaba que tocar la Calculadora Déficit reescribía
 * hacia atrás los objetivos de todos los días pasados que siguieran abiertos:
 * un día de la semana pasada, planificado y reportado contra 2.112 kcal, pasaba
 * a compararse contra 1.900 kcal sin que nada hubiera cambiado en él. El
 * historial de la ventana de 7 días quedaba medido con una regla distinta a la
 * que se usó para vivirlo.
 *
 * La corrección es sellar el objetivo en el propio `RegistroDiario` cuando el
 * día empieza. Desde entonces ese día tiene su objetivo y no se mueve: cambiar
 * la Calculadora solo afecta a los días que todavía no han empezado y, como
 * excepción deliberada, al de **hoy** —que es el día que el usuario está
 * viviendo y para el que acaba de pedir el cambio.
 *
 * ── Qué columnas usa, y por qué no hacen falta nuevas ────────────────────────
 *
 * `registros_diarios` ya tenía `calorias_objetivo_dia`, `proteina_objetivo_g`,
 * `grasa_objetivo_g` y `carbohidratos_objetivo_g`, que hasta ahora solo se
 * rellenaban al cerrar. Pasan a escribirse al empezar el día: su significado es
 * el mismo —el objetivo vigente de ese día— solo que ahora se conoce desde el
 * principio y no únicamente al final. El cierre las reescribe con el mismo
 * valor y sigue añadiendo lo consumido y el déficit.
 *
 * No reimplementa ninguna fórmula: todo sale de NutritionCalculatorService
 * (sección 7).
 */
class ObjetivoDelDiaService
{
    public function __construct(
        private readonly NutritionCalculatorService $calculadora,
    ) {}

    /**
     * Sella en el día el objetivo vigente del usuario. Idempotente por
     * naturaleza: vuelve a calcular y sobrescribe, que es justo lo que hace
     * falta cuando el usuario cambia su Calculadora y el día es el de hoy.
     *
     * Un día **cerrado no se toca**: sus cifras están congeladas y reescribir
     * su objetivo cambiaría un déficit ya consolidado.
     *
     * Si el perfil todavía no permite calcular un plan (parámetros a medias, o
     * macros que no caben en el objetivo) no se sella nada y no se lanza: el
     * día queda sin sellar y se resuelve como los días heredados, contra el
     * perfil vigente. Sellar es una mejora del historial, nunca un requisito
     * para poder usar la aplicación.
     */
    public function sellar(RegistroDiario $registroDiario): void
    {
        if ($registroDiario->cerrado) {
            return;
        }

        $objetivo = $this->calcularDesdeElPerfil($registroDiario->usuario);

        if ($objetivo === null) {
            return;
        }

        $registroDiario->update([
            'calorias_objetivo_dia' => round($objetivo['calorias_objetivo'], 2),
            'proteina_objetivo_g' => round($objetivo['proteina_g'], 2),
            'grasa_objetivo_g' => round($objetivo['grasa_g'], 2),
            'carbohidratos_objetivo_g' => round($objetivo['carbohidratos_g'], 2),
        ]);
    }

    /**
     * Sella el día de HOY del usuario, si lo tiene abierto. Es lo que se llama
     * tras un cambio de la Calculadora o tras confirmar una recomendación: el
     * cambio alcanza al día en curso y a ninguno anterior.
     */
    public function sellarElDiaDeHoy(User $usuario): void
    {
        $hoy = RegistroDiario::where('usuario_id', $usuario->id)
            ->whereDate('fecha', now()->toDateString())
            ->first();

        if ($hoy !== null) {
            // La relación puede no estar cargada y `sellar()` necesita el
            // perfil: se le pasa el usuario que ya tenemos en la mano.
            $hoy->setRelation('usuario', $usuario);

            $this->sellar($hoy);
        }
    }

    /**
     * El objetivo con el que trabaja este día: el sellado si lo tiene, y si no
     * —días creados antes de que esto existiera— el que derive del perfil
     * actual, que es exactamente el comportamiento anterior.
     *
     * @return array{calorias_objetivo: float, proteina_g: float, grasa_g: float, carbohidratos_g: float}
     *
     * @throws NegativeCarbohydrateException|InvalidNutritionParameterException cuando no hay sello y el perfil no permite calcular
     */
    public function vigente(RegistroDiario $registroDiario): array
    {
        if ($this->estaSellado($registroDiario)) {
            return [
                'calorias_objetivo' => (float) $registroDiario->calorias_objetivo_dia,
                'proteina_g' => (float) $registroDiario->proteina_objetivo_g,
                'grasa_g' => (float) $registroDiario->grasa_objetivo_g,
                'carbohidratos_g' => (float) $registroDiario->carbohidratos_objetivo_g,
            ];
        }

        return $this->calcularDelUsuario($registroDiario->usuario);
    }

    /**
     * ¿Tiene este día su objetivo sellado? Hacen falta las cuatro cifras: un
     * día cerrado antes de que el snapshot guardara grasa y carbohidratos tiene
     * calorías y proteína pero no las otras dos, y completarlas desde el perfil
     * actual mezclaría dos reglas distintas en la misma tarjeta.
     */
    public function estaSellado(RegistroDiario $registroDiario): bool
    {
        return $registroDiario->calorias_objetivo_dia !== null
            && $registroDiario->proteina_objetivo_g !== null
            && $registroDiario->grasa_objetivo_g !== null
            && $registroDiario->carbohidratos_objetivo_g !== null;
    }

    /**
     * El objetivo que dicta el perfil del usuario ahora mismo, con el objetivo
     * calórico vigente (`users.calorias_objetivo`) por delante de la fórmula
     * cruda cuando existe (sección 5.2).
     *
     * @return array{calorias_objetivo: float, proteina_g: float, grasa_g: float, carbohidratos_g: float}
     */
    public function calcularDelUsuario(User $usuario): array
    {
        return $this->calculadora->calculatePlan(
            (float) $usuario->peso_kg,
            (float) $usuario->nivel_actividad,
            $usuario->tipo_deficit,
            (float) $usuario->valor_deficit,
            (float) $usuario->proteina_factor,
            (float) $usuario->grasa_factor,
            $usuario->calorias_objetivo !== null ? (float) $usuario->calorias_objetivo : null,
        );
    }

    /**
     * Como el anterior pero tragándose el fallo: sellar es oportunista.
     *
     * @return array{calorias_objetivo: float, proteina_g: float, grasa_g: float, carbohidratos_g: float}|null
     */
    private function calcularDesdeElPerfil(User $usuario): ?array
    {
        foreach (MealDistributionService::PARAMETROS_REQUERIDOS as $parametro) {
            if ($usuario->{$parametro} === null) {
                return null;
            }
        }

        try {
            return $this->calcularDelUsuario($usuario);
        } catch (NegativeCarbohydrateException|InvalidNutritionParameterException) {
            return null;
        }
    }
}
