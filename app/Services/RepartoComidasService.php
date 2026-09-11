<?php

namespace App\Services;

use App\Models\ActividadFisica;
use App\Models\RegistroDiario;
use Illuminate\Support\Collection;

/**
 * Cómo se reparte el objetivo del día entre desayuno, almuerzo y cena
 * (CLAUDE.md sección 5.14).
 *
 * ── Por qué ya no lo teclea el usuario ─────────────────────────────────────
 *
 * Antes había un panel "Reparto del día" con tres porcentajes editables. Se
 * retiró: repartir el día es una decisión nutricional, no una preferencia de
 * interfaz, y pedirle a quien está aprendiendo a comer mejor que elija los
 * porcentajes es pedirle justo lo que ha venido a que le resuelvan. Ahora el
 * reparto se **deriva**, y solo de dos cosas:
 *
 *  1. **Un reparto balanceado de partida** — MealPlanGeneratorService::DISTRIBUCION_COMIDAS,
 *     30/40/30. Reparte el día sin dejar ninguna comida testimonial y sin
 *     cargar la cena, que es justo el hábito que se quiere corregir: quien
 *     desayuna poco llega a la noche con hambre y come de más cuando menos
 *     actividad le queda por delante.
 *  2. **La actividad física registrada ese día** — la comida que viene después
 *     de entrenar recibe más parte del día, que es cuando el cuerpo mejor
 *     aprovecha lo que entra. Cuánto más es proporcional a lo que se quemó, y
 *     está acotado por PUNTOS_MAXIMOS_ACTIVIDAD para que una sola sesión larga
 *     no desfigure el reparto.
 *
 * El reparto no cambia el objetivo del día, solo su forma: la suma sigue siendo
 * 1.0. Las calorías de la actividad se contabilizan donde siempre — en el
 * déficit del cierre (sección 7). Aquí no se reimplementa ninguna fórmula.
 *
 * ── Qué NO hace ────────────────────────────────────────────────────────────
 *
 * No recalcula ningún PlanComida ya generado: sus macros están persistidos. El
 * reparto dimensiona los objetivos que se muestran y el presupuesto de lo que
 * queda por generar, nunca lo ya resuelto. Y no se persiste en ninguna columna:
 * es una función pura del día, así que guardarlo solo abriría la puerta a que
 * la fila contradiga al cálculo.
 */
class RepartoComidasService
{
    /**
     * Hora de referencia de cada comida. No es una hora a la que haya que
     * comer: es el punto del día con el que se compara la hora de la actividad
     * para saber qué comida viene "después de entrenar".
     *
     * @var array<string, int>
     */
    public const HORA_DE_REFERENCIA = [
        'desayuno' => 8,
        'almuerzo' => 13,
        'cena' => 19,
    ];

    /**
     * Cuánto puede desplazar la actividad física el reparto, en proporción del
     * día: hasta 10 puntos que la comida posterior al entrenamiento le quita al
     * resto. Por encima, un día de mucho ejercicio dejaría las otras dos
     * comidas en nada.
     */
    public const PUNTOS_MAXIMOS_ACTIVIDAD = 0.10;

    /**
     * Proporción mínima que le puede quedar a una comida después del
     * desplazamiento. Con el balanceado de partida y el tope de arriba nunca se
     * alcanza; está para que ningún cambio futuro de esas constantes pueda
     * dejar una comida sin presupuesto que repartir.
     */
    public const PROPORCION_MINIMA = 0.15;

    /**
     * El reparto de partida, sin actividad de por medio.
     *
     * @return array<string, float>
     */
    public function balanceado(): array
    {
        return MealPlanGeneratorService::DISTRIBUCION_COMIDAS;
    }

    /**
     * El reparto vigente de un día concreto: el balanceado, desplazado hacia la
     * comida posterior a la actividad física que se haya registrado.
     *
     * @return array<string, float> proporciones (0–1) por comida, en el orden de DISTRIBUCION_COMIDAS
     */
    public function paraElDia(RegistroDiario $registroDiario): array
    {
        $ajuste = $this->ajustePorActividad($registroDiario);

        if ($ajuste === null) {
            return $this->balanceado();
        }

        return $this->desplazar($this->balanceado(), $ajuste['comida'], $ajuste['puntos']);
    }

    /**
     * Por qué el reparto de este día es el que es, para poder contarlo en
     * pantalla en una línea (nunca un párrafo de instrucciones — regla 8).
     *
     * @return array{comida: string|null, puntos: float, calorias_actividad: float, reparto: array<string, float>}
     */
    public function explicacion(RegistroDiario $registroDiario): array
    {
        $ajuste = $this->ajustePorActividad($registroDiario);

        return [
            'comida' => $ajuste['comida'] ?? null,
            'puntos' => $ajuste['puntos'] ?? 0.0,
            'calorias_actividad' => $ajuste['calorias_actividad'] ?? 0.0,
            'reparto' => $ajuste === null
                ? $this->balanceado()
                : $this->desplazar($this->balanceado(), $ajuste['comida'], $ajuste['puntos']),
        ];
    }

