<?php

namespace App\Services;

use App\Exceptions\DayAlreadyClosedException;
use App\Exceptions\MealDistributionUnavailableException;
use App\Exceptions\ReporteComidaInvalidoException;
use App\Models\ComidaReal;
use App\Models\PlanComida;
use App\Models\RegistroDiario;
use App\Services\AI\MealDistributionProviderInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;

/**
 * Reporte y cierre de UNA comida (CLAUDE.md sección 5.5).
 *
 * ── Por qué una comida y no el día entero ──────────────────────────────────
 *
 * Antes esto era CierreFeedbackService y solo corría al cerrar el día: las tres
 * comidas se contaban de una vez, al final, en una sola llamada al proveedor.
 * El problema es que hasta ese momento el sistema no sabía nada de lo que ya se
 * había comido, así que el almuerzo y la cena se seguían dimensionando contra
 * el objetivo entero aunque el desayuno se hubiera ido 300 kcal por encima.
 *
 * Ahora cada comida se cierra por separado, en cuanto se come, y desde ese
 * momento sus cifras entran en el saldo del día: eso es lo que permite que
 * "Ajustar mi plan" reparta lo que de verdad queda (sección 5.3) y que el panel
 * de objetivo enseñe el saldo por macro (sección 5.21).
 *
 * ── Los cuatro caminos, y cuáles cuestan una llamada ───────────────────────
 *
 *  - **"Sí, cumplí lo sugerido"** — la ComidaReal se crea con los macros del
 *    propio PlanComida. Un clic, cero llamadas al proveedor.
 *  - **"Lo que sueles comer"** — el atajo escribe en el campo el texto de una
 *    comida anterior del usuario (ComidasFrecuentesService, sección 5.22). Si
 *    se envía tal cual, se copian sus macros: cero llamadas, porque ya se
 *    calcularon el día que se reportó. Si se edita, vale lo escrito.
 *  - **Contarlo por escrito** — "al final me comí un sándwich" pasa por el
 *    proveedor (`estimarConsumoReal`). Es el único camino que gasta cuota
 *    (sección 5.20).
 *  - **Sin plan previo** — se puede reportar una comida que nunca se planificó.
 *    Se crea un PlanComida con `origen = reporte` y macros a cero para colgar
 *    de él la ComidaReal: nada se planificó, y el historial "planificado vs.
 *    ejecutado" lo dice tal cual en vez de inventarse una sugerencia.
 *
 * En todos los casos las cifras que entran al balance energético se suman en
 * PHP a partir de los ingredientes (regla 7 de la sección 13) y se persisten
 * con ComidaRealService.
 */
class ReporteComidaService
{
    /**
     * Tipo de comida con el que se guardan los extras (sección 5.27).
     *
     * `snack` ya estaba en el enum de `planes_comida` desde la primera migración
     * y nunca se había usado, así que esto no añade ninguna columna ni ningún
     * valor nuevo. **A propósito NO está en DISTRIBUCION_COMIDAS**: esa constante
     * declara quién recibe parte del reparto del día, y un extra justamente no
     * recibe ninguna. Gracias a eso los chips de Inicio, el checklist de
     * diagnóstico y `comidasSinReportar()` siguen hablando de tres comidas sin
     * tocar una línea.
     */
    public const TIPO_EXTRA = 'snack';

    public function __construct(
        private readonly MealDistributionProviderInterface $proveedor,
        private readonly MealDistributionService $distribucion,
        private readonly ComidaRealService $comidaRealService,
        private readonly ComidasFrecuentesService $frecuentes,
    ) {}

