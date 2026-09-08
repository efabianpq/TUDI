<?php

namespace App\Services;

use App\Exceptions\RecomendacionYaProcesadaException;
use App\Models\RecomendacionSistema;
use App\Models\RegistroDiario;
use App\Services\AI\NutritionAiProviderInterface;
use Illuminate\Support\Facades\DB;

/**
 * Motor de recomendaciones (sección 6 de CLAUDE.md). Traduce promedios móviles
 * de 7 días ya calculados (peso, % de pérdida semanal) en RecomendacionSistema
 * pendientes de confirmación — nunca aplica un ajuste de calorias_objetivo por
 * sí solo. La fuente de esos promedios (TrendAnalyticsService / Prompt 11)
 * todavía no existe; este servicio recibe el porcentaje ya calculado y solo
 * decide si corresponde una recomendación, la redacta y la persiste.
 */
class RulesEngineService
{
    public const TIPO_AJUSTE_CALORICO = 'ajuste_calorico';

    public const TIPO_ALERTA_ESTANCAMIENTO = 'alerta_estancamiento';

    /*
     * ── Valores de fábrica de los umbrales de la sección 6 ──────────────────
     *
     * Siguen siendo constantes públicas porque son la definición del
     * comportamiento por defecto y el catálogo de ParametrosMaestrosService las
     * declara como tal (`defecto`). Lo que el motor lee en tiempo de ejecución
     * NO son estas constantes sino los métodos de abajo, que devuelven el valor
     * vigente: el administrador puede haberlos movido desde la consola
     * (CLAUDE.md sección 4.27).
     */

    /** Por debajo de este % de pérdida semanal se sugiere reducir el objetivo. */
    public const UMBRAL_PERDIDA_LENTA_PCT = 0.5;

    /** Por encima de este % de pérdida semanal se sugiere aumentarlo. */
    public const UMBRAL_PERDIDA_RAPIDA_PCT = 1.0;

    /**
     * Punto medio del rango 100–200 kcal que exige la sección 6. Un solo valor
     * evita preguntarle al usuario una cifra exacta que el MVP no pidió.
     */
    public const AJUSTE_KCAL_SUGERIDO = 150.0;

    /**
     * Variación semanal de peso, en kg, por debajo de la cual una semana se
     * considera "sin cambios" a efectos de detectar estancamiento.
     */
    public const UMBRAL_ESTANCAMIENTO_KG = 0.2;

    /**
     * Semanas consecutivas por debajo del umbral necesarias para alertar.
     */
    public const SEMANAS_ESTANCAMIENTO = 3;

    public function __construct(
        private readonly NutritionAiProviderInterface $aiProvider,
        private readonly ParametrosMaestrosService $parametros,
    ) {}

    /**
     * Los cinco umbrales de arriba son ajustables desde la consola de
     * administración (CLAUDE.md sección 4.27); las constantes siguen siendo su
     * valor de fábrica y el que devuelve el catálogo por defecto. Se leen por
     * método y no por constante para que un cambio del administrador surta
     * efecto sin desplegar.
     */
    public function umbralPerdidaLentaPct(): float
    {
        return (float) $this->parametros->valor('recomendaciones_umbral_perdida_lenta_pct');
    }

    public function umbralPerdidaRapidaPct(): float
    {
        return (float) $this->parametros->valor('recomendaciones_umbral_perdida_rapida_pct');
    }

    public function ajusteKcalSugerido(): float
    {
        return (float) $this->parametros->valor('recomendaciones_ajuste_kcal');
    }

    public function umbralEstancamientoKg(): float
    {
        return (float) $this->parametros->valor('recomendaciones_umbral_estancamiento_kg');
    }

    public function semanasEstancamiento(): int
    {
        return (int) $this->parametros->valor('recomendaciones_semanas_estancamiento');
    }

    /**
     * Decide qué dirección de ajuste corresponde, si alguna, según la regla
     * literal de la sección 6. Público para poder testear la regla sola, sin
     * pasar por la persistencia.
     */
    public function evaluarTendenciaPeso(float $porcentajePerdidaSemanal): ?string
    {
        if ($porcentajePerdidaSemanal < $this->umbralPerdidaLentaPct()) {
            return 'reducir';
        }

        if ($porcentajePerdidaSemanal > $this->umbralPerdidaRapidaPct()) {
            return 'aumentar';
        }

        return null;
    }

