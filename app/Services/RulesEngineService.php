<?php

namespace App\Services;

use App\Exceptions\RecomendacionYaProcesadaException;
use App\Models\RecomendacionSistema;
use App\Models\RegistroDiario;
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

    /**
     * Umbrales de la sección 6, públicos para que TrendAnalyticsService clasifique
     * la tendencia con los mismos números y no los duplique.
     */
    public const UMBRAL_PERDIDA_LENTA_PCT = 0.5;

    public const UMBRAL_PERDIDA_RAPIDA_PCT = 1.0;

    /**
     * Punto medio del rango 100–200 kcal que exige la sección 6. Un solo valor
     * fijo evita introducir otra variable de configuración que el MVP no pidió.
     */
    private const AJUSTE_KCAL_SUGERIDO = 150.0;

    /**
     * Variación semanal de peso, en kg, por debajo de la cual una semana se
     * considera "sin cambios" a efectos de detectar estancamiento.
     */
    private const UMBRAL_ESTANCAMIENTO_KG = 0.2;

    /**
     * Semanas consecutivas por debajo del umbral necesarias para alertar.
     */
    private const SEMANAS_ESTANCAMIENTO = 3;

    /**
     * Decide qué dirección de ajuste corresponde, si alguna, según la regla
     * literal de la sección 6. Público para poder testear la regla sola, sin
     * pasar por la persistencia.
     */
    public function evaluarTendenciaPeso(float $porcentajePerdidaSemanal): ?string
    {
        if ($porcentajePerdidaSemanal < self::UMBRAL_PERDIDA_LENTA_PCT) {
            return 'reducir';
        }

        if ($porcentajePerdidaSemanal > self::UMBRAL_PERDIDA_RAPIDA_PCT) {
            return 'aumentar';
        }

        return null;
    }

    /**
     * Genera y persiste una RecomendacionSistema de tipo ajuste_calorico si la
     * tendencia lo justifica, o null si la pérdida semanal está dentro del
     * rango esperado (0.5%–1%). Nunca modifica calorias_objetivo del usuario:
     * eso solo ocurre al confirmar (ver confirmar()).
     */
    public function generarRecomendacionAjusteCalorico(RegistroDiario $registroDiario, float $porcentajePerdidaSemanal): ?RecomendacionSistema
    {
        $direccion = $this->evaluarTendenciaPeso($porcentajePerdidaSemanal);

        if ($direccion === null) {
            return null;
        }

        $usuario = $registroDiario->usuario;
        $caloriasActuales = (float) $usuario->calorias_objetivo;
        $caloriasSugeridas = $direccion === 'reducir'
            ? $caloriasActuales - self::AJUSTE_KCAL_SUGERIDO
            : $caloriasActuales + self::AJUSTE_KCAL_SUGERIDO;

        $mensaje = $direccion === 'reducir'
            ? sprintf(
                'Tu pérdida de peso promedio de las últimas semanas es %.2f%% semanal, por debajo del 0.5%% recomendado. '.
                'Sugerimos reducir tu objetivo calórico en %d kcal: de %s a %s kcal.',
                $porcentajePerdidaSemanal,
                self::AJUSTE_KCAL_SUGERIDO,
                number_format($caloriasActuales, 0),
                number_format($caloriasSugeridas, 0),
            )
            : sprintf(
                'Tu pérdida de peso promedio de las últimas semanas es %.2f%% semanal, por encima del 1%% recomendado. '.
                'Sugerimos aumentar tu objetivo calórico en %d kcal: de %s a %s kcal.',
                $porcentajePerdidaSemanal,
                self::AJUSTE_KCAL_SUGERIDO,
                number_format($caloriasActuales, 0),
                number_format($caloriasSugeridas, 0),
            );

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
        if (count($variacionesPesoKg) < self::SEMANAS_ESTANCAMIENTO) {
            return null;
        }

        $ultimasSemanas = array_slice($variacionesPesoKg, -self::SEMANAS_ESTANCAMIENTO);

        foreach ($ultimasSemanas as $variacion) {
            if (abs($variacion) >= self::UMBRAL_ESTANCAMIENTO_KG) {
                return null;
            }
        }

        $mensaje = sprintf(
            'Tu peso se ha mantenido prácticamente igual (variación menor a %.1f kg) durante las últimas %d semanas. '.
            'Esto puede indicar un estancamiento; es solo informativo, no cambia tu objetivo calórico automáticamente.',
            self::UMBRAL_ESTANCAMIENTO_KG,
            self::SEMANAS_ESTANCAMIENTO,
        );

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