    /**
     * Cierra una comida del día con lo que el usuario dice haber comido.
     *
     * @param  array{cumplio?: bool|string|null, texto?: string|null, imagen?: UploadedFile|null, repetir?: int|string|null}  $respuesta
     *
     * @throws ReporteComidaInvalidoException cuando la comida ya está cerrada o la respuesta no dice qué se comió
     * @throws MealDistributionUnavailableException cuando el proveedor no puede interpretar el texto
     * @throws DayAlreadyClosedException cuando el día ya está cerrado
     */
    public function reportar(RegistroDiario $registroDiario, string $tipoComida, array $respuesta): ComidaReal
    {
        $plan = $registroDiario->planesComida()
            ->with('comidaReal')
            ->where('tipo_comida', $tipoComida)
            ->first();

        if ($plan?->comidaReal !== null) {
            throw ReporteComidaInvalidoException::yaReportada($tipoComida);
        }

        $imagen = ($respuesta['imagen'] ?? null) instanceof UploadedFile ? $respuesta['imagen'] : null;
        $texto = isset($respuesta['texto']) ? trim((string) $respuesta['texto']) : '';
        $repetir = $respuesta['repetir'] ?? null;

        // "Lo que sueles comer" ya no es un camino aparte: es un atajo que
        // escribe en el campo el texto de una comida anterior (sección 5.22).
        // Si llega tal cual se copió, se reutilizan los macros de aquel reporte
        // —ya calculados— y no se llama a nadie; si el usuario lo editó, manda
        // lo que escribió y eso sí pasa por el proveedor.
        $repetida = filled($repetir)
            ? $this->frecuentes->deUsuario($registroDiario->usuario, (int) $repetir)
                ?? throw ReporteComidaInvalidoException::plantillaNoDisponible()
            : null;

        // El orden es el de fidelidad de la información, no el del formulario:
        // si contó por escrito algo distinto, eso manda sobre cualquier atajo.
        $datos = match (true) {
            $repetida !== null && ($texto === '' || $this->frecuentes->coincideCon($repetida, $texto)) => $this->desdeComidaFrecuente($repetida),
            $texto !== '' => $this->desdeTexto($registroDiario, $tipoComida, $plan, $texto),
            filter_var($respuesta['cumplio'] ?? false, FILTER_VALIDATE_BOOLEAN) => $this->desdePlan($tipoComida, $plan),
            default => throw ReporteComidaInvalidoException::sinContenido($tipoComida),
        };

        return $this->comidaRealService->registrar(
            $plan ?? $this->planDeReporte($registroDiario, $tipoComida),
            $datos,
            $imagen,
        );
    }

    /**
     * Registra un EXTRA del día: una cerveza, un postre de media tarde, unas
     * galletas (CLAUDE.md sección 5.27).
     *
     * ── En qué se diferencia de cerrar una comida ──────────────────────────
     *
     * Un extra no se planifica: pasa. De ahí las tres diferencias con
     * `reportar()`, y ninguna es cosmética:
     *
     *  - **No recibe presupuesto.** No entra en DISTRIBUCION_COMIDAS y por tanto
     *    no tiene su parte del reparto. Darle un 10 % del día convertiría a TUDI
     *    en cómplice —"tienes 200 kcal de cerveza asignadas hoy"— y además
     *    dejaría cortas a las tres comidas los días en que no se pica nada. Lo
     *    que hace es **gastar el saldo**: se registra, el saldo del día baja y
     *    "Calcular mi plan" reparte menos entre las comidas que faltan. La
     *    lección la da la aritmética sola, sin sermón (sección 5.21).
     *  - **No hay "cumplí lo sugerido".** No se sugirió nada que cumplir.
     *  - **Se pueden registrar varios al día**, así que no existe la guarda de
     *    "ya reportada": cada uno es su propia fila.
     *
     * @param  array{texto?: string|null, imagen?: UploadedFile|null, repetir?: int|string|null}  $respuesta
     *
     * @throws ReporteComidaInvalidoException cuando no se dice qué se consumió
     * @throws MealDistributionUnavailableException cuando el proveedor no puede interpretar el texto
     * @throws DayAlreadyClosedException cuando el día ya está cerrado
     */
    public function registrarExtra(RegistroDiario $registroDiario, array $respuesta): ComidaReal
    {
        $imagen = ($respuesta['imagen'] ?? null) instanceof UploadedFile ? $respuesta['imagen'] : null;
        $texto = isset($respuesta['texto']) ? trim((string) $respuesta['texto']) : '';
        $repetir = $respuesta['repetir'] ?? null;

        $repetida = filled($repetir)
            ? $this->frecuentes->deUsuario($registroDiario->usuario, (int) $repetir)
                ?? throw ReporteComidaInvalidoException::plantillaNoDisponible()
            : null;

        $datos = match (true) {
            $repetida !== null && ($texto === '' || $this->frecuentes->coincideCon($repetida, $texto)) => $this->desdeComidaFrecuente($repetida),
            $texto !== '' => $this->desdeTexto($registroDiario, self::TIPO_EXTRA, null, $texto),
            default => throw ReporteComidaInvalidoException::extraSinContenido(),
        };

        return $this->comidaRealService->registrar(
            $this->planDeExtra($registroDiario),
            $datos,
            $imagen,
        );
    }