    /**
     * Genera y persiste una RecomendacionSistema de tipo ajuste_calorico si la
     * tendencia lo justifica, o null si la pérdida semanal está dentro del
     * rango esperado (0.5%–1%). Nunca modifica calorias_objetivo del usuario:
     * eso solo ocurre al confirmar (ver confirmar()).
     *
     * Tampoco genera nada si el usuario todavía no tiene un objetivo calórico
     * vigente: sin una cifra de partida, `(float) null` valdría 0 y se sugeriría
     * un objetivo negativo (ver CLAUDE.md sección 4.10). No hay ajuste posible
     * sobre un objetivo que aún no existe.
     */
    public function generarRecomendacionAjusteCalorico(RegistroDiario $registroDiario, float $porcentajePerdidaSemanal): ?RecomendacionSistema
    {
        $direccion = $this->evaluarTendenciaPeso($porcentajePerdidaSemanal);

        if ($direccion === null) {
            return null;
        }

        $usuario = $registroDiario->usuario;
        $caloriasActuales = (float) $usuario->calorias_objetivo;

        if ($caloriasActuales <= 0.0) {
            return null;
        }

        $caloriasSugeridas = $direccion === 'reducir'
            ? $caloriasActuales - $this->ajusteKcalSugerido()
            : $caloriasActuales + $this->ajusteKcalSugerido();

        $mensaje = $this->aiProvider->generarTextoRecomendacion(self::TIPO_AJUSTE_CALORICO, [
            'direccion' => $direccion,
            'porcentaje_perdida_semanal' => $porcentajePerdidaSemanal,
            'calorias_actuales' => $caloriasActuales,
            'calorias_sugeridas' => $caloriasSugeridas,
            'ajuste_kcal_sugerido' => $this->ajusteKcalSugerido(),
        ]);

        return RecomendacionSistema::create([
            'registro_diario_id' => $registroDiario->id,
            'tipo' => self::TIPO_AJUSTE_CALORICO,
            'calorias_objetivo_sugeridas' => $caloriasSugeridas,
            'justificacion' => $mensaje,
            'estado' => 'pendiente',
        ]);
    }

    /**
     * Alerta informativa de estancamiento: variación de peso por debajo del
     * umbral durante varias semanas consecutivas. No lleva calorias_objetivo_
     * sugeridas ni acción automática asociada — es solo para que el usuario lo
     * vea y decida, junto con quien lo asesore, si conviene revisar el plan.
     *
     * @param  array<int, float>  $variacionesPesoKg  Variación de peso (kg, con signo) semana a semana, de la más antigua a la más reciente.
     */
    public function detectarEstancamiento(RegistroDiario $registroDiario, array $variacionesPesoKg): ?RecomendacionSistema
    {
        if (count($variacionesPesoKg) < $this->semanasEstancamiento()) {
            return null;
        }

        $ultimasSemanas = array_slice($variacionesPesoKg, -$this->semanasEstancamiento());

        foreach ($ultimasSemanas as $variacion) {
            if (abs($variacion) >= $this->umbralEstancamientoKg()) {
                return null;
            }
        }

        $mensaje = $this->aiProvider->generarTextoRecomendacion(self::TIPO_ALERTA_ESTANCAMIENTO, [
            'umbral_estancamiento_kg' => $this->umbralEstancamientoKg(),
            'semanas_estancamiento' => $this->semanasEstancamiento(),
        ]);

        return RecomendacionSistema::create([
            'registro_diario_id' => $registroDiario->id,
            'tipo' => self::TIPO_ALERTA_ESTANCAMIENTO,
            'calorias_objetivo_sugeridas' => null,
            'justificacion' => $mensaje,
            'estado' => 'pendiente',
        ]);
    }

    /**
     * Único camino por el que una RecomendacionSistema llega a modificar
     * calorias_objetivo del usuario — y solo si es de tipo ajuste_calorico.
     * Una alerta de estancamiento se puede "confirmar" (queda registrada como
     * vista) pero nunca toca calorias_objetivo, porque no trae una cifra
     * sugerida que aplicar.
     */
    public function confirmar(RecomendacionSistema $recomendacion): RecomendacionSistema
    {
        if ($recomendacion->estado !== 'pendiente') {
            throw RecomendacionYaProcesadaException::alConfirmar($recomendacion->id);
        }

        DB::transaction(function () use ($recomendacion) {
            $recomendacion->update([
                'estado' => 'confirmada',
                'confirmada_en' => now(),
            ]);

            if ($recomendacion->tipo === self::TIPO_AJUSTE_CALORICO && $recomendacion->calorias_objetivo_sugeridas !== null) {
                $recomendacion->registroDiario->usuario->update([
                    'calorias_objetivo' => $recomendacion->calorias_objetivo_sugeridas,
                ]);
            }
        });

        return $recomendacion->refresh();
    }

    public function rechazar(RecomendacionSistema $recomendacion): RecomendacionSistema
    {
        if ($recomendacion->estado !== 'pendiente') {
            throw RecomendacionYaProcesadaException::alRechazar($recomendacion->id);
        }

        $recomendacion->update(['estado' => 'rechazada']);

        return $recomendacion->refresh();
    }
}
