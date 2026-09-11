<?php

namespace App\Services;

use App\Models\RecomendacionSistema;
use App\Models\RegistroDiario;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Seguimiento periódico del usuario (CLAUDE.md sección 4.17): cómo evoluciona
 * semana a semana a partir de sus planes diarios, y qué ajustes le ha ido
 * proponiendo el sistema.
 *
 * Es la mitad "histórica" de la pantalla de inicio, complementaria a la
 * fotografía de hoy y a los promedios móviles de TrendAnalyticsService: aquella
 * responde "¿cómo voy?" y esta "¿qué ha ido pasando y qué he ido cambiando?".
 *
 * No calcula ninguna fórmula nutricional ni decide ningún ajuste: agrega lo que
 * ya está persistido en `registros_diarios` (que rellena el cierre diario) y en
 * `recomendaciones_sistema` (que rellena RulesEngineService). Solo lee.
 */
class SeguimientoService
{
    /**
     * Días de una "semana" de seguimiento. Coincide con la ventana móvil de
     * TrendAnalyticsService: las dos miran el mismo tamaño de bloque, así que
     * las cifras de las dos secciones de la pantalla son comparables.
     */
    public const DIAS_POR_SEMANA = TrendAnalyticsService::DIAS_VENTANA;

    /**
     * Una fila por semana, de la más reciente a la más antigua, con la
     * adherencia y el balance energético de esos días.
     *
     * La variación de peso de cada semana se calcula contra el promedio de la
     * semana inmediatamente anterior; es `null` cuando falta el peso en
     * cualquiera de las dos, en vez de compararse contra un hueco.
     *
     * @return array<int, array{inicio: string, fin: string, dias_con_plan: int, dias_cerrados: int, adherencia_pct: float, comidas_planificadas: int, comidas_registradas: int, promedio_peso_kg: float|null, variacion_peso_kg: float|null, promedio_calorias_consumidas: float|null, promedio_deficit_kcal: float|null}>
     */
    public function resumenSemanal(User $usuario, int $semanas = 6, ?Carbon $hasta = null): array
    {
        $fin = ($hasta instanceof Carbon ? $hasta->copy() : Carbon::now())->startOfDay();

        // Se trae una semana de más: hace falta para la variación de peso de la
        // semana más antigua que sí se devuelve.
        $bloques = $semanas + 1;
        $registros = $this->registros($usuario, $fin->copy()->subDays($bloques * self::DIAS_POR_SEMANA - 1), $fin);

        $filas = [];

        for ($i = 0; $i < $bloques; $i++) {
            $finSemana = $fin->copy()->subDays($i * self::DIAS_POR_SEMANA);
            $inicioSemana = $finSemana->copy()->subDays(self::DIAS_POR_SEMANA - 1);

            $filas[] = $this->fila($registros, $inicioSemana, $finSemana);
        }

        // La variación se resuelve aquí, ya con todas las filas construidas.
        foreach ($filas as $indice => $fila) {
            $anterior = $filas[$indice + 1]['promedio_peso_kg'] ?? null;

            $filas[$indice]['variacion_peso_kg'] = $fila['promedio_peso_kg'] !== null && $anterior !== null
                ? round($fila['promedio_peso_kg'] - $anterior, 2)
                : null;
        }

        return array_slice($filas, 0, $semanas);
    }

    /**
     * La fecha del RegistroDiario más antiguo del usuario, o null si nunca
     * tuvo ninguno. Es lo que decide si ya pasó una semana completa desde que
     * empezó a usar la app: sin eso, "Tu seguimiento" mostraría un puñado de
     * semanas casi vacías desde el segundo día de uso (sección 5.8).
     */
    public function primerDiaRegistrado(User $usuario): ?Carbon
    {
        $registro = RegistroDiario::where('usuario_id', $usuario->id)
            ->orderBy('fecha')
            ->first();

        return $registro?->fecha;
    }

