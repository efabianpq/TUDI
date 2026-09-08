<?php

namespace App\Services;

use App\Models\MetricaTendencia;
use App\Models\RegistroDiario;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Analítica de tendencias: promedios móviles de 7 días y adherencia del usuario.
 *
 * Es la fuente de los números que la sección 6 exige para ajustar el objetivo
 * calórico (nunca un día aislado). No decide nada por sí mismo: solo calcula y
 * persiste el snapshot; quien traduce esas cifras en una RecomendacionSistema
 * pendiente de confirmación es RulesEngineService.
 *
 * ── Por qué el promedio se calcula en PHP y no con AVG() OVER (...) ──────────
 *
 * La sección 10 de CLAUDE.md pide verificar la versión real del motor antes de
 * usar funciones de ventana. En desarrollo es MySQL 8.0.30 (comprobado con
 * `php artisan db:show`), que sí las soporta, pero:
 *
 *  1. el objetivo de despliegue es un hosting compartido de Hostinger cuya
 *     versión de MySQL/MariaDB no está garantizada ni bajo nuestro control, y
 *  2. la suite de tests corre sobre SQLite en memoria (phpunit.xml).
 *
 * Como la ventana son 7 filas por usuario, promediarlas en PHP no tiene coste
 * apreciable y el mismo código funciona en los tres motores. Si algún día se
 * fija la versión del servidor, este es el único sitio que habría que tocar.
 *
 * ── Definición del índice de consistencia ───────────────────────────────────
 *
 *   indice_consistencia_pct = días con RegistroDiario cerrado / 7 * 100
 *
 * El divisor es siempre 7 (los días naturales de la ventana, incluida la fecha
 * de corte), nunca el número de días con registro: un usuario que solo abrió y
 * cerró 2 días de los últimos 7 tiene 28.6% de consistencia, no 100%. Se cuenta
 * el cierre y no la mera existencia del RegistroDiario porque un registro se
 * crea solo con reportar un ingrediente, mientras que cerrarlo implica haber
 * registrado comidas y actividad: es la señal real de adherencia.
 */
class TrendAnalyticsService
{
    /**
     * Días naturales de la ventana móvil. Con registro diario coincide con "los
     * últimos 7 RegistroDiario"; si el usuario se saltó días, la ventana sigue
     * siendo de 7 días de calendario y los días ausentes cuentan como tales.
     */
    public const DIAS_VENTANA = 7;

    /**
     * Variación semanal de peso, en %, por debajo de la cual la tendencia se
     * considera "estable" en vez de pérdida o ganancia: ~0.08 kg para 80 kg,
     * dentro del ruido de una báscula doméstica.
     */
    private const UMBRAL_ESTABLE_PCT = 0.1;

    /**
     * Los umbrales de pérdida lenta/rápida con los que se clasifica la
     * tendencia son los mismos que aplica el motor de recomendaciones, y el
     * administrador puede haberlos movido (CLAUDE.md sección 4.27): se leen del
     * mismo sitio para que las dos pantallas nunca discrepen.
     */
    public function __construct(
        private readonly ParametrosMaestrosService $parametros,
    ) {}

    /**
     * Las tres métricas de la ventana que termina en $fechaCorte, más el
     * contexto necesario para saber si son de fiar.
     *
     * Nunca lanza por falta de datos: con menos de 7 días de historial devuelve
     * `datos_suficientes => false` y los promedios calculados sobre lo que haya
     * (o null si no hay ni un dato). El índice de consistencia siempre es un
     * número: cero días cerrados de siete es 0%, no "desconocido".
     *
     * @return array{fecha_corte: string, promedio_movil_peso_kg: float|null, promedio_movil_calorias: float|null, promedio_movil_deficit_kcal: float|null, indice_consistencia_pct: float, dias_con_datos: int, dias_cerrados: int, dias_con_peso: int, dias_con_peso_anterior: int, datos_suficientes: bool, porcentaje_perdida_semanal: float|null, tendencia: string|null}
     */
    public function calcular(User $usuario, ?Carbon $fechaCorte = null): array
    {
        $corte = $this->normalizarFecha($fechaCorte);

        // Se traen 14 días de una sola vez: los 7 de la ventana actual y los 7
        // de la anterior, que hacen falta para el % de pérdida semanal.
        $registros = $this->registrosEntre($usuario, $corte->copy()->subDays(self::DIAS_VENTANA * 2 - 1), $corte);

        $ventana = $this->ventana($registros, $corte);
        $ventanaAnterior = $this->ventana($registros, $corte->copy()->subDays(self::DIAS_VENTANA));

        $pesoActual = $this->promedio($ventana, 'peso_kg');
        $pesoAnterior = $this->promedio($ventanaAnterior, 'peso_kg');
        $porcentajePerdida = $this->porcentajePerdidaSemanal($pesoAnterior, $pesoActual);

        $diasCerrados = $ventana->where('cerrado', true)->count();

        return [
            'fecha_corte' => $corte->toDateString(),
            'promedio_movil_peso_kg' => $pesoActual,
            'promedio_movil_calorias' => $this->promedio($ventana, 'calorias_consumidas'),
            'promedio_movil_deficit_kcal' => $this->promedio($ventana, 'deficit_diario'),
            'indice_consistencia_pct' => $this->indiceConsistencia($diasCerrados),
            'dias_con_datos' => $ventana->count(),
            'dias_cerrados' => $diasCerrados,
            // Días con peso registrado en cada ventana. No hacen falta los siete
            // —nadie se pesa a diario, y el promedio ignora los días sin dato—
            // pero sí al menos uno en cada una: sin eso no hay dos promedios que
            // comparar y el % de pérdida semanal es null (sección 5.7).
            'dias_con_peso' => $ventana->whereNotNull('peso_kg')->count(),
            'dias_con_peso_anterior' => $ventanaAnterior->whereNotNull('peso_kg')->count(),
            'datos_suficientes' => $ventana->count() >= self::DIAS_VENTANA,
            'porcentaje_perdida_semanal' => $porcentajePerdida,
            'tendencia' => $this->clasificarTendencia($porcentajePerdida),
        ];
    }

