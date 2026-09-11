<?php

namespace App\Services;

use App\Models\ParametroMaestro;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Parámetros maestros del dominio (CLAUDE.md sección 4.27): las constantes de
 * ajuste que el administrador puede mover sin tocar código ni desplegar.
 *
 * ── Qué entra en el catálogo y qué no ──────────────────────────────────────
 *
 * Entran los umbrales del motor de recomendaciones, los de la sugerencia de
 * actividad y los límites diarios de llamadas a la IA (sección 5.20): son
 * cifras de criterio, no de arquitectura, y moverlas no invalida ningún dato ya
 * persistido.
 *
 * NO entran, a propósito:
 *
 *  - **El reparto 25/40/35 entre comidas** (MealPlanGeneratorService::DISTRIBUCION_COMIDAS).
 *    Cambiarlo desdibujaría los planes ya generados con el reparto anterior, y
 *    la clave de ese array es además el nombre de columna del texto de
 *    ingredientes y de los campos del formulario del cierre.
 *  - **Las fórmulas de la sección 5 y los rangos de macros.** Son la definición
 *    del producto, no un ajuste: la sección 5 es su única fuente de verdad.
 *  - **El modelo y el timeout del proveedor de IA.** Son configuración de
 *    despliegue y viven en `.env`; leerlos desde aquí obligaría a una consulta
 *    en cada petición (ver sección 4.22).
 *
 * ── Coste de lectura ───────────────────────────────────────────────────────
 *
 * Los valores se cachean juntos y para siempre; guardar invalida la entrada.
 * Una petición que no consulte ningún parámetro no paga nada, y una que
 * consulte varios paga una sola lectura de caché.
 */
class ParametrosMaestrosService
{
    public const CACHE_CLAVE = 'parametros_maestros';

    public const TIPO_ENTERO = 'entero';

    public const TIPO_DECIMAL = 'decimal';