    /**
     * Qué comida se lleva el desplazamiento y de cuánto es.
     *
     * Devuelve null cuando no hay nada que desplazar: sin actividad registrada,
     * sin objetivo calórico con el que medirla, o sin ninguna comida abierta a
     * la que mover presupuesto (las cerradas ya tienen su ComidaReal y sus
     * macros no se tocan — sección 5.5).
     *
     * @return array{comida: string, puntos: float, calorias_actividad: float}|null
     */
    private function ajustePorActividad(RegistroDiario $registroDiario): ?array
    {
        $actividades = $registroDiario->relationLoaded('actividadesFisicas')
            ? $registroDiario->actividadesFisicas
            : $registroDiario->actividadesFisicas()->get();

        if ($actividades->isEmpty()) {
            return null;
        }

        $caloriasActividad = (float) $actividades->sum(
            fn (ActividadFisica $actividad): float => (float) $actividad->calorias_ajustadas,
        );

        $objetivoDia = (float) ($registroDiario->usuario->calorias_objetivo ?? 0);

        if ($caloriasActividad <= 0 || $objetivoDia <= 0) {
            return null;
        }

        $comida = $this->comidaPosteriorA($this->horaDeLaUltimaActividad($actividades), $registroDiario);

        if ($comida === null) {
            return null;
        }

        return [
            'comida' => $comida,
            'puntos' => min(self::PUNTOS_MAXIMOS_ACTIVIDAD, $caloriasActividad / $objetivoDia),
            'calorias_actividad' => $caloriasActividad,
        ];
    }

    /**
     * La hora del día a la que se entrenó. Las ActividadFisica no guardan hora
     * propia —se registran al terminar—, así que la de creación de la última es
     * la mejor aproximación disponible, y no obliga a una columna nueva.
     *
     * @param  Collection<int, ActividadFisica>  $actividades
     */
    private function horaDeLaUltimaActividad(Collection $actividades): int
    {
        $momento = $actividades
            ->map(fn (ActividadFisica $actividad) => $actividad->created_at)
            ->filter()
            ->max();

        return (int) ($momento?->copy()->setTimezone(config('app.timezone'))->hour ?? 0);
    }

    /**
     * La primera comida todavía abierta cuya hora de referencia cae en o
     * después de la actividad; si ya pasaron todas, la última que siga abierta
     * (entrenar de noche sigue teniendo a la cena como comida de recuperación).
     */
    private function comidaPosteriorA(int $hora, RegistroDiario $registroDiario): ?string
    {
        $cerradas = $registroDiario->planesComida()
            ->has('comidaReal')
            ->pluck('tipo_comida')
            ->all();

        $abiertas = array_values(array_filter(
            array_keys($this->balanceado()),
            fn (string $tipoComida): bool => ! in_array($tipoComida, $cerradas, true),
        ));

        if ($abiertas === []) {
            return null;
        }

        foreach ($abiertas as $tipoComida) {
            if ((self::HORA_DE_REFERENCIA[$tipoComida] ?? 0) >= $hora) {
                return $tipoComida;
            }
        }

        return end($abiertas);
    }

    /**
     * Mueve `$puntos` de proporción hacia `$comida`, quitándoselos al resto en
     * proporción a lo que cada una pesa. La suma sigue siendo 1.0 y ninguna
     * comida baja de PROPORCION_MINIMA.
     *
     * @param  array<string, float>  $reparto
     * @return array<string, float>
     */
    private function desplazar(array $reparto, string $comida, float $puntos): array
    {
        $restoTotal = array_sum($reparto) - $reparto[$comida];

        if ($restoTotal <= 0 || $puntos <= 0) {
            return $reparto;
        }

        // Nadie aporta más de lo que le sobra por encima del mínimo.
        $disponible = 0.0;

        foreach ($reparto as $tipoComida => $proporcion) {
            if ($tipoComida !== $comida) {
                $disponible += max(0.0, $proporcion - self::PROPORCION_MINIMA);
            }
        }

        $puntos = min($puntos, $disponible);

        if ($puntos <= 0) {
            return $reparto;
        }

        $desplazado = [];

        foreach ($reparto as $tipoComida => $proporcion) {
            $desplazado[$tipoComida] = $tipoComida === $comida
                ? $proporcion + $puntos
                : $proporcion - $puntos * ($proporcion / $restoTotal);
        }

        return $desplazado;
    }
}
