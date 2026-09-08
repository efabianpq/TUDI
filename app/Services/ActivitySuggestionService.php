<?php

namespace App\Services;

use App\Models\User;

/**
 * Propone la actividad física del día a partir del resultado de la Calculadora
 * Déficit (CLAUDE.md sección 4.13).
 *
 * Es puramente sugerencia: no persiste nada, no toca `calorias_objetivo` y no
 * genera ninguna RecomendacionSistema. Lo que el usuario haga de verdad se
 * registra como ActividadFisica y se corrige con ActivityCorrectionService,
 * como hasta ahora.
 */
class ActivitySuggestionService
{
    /**
     * Equivalentes metabólicos (MET) por tipo de actividad, a intensidad
     * moderada. Valores del Compendium of Physical Activities, redondeados.
     *
     * Los tipos coinciden con los de ActivityCorrectionService::FACTORES_POR_TIPO
     * para que lo sugerido se pueda registrar tal cual y reciba el factor de
     * corrección correcto.
     *
     * @var array<string, float>
     */
    public const MET_POR_TIPO = [
        'caminata' => 3.5,
        'trote' => 8.0,
        'ciclismo' => 6.0,
        'natación' => 7.0,
        'pesas' => 5.0,
    ];

    /**
     * Qué parte del déficit dietético del día se propone cubrir con actividad.
     *
     * Se deriva del propio déficit del usuario (mantenimiento − objetivo
     * vigente) en vez de ser una cifra fija: así un plan agresivo pide más
     * actividad que uno conservador, sin que haga falta un parámetro nuevo en
     * el perfil. 40% es el punto medio de la práctica habitual de repartir el
     * déficit entre dieta y ejercicio sin que el ejercicio domine el plan.
     */
    public const PROPORCION_DEL_DEFICIT = 0.4;

    /**
     * Suelo y techo del objetivo de actividad, en kcal. Acotan la sugerencia
     * entre "una caminata corta" y "una sesión exigente": por debajo de 150
     * kcal la recomendación no aporta nada y por encima de 600 deja de ser
     * sostenible a diario para la mayoría.
     */
    public const OBJETIVO_MINIMO_KCAL = 150.0;

    public const OBJETIVO_MAXIMO_KCAL = 600.0;

    /**
     * Tope de duración sugerida. Cuando una actividad poco intensa (caminar)
     * necesitaría más tiempo del razonable, se recorta aquí y se informan las
     * calorías que esa duración recortada sí quema — nunca las del objetivo,
     * para no prometer un gasto que la sugerencia no alcanza.
     */
    public const DURACION_MAXIMA_MIN = 90;

    public function __construct(
        private readonly NutritionCalculatorService $calculadora,
        private readonly ParametrosMaestrosService $parametros,
    ) {}

    /**
     * Las cuatro cifras de arriba son ajustables desde la consola de
     * administración (CLAUDE.md sección 4.27); las constantes públicas siguen
     * siendo su valor de fábrica y el que devuelve el catálogo por defecto.
     */
    public function proporcionDelDeficit(): float
    {
        return (float) $this->parametros->valor('actividad_proporcion_del_deficit');
    }

    public function objetivoMinimoKcal(): float
    {
        return (float) $this->parametros->valor('actividad_kcal_minimas');
    }

    public function objetivoMaximoKcal(): float
    {
        return (float) $this->parametros->valor('actividad_kcal_maximas');
    }

    public function duracionMaximaMin(): int
    {
        return (int) $this->parametros->valor('actividad_duracion_maxima_min');
    }

    /**
     * Plan de actividad sugerido para el día.
     *
     * @param  float  $caloriasObjetivoDia  objetivo calórico vigente (el mismo que dimensiona el plan de comidas)
     * @return array{deficit_dieta_kcal: float, calorias_objetivo_actividad: float, sugerencias: array<int, array{tipo: string, duracion_min: int, calorias_estimadas: float, factor_correccion: float, alcanza_objetivo: bool}>}
     */
    public function sugerir(User $usuario, float $caloriasObjetivoDia): array
    {
        $pesoKg = (float) $usuario->peso_kg;

        $mantenimiento = $this->calculadora->calculateMaintenanceCalories(
            $pesoKg,
            (float) $usuario->nivel_actividad,
        );

        $deficitDieta = max(0.0, $mantenimiento - $caloriasObjetivoDia);

        $objetivoActividad = min(
            $this->objetivoMaximoKcal(),
            max($this->objetivoMinimoKcal(), $deficitDieta * $this->proporcionDelDeficit()),
        );

        $sugerencias = [];

        foreach (self::MET_POR_TIPO as $tipo => $met) {
            $sugerencias[] = $this->sugerenciaPara($tipo, $met, $pesoKg, $objetivoActividad);
        }

        return [
            'deficit_dieta_kcal' => round($deficitDieta, 2),
            'calorias_objetivo_actividad' => round($objetivoActividad, 2),
            'sugerencias' => $sugerencias,
        ];
    }

    /**
     * kcal/min = MET × 3.5 × peso_kg / 200 — la fórmula estándar del
     * Compendium para pasar de MET a gasto absoluto.
     *
     * @return array{tipo: string, duracion_min: int, calorias_estimadas: float, factor_correccion: float, alcanza_objetivo: bool}
     */
    private function sugerenciaPara(string $tipo, float $met, float $pesoKg, float $objetivoKcal): array
    {
        $kcalPorMinuto = $met * 3.5 * $pesoKg / 200;
        $duracionMaxima = $this->duracionMaximaMin();

        $duracionNecesaria = $kcalPorMinuto > 0 ? (int) ceil($objetivoKcal / $kcalPorMinuto) : $duracionMaxima;
        $duracion = min($duracionMaxima, max(1, $duracionNecesaria));

        return [
            'tipo' => $tipo,
            'duracion_min' => $duracion,
            'calorias_estimadas' => round($kcalPorMinuto * $duracion, 2),
            'factor_correccion' => ActivityCorrectionService::FACTORES_POR_TIPO[$tipo]
                ?? ActivityCorrectionService::FACTOR_POR_DEFECTO,
            'alcanza_objetivo' => $duracionNecesaria <= $duracionMaxima,
        ];
    }
}