    /**
     * El catálogo: la única declaración de qué parámetros existen.
     *
     * `defecto` es el valor de fábrica, y coincide con la constante del
     * servicio que lo consume — una clave que no está en la tabla significa
     * "el de fábrica", así que la aplicación funciona con la tabla vacía.
     *
     * @var array<string, array{grupo: string, etiqueta: string, ayuda: string, tipo: string, defecto: int|float, min: int|float, max: int|float, unidad: string}>
     */
    public const CATALOGO = [
        'recomendaciones_umbral_perdida_lenta_pct' => [
            'grupo' => 'Motor de recomendaciones',
            'etiqueta' => 'Pérdida semanal lenta',
            'ayuda' => 'Por debajo de este % de pérdida semanal se sugiere reducir el objetivo calórico.',
            'tipo' => self::TIPO_DECIMAL,
            'defecto' => RulesEngineService::UMBRAL_PERDIDA_LENTA_PCT,
            'min' => 0.1,
            'max' => 2.0,
            'unidad' => '% / semana',
        ],
        'recomendaciones_umbral_perdida_rapida_pct' => [
            'grupo' => 'Motor de recomendaciones',
            'etiqueta' => 'Pérdida semanal rápida',
            'ayuda' => 'Por encima de este % de pérdida semanal se sugiere aumentar el objetivo calórico.',
            'tipo' => self::TIPO_DECIMAL,
            'defecto' => RulesEngineService::UMBRAL_PERDIDA_RAPIDA_PCT,
            'min' => 0.2,
            'max' => 3.0,
            'unidad' => '% / semana',
        ],
        'recomendaciones_ajuste_kcal' => [
            'grupo' => 'Motor de recomendaciones',
            'etiqueta' => 'Tamaño del ajuste sugerido',
            'ayuda' => 'Cuántas kcal se suman o restan al objetivo cuando el usuario confirma una recomendación.',
            'tipo' => self::TIPO_DECIMAL,
            'defecto' => RulesEngineService::AJUSTE_KCAL_SUGERIDO,
            'min' => 50.0,
            'max' => 400.0,
            'unidad' => 'kcal',
        ],
        'recomendaciones_umbral_estancamiento_kg' => [
            'grupo' => 'Motor de recomendaciones',
            'etiqueta' => 'Variación que cuenta como estancamiento',
            'ayuda' => 'Una semana con menos variación de peso que esta se considera "sin cambios".',
            'tipo' => self::TIPO_DECIMAL,
            'defecto' => RulesEngineService::UMBRAL_ESTANCAMIENTO_KG,
            'min' => 0.05,
            'max' => 1.0,
            'unidad' => 'kg / semana',
        ],
        'recomendaciones_semanas_estancamiento' => [
            'grupo' => 'Motor de recomendaciones',
            'etiqueta' => 'Semanas seguidas para alertar',
            'ayuda' => 'Cuántas semanas seguidas sin cambios hacen falta antes de avisar de un estancamiento.',
            'tipo' => self::TIPO_ENTERO,
            'defecto' => RulesEngineService::SEMANAS_ESTANCAMIENTO,
            'min' => 2,
            'max' => 8,
            'unidad' => 'semanas',
        ],
        'actividad_proporcion_del_deficit' => [
            'grupo' => 'Sugerencia de actividad',
            'etiqueta' => 'Parte del déficit que cubre el ejercicio',
            'ayuda' => 'Qué proporción del déficit dietético se propone cubrir con actividad física.',
            'tipo' => self::TIPO_DECIMAL,
            'defecto' => ActivitySuggestionService::PROPORCION_DEL_DEFICIT,
            'min' => 0.1,
            'max' => 0.8,
            'unidad' => 'proporción',
        ],
        'actividad_kcal_minimas' => [
            'grupo' => 'Sugerencia de actividad',
            'etiqueta' => 'Objetivo mínimo de actividad',
            'ayuda' => 'Suelo de la sugerencia diaria: por debajo no aporta nada.',
            'tipo' => self::TIPO_DECIMAL,
            'defecto' => ActivitySuggestionService::OBJETIVO_MINIMO_KCAL,
            'min' => 50.0,
            'max' => 400.0,
            'unidad' => 'kcal',
        ],
        'actividad_kcal_maximas' => [
            'grupo' => 'Sugerencia de actividad',
            'etiqueta' => 'Objetivo máximo de actividad',
            'ayuda' => 'Techo de la sugerencia diaria: por encima deja de ser sostenible a diario.',
            'tipo' => self::TIPO_DECIMAL,
            'defecto' => ActivitySuggestionService::OBJETIVO_MAXIMO_KCAL,
            'min' => 200.0,
            'max' => 1200.0,
            'unidad' => 'kcal',
        ],
        'ia_limite_distribuciones_dia' => [
            'grupo' => 'Límites de IA',
            'etiqueta' => 'Ajustes de plan al día',
            'ayuda' => 'Cuántas veces al día puede cada usuario pulsar "Ajustar mi plan". Cada pulsación es una llamada facturable.',
            'tipo' => self::TIPO_ENTERO,
            'defecto' => CuotaIaService::LIMITE_DISTRIBUCIONES_DIA,
            'min' => 1,
            'max' => 100,
            'unidad' => 'al día',
        ],
        'ia_limite_reportes_dia' => [
            'grupo' => 'Límites de IA',
            'etiqueta' => 'Reportes con IA al día',
            'ayuda' => 'Cuántas comidas al día puede cerrar cada usuario contando por escrito qué comió. Cerrar con "cumplí lo sugerido" no gasta cuota.',
            'tipo' => self::TIPO_ENTERO,
            'defecto' => CuotaIaService::LIMITE_REPORTES_DIA,
            'min' => 1,
            'max' => 100,
            'unidad' => 'al día',
        ],
        'actividad_duracion_maxima_min' => [
            'grupo' => 'Sugerencia de actividad',
            'etiqueta' => 'Duración máxima sugerida',
            'ayuda' => 'Cuando una actividad poco intensa necesitaría más tiempo, se recorta aquí.',
            'tipo' => self::TIPO_ENTERO,
            'defecto' => ActivitySuggestionService::DURACION_MAXIMA_MIN,
            'min' => 20,
            'max' => 240,
            'unidad' => 'minutos',
        ],
    ];

