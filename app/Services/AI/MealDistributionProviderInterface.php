<?php

namespace App\Services\AI;

use App\Exceptions\MealDistributionUnavailableException;

/**
 * Contrato para el motor que interpreta el texto libre que el usuario escribe
 * (o dicta) sobre su comida y lo convierte en porciones concretas con sus
 * macronutrientes.
 *
 * Tiene dos operaciones, las dos sobre el día completo y no sobre una comida
 * suelta (CLAUDE.md sección 4.12):
 *
 *  - `distribuirDia()` — antes de comer: "tengo dos huevos y media palta" →
 *    qué porciones tomar en cada comida para cuadrar los objetivos del día.
 *  - `estimarConsumoReal()` — al cerrar: "al final me comí un sándwich" → qué
 *    macros tuvo realmente lo que se comió, para consolidarlo en el cierre
 *    (sección 4.16).
 *
 * Es una interfaz aparte de NutritionAiProviderInterface (CLAUDE.md sección
 * 4.9) a propósito: aquella recibe un inventario ya estructurado
 * (IngredienteDisponible con macros por 100 g) y solo decide cuántos gramos de
 * cada uno usar; esta recibe lenguaje natural y tiene que estimar también los
 * macros. Son dos problemas distintos con dos entradas distintas.
 *
 * Implementación actual: GeminiMealDistributionProvider (Gemini 2.5 Flash).
 * El binding vive en AppServiceProvider. ClaudeMealDistributionProvider
 * (Claude Haiku 4.5) sigue en el repo, sin bindear, por si hiciera falta
 * volver atrás.
 */
interface MealDistributionProviderInterface
{
    /**
     * Reparte, en una sola llamada, los alimentos descritos para las comidas
     * que hay que resolver ahora.
     *
     * Recibe el día entero de contexto —lo que ya está fijado y lo que queda
     * reservado para una comida que todavía no se ha escrito— para que el
     * reparto de las comidas nuevas encaje en lo que sobra del objetivo diario.
     *
     * No persiste nada y no decide ningún presupuesto: los objetivos por comida
     * ya vienen calculados por MealDistributionService a partir de
     * NutritionCalculatorService (la única fuente de verdad de las fórmulas —
     * CLAUDE.md sección 5).
     *
     * @param  array<string, array{texto: string, objetivos: array{calorias: float, proteina_g: float, grasa_g: float, carbohidratos_g: float}}>  $comidas  comidas a generar, indexadas por tipo (desayuno|almuerzo|cena)
     * @param  array{calorias_objetivo_dia: float, proteina_objetivo_dia_g: float, grasa_objetivo_dia_g: float, carbohidratos_objetivo_dia_g: float, reparto: array<string, float>, comidas_fijas: array<string, array{descripcion: string, calorias: float, proteina_g: float, grasa_g: float, carbohidratos_g: float}>, comidas_reservadas: array<string, array{calorias: float, proteina_g: float, grasa_g: float, carbohidratos_g: float}>}  $contextoDia
     * @return array<string, array{descripcion: string, preparacion: string, notas: string, ingredientes: array<int, array{nombre: string, porcion: string, cantidad_g: float, calorias: float, proteina_g: float, grasa_g: float, carbohidratos_g: float}>}> una entrada por comida resuelta, indexada por tipo
     *
     * @throws MealDistributionUnavailableException cuando el proveedor no está configurado, falla, o devuelve algo que no cumple el contrato
     */
    public function distribuirDia(array $comidas, array $contextoDia): array;

    /**
     * Interpreta lo que la persona dice haber comido de verdad y lo traduce a
     * ingredientes con sus macros, para consolidarlo en el cierre del día.
     *
     * Es la otra cara de `distribuirDia()`: aquí no hay objetivos que cuadrar
     * —lo comido, comido está— sino que estimar lo más fielmente posible lo que
     * describe el texto, con el plan sugerido como referencia de porciones.
     *
     * @param  array<string, array{texto: string, plan: array{descripcion: string, calorias: float, proteina_g: float, grasa_g: float, carbohidratos_g: float}}>  $comidas  indexadas por tipo de comida
     * @param  array{calorias_objetivo_dia: float}  $contextoDia
     * @return array<string, array{descripcion: string, preparacion: string, notas: string, ingredientes: array<int, array{nombre: string, porcion: string, cantidad_g: float, calorias: float, proteina_g: float, grasa_g: float, carbohidratos_g: float}>}>
     *
     * @throws MealDistributionUnavailableException cuando el proveedor no está configurado, falla, o devuelve algo que no cumple el contrato
     */
    public function estimarConsumoReal(array $comidas, array $contextoDia): array;
}