    /**
     * Borra un extra del día.
     *
     * `ComidaRealService::eliminar()` ya hace todo lo que hace falta: se lleva la
     * imagen, borra la ComidaReal, **borra también el PlanComida por ser
     * `origen = reporte`** —sin su ComidaReal no queda nada dentro— y recalcula
     * las calorías consumidas del día. Aquí solo se comprueba que la fila sea de
     * verdad un extra de ese día.
     *
     * Igual que reabrir una comida, borrar un extra NO devuelve cuota de IA
     * (sección 5.20): si la devolviera, añadir y borrar el mismo extra sería una
     * llamada gratis infinita.
     *
     * @throws DayAlreadyClosedException cuando el día ya está cerrado
     */
    public function eliminarExtra(RegistroDiario $registroDiario, PlanComida $extra): bool
    {
        if ($extra->registro_diario_id !== $registroDiario->id || ! $this->esExtra($extra)) {
            return false;
        }

        return $this->comidaRealService->eliminar($extra);
    }

    /**
     * Los extras del día, del más reciente al más antiguo.
     *
     * @return Collection<int, PlanComida>
     */
    public function extrasDelDia(RegistroDiario $registroDiario)
    {
        return $registroDiario->planesComida()
            ->with('comidaReal')
            ->where('tipo_comida', self::TIPO_EXTRA)
            ->where('origen', PlanComida::ORIGEN_REPORTE)
            ->has('comidaReal')
            ->get()
            ->sortByDesc(fn (PlanComida $extra): string => (string) $extra->comidaReal?->consumido_en)
            ->values();
    }

    private function esExtra(PlanComida $plan): bool
    {
        return $plan->tipo_comida === self::TIPO_EXTRA && $plan->esReporteSinPlan();
    }

    /**
     * El PlanComida que sostiene un extra. Macros a cero, igual que cualquier
     * reporte sin plan: no se sugirió nada, y ponerle las cifras de lo consumido
     * lo haría parecer un plan cumplido al milímetro.
     */
    private function planDeExtra(RegistroDiario $registroDiario): PlanComida
    {
        return $registroDiario->planesComida()->create([
            'tipo_comida' => self::TIPO_EXTRA,
            'origen' => PlanComida::ORIGEN_REPORTE,
            'descripcion' => __('Extra'),
            'calorias_estimadas' => 0,
            'proteina_g' => 0,
            'grasa_g' => 0,
            'carbohidratos_g' => 0,
        ]);
    }

    /**
     * "Sí, cumplí lo sugerido": los macros del plan, tal cual.
     *
     * @return array{calorias_reales: float, proteina_g: float, grasa_g: float, carbohidratos_g: float, notas: string}
     */
    private function desdePlan(string $tipoComida, ?PlanComida $plan): array
    {
        if ($plan === null || $plan->esReporteSinPlan()) {
            throw ReporteComidaInvalidoException::sinPlan($tipoComida);
        }

        return [
            'calorias_reales' => (float) $plan->calorias_estimadas,
            'proteina_g' => (float) $plan->proteina_g,
            'grasa_g' => (float) $plan->grasa_g,
            'carbohidratos_g' => (float) $plan->carbohidratos_g,
            'notas' => __('Cumplí con lo sugerido.'),
        ];
    }

    /**
     * "Lo que sueles comer": los macros de un reporte anterior del propio
     * usuario, cuando lo que va a reportarse es esa misma comida sin cambios.
     * No pasa por el proveedor — esos macros ya se calcularon el día que se
     * reportó, y volver a preguntarlos costaría una llamada para obtener la
     * misma respuesta.
     *
     * @return array{calorias_reales: float, proteina_g: float, grasa_g: float, carbohidratos_g: float, notas: string}
     */
    private function desdeComidaFrecuente(ComidaReal $anterior): array
    {
        return [
            'calorias_reales' => (float) $anterior->calorias_reales,
            'proteina_g' => (float) $anterior->proteina_g,
            'grasa_g' => (float) $anterior->grasa_g,
            'carbohidratos_g' => (float) $anterior->carbohidratos_g,
            'notas' => mb_substr((string) ($anterior->notas ?: __('Repetí una comida frecuente.')), 0, 1000),
        ];
    }