    /**
     * Valor vigente de un parámetro, ya convertido a su tipo.
     */
    public function valor(string $clave): int|float
    {
        $definicion = self::CATALOGO[$clave] ?? throw new InvalidArgumentException(
            "Parámetro maestro desconocido: {$clave}"
        );

        $guardado = $this->guardados()[$clave] ?? null;

        return $guardado === null
            ? $definicion['defecto']
            : $this->convertir($guardado, $definicion['tipo']);
    }

    /**
     * Todos los parámetros del catálogo con su valor vigente.
     *
     * @return array<string, int|float>
     */
    public function todos(): array
    {
        $valores = [];

        foreach (array_keys(self::CATALOGO) as $clave) {
            $valores[$clave] = $this->valor($clave);
        }

        return $valores;
    }

    /**
     * Persiste los parámetros que cambian respecto de lo vigente.
     *
     * Solo se escriben las claves del catálogo y solo dentro de sus límites: la
     * validación de rango vive aquí, no solo en el Form Request, porque un
     * umbral fuera de rango produce recomendaciones absurdas y este servicio es
     * el único camino de escritura.
     *
     * @param  array<string, mixed>  $valores
     *
     * @throws InvalidArgumentException si una clave no existe o un valor sale del rango
     */
    public function guardar(array $valores, ?int $usuarioId = null): void
    {
        foreach ($valores as $clave => $valor) {
            $definicion = self::CATALOGO[$clave] ?? throw new InvalidArgumentException(
                "Parámetro maestro desconocido: {$clave}"
            );

            $convertido = $this->convertir((string) $valor, $definicion['tipo']);

            if ($convertido < $definicion['min'] || $convertido > $definicion['max']) {
                throw new InvalidArgumentException(
                    "El parámetro {$clave} debe estar entre {$definicion['min']} y {$definicion['max']}."
                );
            }

            ParametroMaestro::updateOrCreate(
                ['clave' => $clave],
                ['valor' => (string) $convertido, 'actualizado_por' => $usuarioId],
            );
        }

        $this->olvidarCache();
    }

    /**
     * Devuelve todos los parámetros a su valor de fábrica.
     */
    public function restablecer(): void
    {
        ParametroMaestro::query()->delete();

        $this->olvidarCache();
    }

    public function olvidarCache(): void
    {
        Cache::forget(self::CACHE_CLAVE);
    }

    /**
     * Los valores que hay en la tabla, cacheados juntos: una petición que
     * consulte varios parámetros paga una sola lectura.
     *
     * @return array<string, string>
     */
    private function guardados(): array
    {
        return Cache::rememberForever(self::CACHE_CLAVE, function (): array {
            try {
                return ParametroMaestro::pluck('valor', 'clave')->all();
            } catch (QueryException $e) {
                /*
                 * La tabla puede no existir todavía: en un despliegue el código
                 * nuevo llega antes que `migrate --force`. Como cada parámetro
                 * tiene un valor de fábrica, aquí se puede seguir con ellos en
                 * vez de tumbar toda la aplicación por un ajuste opcional.
                 */
                Log::warning('No se pudieron leer los parámetros maestros; se usan los valores de fábrica.', [
                    'excepcion' => $e->getMessage(),
                ]);

                return [];
            }
        });
    }

    private function convertir(string $valor, string $tipo): int|float
    {
        // Los administradores también teclean con coma decimal.
        $valor = str_replace(',', '.', trim($valor));

        return $tipo === self::TIPO_ENTERO ? (int) round((float) $valor) : (float) $valor;
    }
}