    /**
     * Calcula y guarda el snapshot: una sola fila de MetricaTendencia por
     * usuario y fecha de corte (índice único usuario_id + fecha).
     */
    public function calcularYPersistir(User $usuario, ?Carbon $fechaCorte = null): MetricaTendencia
    {
        $corte = $this->normalizarFecha($fechaCorte);
        $metricas = $this->calcular($usuario, $corte);

        $atributos = [
            'promedio_movil_peso_kg' => $this->redondear($metricas['promedio_movil_peso_kg']),
            'promedio_movil_calorias' => $this->redondear($metricas['promedio_movil_calorias']),
            'promedio_movil_deficit_kcal' => $this->redondear($metricas['promedio_movil_deficit_kcal']),
            'indice_consistencia_pct' => round($metricas['indice_consistencia_pct'], 2),
            'dias_con_datos' => $metricas['dias_con_datos'],
            'porcentaje_perdida_semanal' => $this->redondear($metricas['porcentaje_perdida_semanal']),
            'tendencia' => $metricas['tendencia'],
        ];

        // whereDate + update/create en vez de updateOrCreate: el cast `date` guarda
        // la fecha con hora en SQLite y la comparación cruda de updateOrCreate no
        // casa, duplicando la fila (mismo problema documentado en la sección 4.1).
        $existente = MetricaTendencia::where('usuario_id', $usuario->id)
            ->whereDate('fecha', $corte->toDateString())
            ->first();

        if ($existente) {
            $existente->update($atributos);

            return $existente->refresh();
        }

        return MetricaTendencia::create([
            'usuario_id' => $usuario->id,
            'fecha' => $corte->toDateString(),
            ...$atributos,
        ]);
    }

    /**
     * Serie del promedio móvil de peso para el gráfico: un punto por cada día
     * de los últimos $dias, cada uno con el promedio de su propia ventana de 7
     * días. Se resuelve con una sola consulta y las ventanas se recortan en
     * memoria, en vez de N consultas o una función de ventana SQL.
     *
     * @return array<int, array{fecha: string, promedio_movil_peso_kg: float|null, indice_consistencia_pct: float}>
     */
    public function serieHistorica(User $usuario, int $dias = 30, ?Carbon $fechaCorte = null): array
    {
        $corte = $this->normalizarFecha($fechaCorte);
        $dias = max(1, $dias);

        $registros = $this->registrosEntre($usuario, $corte->copy()->subDays($dias + self::DIAS_VENTANA - 2), $corte);

        $serie = [];

        for ($i = $dias - 1; $i >= 0; $i--) {
            $dia = $corte->copy()->subDays($i);
            $ventana = $this->ventana($registros, $dia);

            $serie[] = [
                'fecha' => $dia->toDateString(),
                'promedio_movil_peso_kg' => $this->promedio($ventana, 'peso_kg'),
                'indice_consistencia_pct' => $this->indiceConsistencia($ventana->where('cerrado', true)->count()),
            ];
        }

        return $serie;
    }