    /**
     * Historial de lo que el sistema le ha propuesto al usuario y qué hizo con
     * cada propuesta — el rastro de cómo se ha ido moviendo su objetivo.
     *
     * @return Collection<int, RecomendacionSistema>
     */
    public function historialRecomendaciones(User $usuario, int $limite = 12): Collection
    {
        return RecomendacionSistema::whereHas(
            'registroDiario',
            fn ($consulta) => $consulta->where('usuario_id', $usuario->id),
        )
            ->with('registroDiario:id,fecha')
            ->latest()
            ->limit($limite)
            ->get();
    }

    /**
     * Los RegistroDiario del rango, indexados por fecha, con el conteo de
     * comidas planificadas y registradas de cada día.
     *
     * @return Collection<string, RegistroDiario>
     */
    private function registros(User $usuario, Carbon $desde, Carbon $hasta): Collection
    {
        // `whereDate` y no `whereBetween`: la columna guarda la fecha con hora
        // 00:00:00, y comparar como cadena dejaría fuera el propio día de corte
        // (mismo criterio que TrendAnalyticsService::registrosEntre).
        return RegistroDiario::where('usuario_id', $usuario->id)
            ->whereDate('fecha', '>=', $desde->toDateString())
            ->whereDate('fecha', '<=', $hasta->toDateString())
            ->withCount([
                'planesComida as comidas_planificadas',
                'planesComida as comidas_registradas' => fn ($consulta) => $consulta->has('comidaReal'),
            ])
            ->get()
            ->keyBy(fn (RegistroDiario $registro): string => $registro->fecha->toDateString());
    }

    /**
     * @param  Collection<string, RegistroDiario>  $registros
     * @return array{inicio: string, fin: string, dias_con_plan: int, dias_cerrados: int, adherencia_pct: float, comidas_planificadas: int, comidas_registradas: int, promedio_peso_kg: float|null, variacion_peso_kg: float|null, promedio_calorias_consumidas: float|null, promedio_deficit_kcal: float|null}
     */
    private function fila(Collection $registros, Carbon $inicio, Carbon $fin): array
    {
        $semana = $registros->filter(
            fn (RegistroDiario $registro): bool => $registro->fecha->betweenIncluded($inicio, $fin),
        );

        $cerrados = $semana->where('cerrado', true);

        return [
            'inicio' => $inicio->toDateString(),
            'fin' => $fin->toDateString(),
            'dias_con_plan' => $semana->count(),
            'dias_cerrados' => $cerrados->count(),
            // Mismo divisor que el índice de consistencia (sección 4.7): los 7
            // días naturales de la semana, no los días con registro.
            'adherencia_pct' => round($cerrados->count() / self::DIAS_POR_SEMANA * 100, 1),
            'comidas_planificadas' => (int) $semana->sum('comidas_planificadas'),
            'comidas_registradas' => (int) $semana->sum('comidas_registradas'),
            'promedio_peso_kg' => $this->promedio($semana, 'peso_kg'),
            'variacion_peso_kg' => null,
            'promedio_calorias_consumidas' => $this->promedio($cerrados, 'calorias_consumidas'),
            'promedio_deficit_kcal' => $this->promedio($cerrados, 'deficit_diario'),
        ];
    }

    /**
     * Promedio de una columna ignorando los días sin valor: un día sin pesarse
     * no cuenta como cero ni en el numerador ni en el divisor (sección 4.7).
     *
     * @param  Collection<int|string, RegistroDiario>  $registros
     */
    private function promedio(Collection $registros, string $columna): ?float
    {
        $valores = $registros
            ->map(fn (RegistroDiario $registro) => $registro->{$columna})
            ->filter(fn ($valor): bool => $valor !== null)
            ->map(fn ($valor): float => (float) $valor);

        return $valores->isEmpty() ? null : round($valores->avg(), 2);
    }
}
