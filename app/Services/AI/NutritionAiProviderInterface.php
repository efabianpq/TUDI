<?php

namespace App\Services\AI;

/**
 * Contrato para todo lo que hoy decide un "algoritmo" pero que en el futuro
 * podría decidir un modelo de IA generativa: qué ingredientes usar en una
 * comida y cómo redactar el mensaje de una RecomendacionSistema.
 *
 * El dominio (MealPlanGeneratorService, RulesEngineService) depende solo de
 * esta interfaz, nunca de una implementación concreta — así, cambiar de
 * proveedor en el futuro (ver GenerativeAiProvider más abajo) es solo cambiar
 * el binding en el ServiceProvider, sin tocar ningún Service de dominio.
 *
 * Implementación actual: RuleBasedNutritionProvider, que envuelve la
 * heurística codiciosa de MealPlanGeneratorService y las plantillas de texto
 * de RulesEngineService (CLAUDE.md secciones 4.2 y 4.6).
 *
 * Futuro (no implementado en este prompt a propósito): una
 * GenerativeAiProvider que use Guzzle para llamar a un proveedor de IA
 * externo (p.ej. un LLM) implementaría esta misma interfaz — el resto del
 * dominio no necesitaría ningún cambio.
 */
interface NutritionAiProviderInterface
{
    /**
     * Sugiere qué ingredientes del inventario disponible usar, y en qué
     * cantidad (gramos), para cubrir los objetivos de calorías/macros de una
     * comida. El inventario se recibe por referencia y se modifica in place:
     * los gramos sugeridos se descuentan de él, para que una comida siguiente
     * no vuelva a ofrecer lo que ya se asignó a esta.
     *
     * @param  array<int, array<string, mixed>>  $inventario  cada elemento con las claves ingrediente_id, nombre, disponible_g, calorias_por_100g, proteina_por_100g, grasa_por_100g, carbohidratos_por_100g (mismo shape que produce MealPlanGeneratorService)
     * @param  array{calorias: float, proteina_g: float, grasa_g: float, carbohidratos_g: float}  $objetivos  objetivos de esta comida (ya repartidos según MealPlanGeneratorService::DISTRIBUCION_COMIDAS)
     * @return array<int, array<string, mixed>> selección elegida: ingrediente_id, nombre, cantidad_g, calorias, proteina_g, grasa_g, carbohidratos_g
     */
    public function sugerirIngredientesParaComida(array &$inventario, array $objetivos): array;

    /**
     * Genera el texto legible de una RecomendacionSistema a partir del
     * contexto ya calculado por RulesEngineService (porcentaje de pérdida
     * semanal, calorías actuales/sugeridas, umbrales de estancamiento, etc.).
     * No decide si corresponde generar la recomendación ni la persiste — eso
     * sigue siendo responsabilidad exclusiva de RulesEngineService.
     *
     * @param  string  $tipo  RulesEngineService::TIPO_AJUSTE_CALORICO | TIPO_ALERTA_ESTANCAMIENTO
     * @param  array<string, mixed>  $contexto  datos necesarios para redactar el mensaje; las claves dependen de $tipo
     */
    public function generarTextoRecomendacion(string $tipo, array $contexto): string;
}