    /**
     * Variación de peso (kg, con signo) semana a semana, de la más antigua a
     * la más reciente — el insumo que pide RulesEngineService::detectarEstancamiento().
     * Cada variación es la diferencia entre dos promedios móviles de 7 días
     * consecutivos (no window functions, mismo criterio que el resto de la
     * clase): reutiliza el mismo promedio móvil que ya expone calcular(),
     * solo que muestreado cada 7 días hacia atrás en vez de una sola vez.
     *
     * Si algún promedio de la cadena falta (semana sin ningún peso registrado),
     * esa variación se omite en vez de comparar contra un dato inexistente —
     * detectarEstancamiento() solo actúa con al menos 3 variaciones reales, así
     * que un hueco en el historial produce menos variaciones y, como mucho, no
     * hay alerta; nunca una alerta calculada sobre un hueco.
     *
     * @return array<int, float>
     */
    public function variacionesSemanalesPesoKg(User $usuario, int $semanas = 3, ?Carbon $fechaCorte = null): array
    {
        $corte = $this->normalizarFecha($fechaCorte);
        $puntosNecesarios = $semanas + 1;

        $registros = $this->registrosEntre(
            $usuario,
            $corte->copy()->subDays(self::DIAS_VENTANA * $puntosNecesarios - 1),
            $corte,
        );

        $promedios = [];
        for ($i = $semanas; $i >= 0; $i--) {
            $fecha = $corte->copy()->subDays($i * self::DIAS_VENTANA);
            $promedios[] = $this->promedio($this->ventana($registros, $fecha), 'peso_kg');
        }

        $variaciones = [];
        for ($i = 1; $i < count($promedios); $i++) {
            if ($promedios[$i - 1] === null || $promedios[$i] === null) {
                continue;
            }

            $variaciones[] = $promedios[$i] - $promedios[$i - 1];
        }

        return $variaciones;
    }

    /**
     * Los RegistroDiario del usuario dentro de un rango de fechas, ambos
     * extremos incluidos. Se usa whereDate y no whereBetween porque el cast
     * `date` persiste la fecha con hora en SQLite y la comparación textual del
     * extremo superior fallaría.
     *
     * @return Collection<int, RegistroDiario>
     */
    private function registrosEntre(User $usuario, Carbon $desde, Carbon $hasta): Collection
    {
        return RegistroDiario::where('usuario_id', $usuario->id)
            ->whereDate('fecha', '>=', $desde->toDateString())
            ->whereDate('fecha', '<=', $hasta->toDateString())
            ->orderBy('fecha')
            ->get();
    }

    /**
     * Los registros que caen en la ventana de 7 días que termina en $corte.
     *
     * @param  Collection<int, RegistroDiario>  $registros
     * @return Collection<int, RegistroDiario>
     */
    private function ventana(Collection $registros, Carbon $corte): Collection
    {
        $inicio = $corte->copy()->subDays(self::DIAS_VENTANA - 1);

        return $registros->filter(
            fn (RegistroDiario $registro) => $registro->fecha->betweenIncluded($inicio, $corte)
        )->values();
    }

    /**
     * Índice de consistencia: días cerrados sobre los 7 de la ventana, siempre.
     * El divisor fijo es lo que hace que "0 de 7" sea 0% y no un dato ausente.
     */
    private function indiceConsistencia(int $diasCerrados): float
    {
        return (float) ($diasCerrados / self::DIAS_VENTANA * 100);
    }

    /**
     * Promedio de una columna sobre los días de la ventana que sí la tienen
     * rellena. Los días sin dato no cuentan ni como cero ni en el divisor;
     * si no hay ninguno, no hay promedio que devolver.
     *
     * @param  Collection<int, RegistroDiario>  $ventana
     */
    private function promedio(Collection $ventana, string $columna): ?float
    {
        $valores = $ventana
            ->pluck($columna)
            ->reject(fn ($valor) => $valor === null)
            ->map(fn ($valor) => (float) $valor);

        return $valores->isEmpty() ? null : $valores->sum() / $valores->count();
    }

    /**
     * Pérdida semanal en %, comparando el promedio móvil actual contra el de
     * hace 7 días: positivo = adelgazó, negativo = engordó. Null si falta
     * alguno de los dos promedios (no hay historial suficiente para comparar).
     */
    private function porcentajePerdidaSemanal(?float $pesoAnterior, ?float $pesoActual): ?float
    {
        if ($pesoAnterior === null || $pesoActual === null || $pesoAnterior <= 0.0) {
            return null;
        }

        return ($pesoAnterior - $pesoActual) / $pesoAnterior * 100;
    }

    /**
     * Traduce la pérdida semanal al enum `tendencia` de metricas_tendencia,
     * usando los mismos umbrales de la sección 6 que aplica RulesEngineService.
     */
    private function clasificarTendencia(?float $porcentajePerdidaSemanal): ?string
    {
        if ($porcentajePerdidaSemanal === null) {
            return null;
        }

        if (abs($porcentajePerdidaSemanal) <= self::UMBRAL_ESTABLE_PCT) {
            return 'estable';
        }

        if ($porcentajePerdidaSemanal < 0) {
            return 'ganancia';
        }

        if ($porcentajePerdidaSemanal < (float) $this->parametros->valor('recomendaciones_umbral_perdida_lenta_pct')) {
            return 'perdida_lenta';
        }

        return $porcentajePerdidaSemanal > (float) $this->parametros->valor('recomendaciones_umbral_perdida_rapida_pct')
            ? 'perdida_rapida'
            : 'perdida_adecuada';
    }

    private function normalizarFecha(?Carbon $fecha): Carbon
    {
        return ($fecha ? $fecha->copy() : now())->startOfDay();
    }

    private function redondear(?float $valor): ?float
    {
        return $valor === null ? null : round($valor, 2);
    }
}
