<?php

namespace App\Services;

use App\Exceptions\MealDistributionUnavailableException;
use App\Models\ComidaReal;
use App\Models\PlanComida;
use App\Models\RegistroDiario;
use App\Services\AI\MealDistributionProviderInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;

/**
 * Feedback del cierre del día (CLAUDE.md sección 4.16): antes de congelar el
 * día, el usuario dice comida a comida si cumplió con lo que se le sugirió.
 *
 * Dos caminos por comida, ninguno obligatorio:
 *
 *  - **"Sí, lo cumplí"** — se registra la ComidaReal con los macros del propio
 *    PlanComida. Es un clic y no cuesta ninguna llamada al proveedor de IA.
 *  - **Contar qué comió** — "al final me comí un sándwich y una gaseosa" pasa
 *    por el proveedor (`estimarConsumoReal`), que lo traduce a alimentos con
 *    sus macros; las tres comidas viajan en una sola llamada para que la
 *    estimación sea coherente entre ellas.
 *
 * En cualquiera de los dos se puede adjuntar la foto de evidencia de esa
 * comida: desde la sección 4.23 este es el único sitio donde se sube, porque el
 * botón "Registrar" de cada comida —que preguntaba lo mismo— desapareció.
 *
 * En los dos casos las cifras que entran al balance energético se suman en PHP
 * a partir de los ingredientes (regla 7 de la sección 11) y se persisten con
 * ComidaRealService, que es quien redistribuye el presupuesto pendiente del día
 * y recalcula `calorias_consumidas` (sección 4.3).
 *
 * Una comida sin plan, o que ya tiene ComidaReal registrada, se ignora: el
 * cierre nunca sobrescribe lo que el usuario ya registró a mano.
 */
class CierreFeedbackService
{
    public function __construct(
        private readonly MealDistributionProviderInterface $proveedor,
        private readonly MealDistributionService $distribucion,
        private readonly ComidaRealService $comidaRealService,
    ) {}

    /**
     * Registra el feedback de las comidas del día y devuelve las ComidaReal
     * creadas.
     *
     * El orden importa: primero se resuelve la parte que depende del proveedor
     * (sin persistir nada) y solo después se escribe. Así un fallo de la IA no
     * deja el día a medio registrar.
     *
     * @param  array<string, array{cumplio?: bool|string|null, texto?: string|null, imagen?: UploadedFile|null}>  $feedback  indexado por tipo de comida
     * @return Collection<int, ComidaReal>
     *
     * @throws MealDistributionUnavailableException cuando el proveedor no puede interpretar lo que se comió
     */
    public function registrar(RegistroDiario $registroDiario, array $feedback): Collection
    {
        $planes = $registroDiario->planesComida()->with('comidaReal')->get()->keyBy('tipo_comida');

        /** @var array<string, array{plan: PlanComida, texto: string}> $aEstimar */
        $aEstimar = [];
        /** @var array<string, PlanComida> $cumplidas */
        $cumplidas = [];
        /** @var array<string, UploadedFile> $imagenes */
        $imagenes = [];

        foreach ($feedback as $tipoComida => $respuesta) {
            $plan = $planes->get($tipoComida);

            if (! $plan instanceof PlanComida || $plan->comidaReal !== null) {
                continue;
            }

            $texto = isset($respuesta['texto']) ? trim((string) $respuesta['texto']) : '';

            // La imagen es evidencia visual y acompaña a cualquiera de los dos
            // caminos; por sí sola no dice qué se comió, así que no crea una
            // ComidaReal si no hay ni texto ni "lo cumplí".
            if (($respuesta['imagen'] ?? null) instanceof UploadedFile) {
                $imagenes[$tipoComida] = $respuesta['imagen'];
            }

            // El texto manda sobre la casilla: si contó qué comió, esa es la
            // información más fiel, aunque además marcara "lo cumplí".
            if ($texto !== '') {
                $aEstimar[$tipoComida] = ['plan' => $plan, 'texto' => $texto];

                continue;
            }

            if (filter_var($respuesta['cumplio'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                $cumplidas[$tipoComida] = $plan;
            }
        }

        $estimaciones = $aEstimar !== []
            ? $this->estimar($registroDiario, $aEstimar)
            : [];

        $registradas = collect();

        foreach ($cumplidas as $tipoComida => $plan) {
            $registradas->push($this->comidaRealService->registrar($plan, [
                'calorias_reales' => (float) $plan->calorias_estimadas,
                'proteina_g' => (float) $plan->proteina_g,
                'grasa_g' => (float) $plan->grasa_g,
                'carbohidratos_g' => (float) $plan->carbohidratos_g,
                'notas' => __('Cumplí con lo sugerido.'),
            ], $imagenes[$tipoComida] ?? null));
        }

        foreach ($estimaciones as $tipoComida => $estimacion) {
            $registradas->push($this->comidaRealService->registrar(
                $aEstimar[$tipoComida]['plan'],
                $this->macrosDe($estimacion, $aEstimar[$tipoComida]['texto']),
                $imagenes[$tipoComida] ?? null,
            ));
        }

        return $registradas;
    }

    /**
     * Una sola llamada al proveedor con todas las comidas que el usuario
     * describió, para que las interprete de forma consolidada.
     *
     * @param  array<string, array{plan: PlanComida, texto: string}>  $aEstimar
     * @return array<string, array{descripcion: string, preparacion: string, notas: string, ingredientes: array<int, array<string, mixed>>}>
     */
    private function estimar(RegistroDiario $registroDiario, array $aEstimar): array
    {
        $objetivos = $this->distribucion->objetivosDelRegistro($registroDiario);

        $comidas = [];

        foreach ($aEstimar as $tipoComida => $entrada) {
            $comidas[$tipoComida] = [
                'texto' => $entrada['texto'],
                'plan' => [
                    'descripcion' => (string) ($entrada['plan']->descripcion ?? ''),
                    'calorias' => (float) $entrada['plan']->calorias_estimadas,
                    'proteina_g' => (float) $entrada['plan']->proteina_g,
                    'grasa_g' => (float) $entrada['plan']->grasa_g,
                    'carbohidratos_g' => (float) $entrada['plan']->carbohidratos_g,
                ],
            ];
        }

        return $this->proveedor->estimarConsumoReal($comidas, [
            'calorias_objetivo_dia' => $objetivos['dia']['calorias_objetivo'],
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