    /**
     * "Cuéntame qué comiste": una llamada al proveedor, con esta comida sola.
     *
     * @return array{calorias_reales: float, proteina_g: float, grasa_g: float, carbohidratos_g: float, notas: string}
     */
    private function desdeTexto(RegistroDiario $registroDiario, string $tipoComida, ?PlanComida $plan, string $texto): array
    {
        $objetivos = $this->distribucion->objetivosDelRegistro($registroDiario);

        /*
         * `plan` va solo si de verdad hubo una sugerencia. Antes se mandaba
         * siempre, y sin plan previo salía un plan de ceros: eso no le dice al
         * modelo "no había plan", le dice "se le sugirió no comer nada", que es
         * una referencia falsa que además tira de la estimación hacia abajo. Un
         * extra (sección 5.27) nunca tiene plan, por definición.
         */
        $comida = ['texto' => $texto];

        if ($plan !== null && ! $plan->esReporteSinPlan()) {
            $comida['plan'] = [
                'descripcion' => (string) $plan->descripcion,
                'calorias' => (float) $plan->calorias_estimadas,
                'proteina_g' => (float) $plan->proteina_g,
                'grasa_g' => (float) $plan->grasa_g,
                'carbohidratos_g' => (float) $plan->carbohidratos_g,
            ];
        }

        $estimaciones = $this->proveedor->estimarConsumoReal(
            [$tipoComida => $comida],
            ['calorias_objetivo_dia' => $objetivos['dia']['calorias_objetivo']],
        );

        $estimacion = $estimaciones[$tipoComida] ?? throw MealDistributionUnavailableException::porRespuestaInvalida(
            "no se recibió ninguna estimación para el {$tipoComida}"
        );

        return $this->macrosDe($estimacion, $texto);
    }

    /**
     * El PlanComida que recibe un reporte de una comida que nunca se planificó.
     * Macros a cero a propósito: no se sugirió nada, y ponerle las cifras de lo
     * comido lo haría parecer un plan que se cumplió al milímetro.
     */
    private function planDeReporte(RegistroDiario $registroDiario, string $tipoComida): PlanComida
    {
        return $registroDiario->planesComida()->create([
            'tipo_comida' => $tipoComida,
            'origen' => PlanComida::ORIGEN_REPORTE,
            'descripcion' => __('Reportado sin plan previo'),
            'calorias_estimadas' => 0,
            'proteina_g' => 0,
            'grasa_g' => 0,
            'carbohidratos_g' => 0,
        ]);
    }

    /**
     * Macros reales de la comida, sumados en PHP a partir de los ingredientes
     * que estimó el modelo — nunca de un total que devuelva él.
     *
     * @param  array{descripcion: string, notas: string, ingredientes: array<int, array<string, mixed>>}  $estimacion
     * @return array{calorias_reales: float, proteina_g: float, grasa_g: float, carbohidratos_g: float, notas: string}
     */
    private function macrosDe(array $estimacion, string $textoDelUsuario): array
    {
        $sumar = fn (string $campo): float => round(
            (float) array_sum(array_column($estimacion['ingredientes'], $campo)),
            2,
        );

        $notas = $textoDelUsuario;

        if (($estimacion['descripcion'] ?? '') !== '') {
            $notas .= ' — '.$estimacion['descripcion'];
        }

        if (($estimacion['notas'] ?? '') !== '') {
            $notas .= ' ('.$estimacion['notas'].')';
        }

        return [
            'calorias_reales' => $sumar('calorias'),
            'proteina_g' => $sumar('proteina_g'),
            'grasa_g' => $sumar('grasa_g'),
            'carbohidratos_g' => $sumar('carbohidratos_g'),
            'notas' => mb_substr($notas, 0, 1000),
        ];
    }
}
