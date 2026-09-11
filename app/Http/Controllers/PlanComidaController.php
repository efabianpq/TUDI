<?php

namespace App\Http\Controllers;

use App\Exceptions\InvalidNutritionParameterException;
use App\Exceptions\MealDistributionUnavailableException;
use App\Exceptions\NegativeCarbohydrateException;
use App\Exceptions\NoIngredientsAvailableException;
use App\Http\Requests\DistribucionDiaRequest;
use App\Http\Requests\RegistroPesoRequest;
use App\Models\RegistroDiario;
use App\Services\ActivitySuggestionService;
use App\Services\ComidasFrecuentesService;
use App\Services\CuotaIaService;
use App\Services\DailyClosureService;
use App\Services\MealDistributionService;
use App\Services\MealPlanGeneratorService;
use App\Services\NutritionCalculatorService;
use App\Services\ObjetivoDelDiaService;
use App\Services\PlanDiarioService;
use App\Services\RepartoComidasService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Illuminate\View\View;

/**
 * "Planes diarios" (CLAUDE.md sección 4.12): el histórico de días del usuario y
 * el detalle de cada uno.
 *
 *  - `index()` — el listado de todos los planes diarios, con el estado de cada
 *    día, y el botón para crear el de hoy si todavía no existe.
 *  - `show()` — un plan diario concreto, que es donde transcurre el día:
 *      1. Cálculo alimenticio — el texto libre de las tres comidas y su
 *         distribución generada con IA en una sola pasada (MealDistributionService).
 *      2. Actividad física — la sugerencia derivada de la Calculadora Déficit
 *         (ActivitySuggestionService) y el registro de lo que se hizo.
 *      2b. Reporte de comidas — el cierre de cada comida por separado
 *         (ReporteComidaService, vía ReporteComidaController).
 *      3. Cierre del día — la consolidación de lo reportado, sin IA
 *         (DailyClosureService, vía CierreDiarioController).
 *
 * La clase conserva su nombre aunque la sección se llame ahora "Planes
 * diarios": el cambio es de cara al usuario, y renombrarla solo añadiría ruido
 * al diff (mismo criterio que ProfileParametersController — sección 4.14).
 *
 * Es un controlador de composición, como DashboardController: no calcula nada
 * por su cuenta, solo orquesta servicios de dominio y traduce sus excepciones
 * a redirects con mensaje — nunca a un 500.
 */
class PlanComidaController extends Controller
{
    /**
     * Planes diarios por página en el listado. Un mes de historial entra en la
     * primera página sin que la consulta crezca sin límite.
     */
    private const PLANES_POR_PAGINA = 30;

    public function __construct(
        private readonly NutritionCalculatorService $calculadora,
        private readonly MealPlanGeneratorService $generador,
        private readonly MealDistributionService $distribucion,
        private readonly ActivitySuggestionService $sugerenciaActividad,
        private readonly DailyClosureService $cierre,
        private readonly RepartoComidasService $reparto,
        private readonly PlanDiarioService $planDiario,
        private readonly CuotaIaService $cuotas,
        private readonly ComidasFrecuentesService $frecuentes,
        private readonly ObjetivoDelDiaService $objetivoDelDia,
    ) {}

    /**
     * Listado de todos los planes diarios del usuario, del más reciente al más
     * antiguo.
     */
    public function index(Request $request): View
    {
        $usuario = $request->user();

        return view('planes.index', [
            'planes' => $this->listado($request),
            'registroDeHoy' => $this->registroDiarioDeHoy($request),
            'perfilCompleto' => $this->distribucion->perfilCompleto($usuario),
        ]);
    }

    /**
     * "Crear plan diario": abre el RegistroDiario de hoy si aún no existe y
     * lleva a su detalle. Es el primer paso del flujo diario y no recibe
     * ninguna entrada, así que no hay Form Request que aplicar (sección 7).
     */
    public function crear(Request $request): RedirectResponse
    {
        $registroDiario = $this->registroDiarioDeHoy($request);
        $yaExistia = $registroDiario !== null;

        if (! $yaExistia) {
            $registroDiario = RegistroDiario::create([
                'usuario_id' => $request->user()->id,
                'fecha' => now()->toDateString(),
            ]);

            // El día nace con su objetivo sellado (sección 5.25): a partir de
            // aquí es SUYO y un cambio posterior de la Calculadora no lo
            // reescribe salvo que siga siendo el día de hoy.
            $registroDiario->setRelation('usuario', $request->user());
            $this->objetivoDelDia->sellar($registroDiario);
        }

        return Redirect::route('planes.show', $registroDiario)
            ->with('status', $yaExistia ? 'plan-existente' : 'plan-creado');
    }

    /**
     * El detalle de un plan diario: las tres secciones del día.
     */
    public function show(Request $request, RegistroDiario $registroDiario): View
    {
        $this->autorizar($request, $registroDiario);

        $usuario = $request->user();

        $datos = [
            'registroDiario' => $registroDiario,
            'perfilCompleto' => $this->distribucion->perfilCompleto($usuario),
            'errorPerfil' => null,
            'objetivos' => null,
            'comidas' => [],
            'actividad' => null,
            'actividades' => $registroDiario->actividadesFisicas()->latest()->get(),
            'resumenCierre' => null,
            // Cómo quedó repartido el día y por qué (sección 5.14): el reparto
            // ya no lo teclea nadie, lo deriva RepartoComidasService.
            'reparto' => $this->reparto->paraElDia($registroDiario),
            'explicacionReparto' => $this->reparto->explicacion($registroDiario),
            // Saldo por macro de lo que queda del día (sección 5.21).
            'saldo' => null,
            // Comidas del día todavía sin reportar (sección 5.5).
            'comidasSinReportar' => $this->cierre->comidasSinReportar($registroDiario),
            // Cuánta IA le queda hoy al usuario (sección 5.20).
            'cuotaDistribucion' => $this->cuotas->restantes($usuario, CuotaIaService::CONCEPTO_DISTRIBUCION),
            'cuotaReporte' => $this->cuotas->restantes($usuario, CuotaIaService::CONCEPTO_REPORTE),
            'limiteDistribucion' => $this->cuotas->limite(CuotaIaService::CONCEPTO_DISTRIBUCION),
            'limiteReporte' => $this->cuotas->limite(CuotaIaService::CONCEPTO_REPORTE),
            // Por qué la sección de recomendaciones está vacía (sección 5.6).
            'diagnosticoRecomendaciones' => null,
        ];

        if (! $datos['perfilCompleto']) {
            $datos['errorPerfil'] = __('Completa tu Calculadora Déficit para saber cuántas calorías debes consumir hoy.');

            return view('planes.show', $datos);
        }

        try {
            $datos['objetivos'] = $this->distribucion->objetivosDelRegistro($registroDiario);
            $datos['actividad'] = $this->sugerenciaActividad->sugerir(
                $usuario,
                $datos['objetivos']['dia']['calorias_objetivo'],
            );
            $datos['resumenCierre'] = $this->cierre->resumen($registroDiario);
            $datos['saldo'] = $this->distribucion->saldoDelDia($registroDiario);
            $datos['diagnosticoRecomendaciones'] = $this->cierre->diagnosticoRecomendaciones($registroDiario);
        } catch (NegativeCarbohydrateException|InvalidNutritionParameterException $e) {
            $datos['errorPerfil'] = $e->getMessage();

            return view('planes.show', $datos);
        }

        $datos['comidas'] = $this->comidasDelDia($registroDiario, $datos['objetivos'], $usuario);

        return view('planes.show', $datos);
    }

    /**
     * "Reiniciar el día": lo vacía y lo deja como recién creado, sin las
     * sugerencias ya generadas (sección 5.16). No recibe entrada, así que no
     * hay Form Request que aplicar (sección 9).
     */
    public function resetear(Request $request, RegistroDiario $registroDiario): RedirectResponse
    {
        $this->autorizar($request, $registroDiario);

        // Solo el día de hoy se reinicia: un día pasado ya no puede volver a
        // vivirse, así que vaciarlo solo borraría historial. La vista ni siquiera
        // pinta el botón, pero la ruta llega por POST y eso no basta.
        if (! $registroDiario->fecha->isToday()) {
            return Redirect::route('planes.show', $registroDiario)
                ->with('error', __('Solo puedes reiniciar el plan de hoy. Para deshacerte de un día anterior, elimínalo.'));
        }

        $this->planDiario->resetear($registroDiario);

        return Redirect::route('planes.show', $registroDiario)->with('status', 'plan-reiniciado');
    }

    /**
     * Elimina el plan diario entero, con todo su día en cascada. Vuelve al
     * listado: la pantalla desde la que se pulsó ya no existe.
     */
    public function destroy(Request $request, RegistroDiario $registroDiario): RedirectResponse
    {
        $this->autorizar($request, $registroDiario);

        $this->planDiario->eliminar($registroDiario);

        return Redirect::route('planes.index')->with('status', 'plan-eliminado');
    }

    /**
     * "Ajustar mi plan": guarda el texto de las tres comidas y pide al motor de
     * IA, en una sola llamada, el reparto de las que haga falta resolver. Un
     * mismo botón hace las dos cosas para que el usuario no tenga que guardar y
     * luego generar.
     *
     * Ajustar, y no solo generar: antes de repartir se mide cuánto queda del
     * objetivo del día después de las comidas ya cerradas, y es ESE saldo el
     * que se reparte entre las que faltan (sección 5.3). Una comida cerrada no
     * se toca mientras siga cerrada; las que aún no tienen texto reservan su
     * parte para cuando se escriban.
     */
    public function distribuir(DistribucionDiaRequest $request, RegistroDiario $registroDiario): RedirectResponse
    {
        $this->autorizar($request, $registroDiario);

        if (! $this->distribucion->perfilCompleto($request->user())) {
            return Redirect::route('calculadora.edit')
                ->with('error', __('Completa tu Calculadora Déficit antes de generar una distribución.'));
        }

        try {
            $this->distribucion->distribuirDia(
                $registroDiario,
                (array) $request->validated('ingredientes'),
                $request->validated('rehacer'),
            );
        } catch (MealDistributionUnavailableException $e) {
            return Redirect::route('planes.show', $registroDiario)->with('error', $e->getMessage());
        } catch (NegativeCarbohydrateException|InvalidNutritionParameterException $e) {
            return Redirect::route('calculadora.edit')->with('error', $e->getMessage());
        }

        return Redirect::route('planes.show', $registroDiario)->with('status', 'plan-ajustado');
    }

    /**
     * Peso del día. Vive dentro del plan diario porque es un dato más de ese
     * día, no una pantalla propia (sección 4.11).
     *
     * A propósito no toca `users.peso_kg`: ese es el peso de perfil que
     * dimensiona el TMB y solo se cambia explícitamente en la Calculadora.
     * Se permite sobre un día cerrado — `peso_kg` no alimenta ninguna cifra del
     * cierre, solo la analítica de tendencias.
     */
    public function peso(RegistroPesoRequest $request, RegistroDiario $registroDiario): RedirectResponse
    {
        $this->autorizar($request, $registroDiario);

        $registroDiario->update(['peso_kg' => $request->validated('peso_kg')]);

        return Redirect::route('planes.show', $registroDiario)->with('status', 'peso-guardado');
    }

    /**
     * Generación heurística a partir de los IngredienteDisponible reportados
     * (CLAUDE.md sección 4.2). Ya no está enlazada desde la interfaz —"Generar
     * distribución" la sustituyó— pero se conserva porque sigue siendo un
     * camino válido y cubierto por tests cuando el día tiene ingredientes
     * estructurados.
     */
    public function generar(Request $request, RegistroDiario $registroDiario): RedirectResponse
    {
        $this->autorizar($request, $registroDiario);

        $usuario = $request->user();

        if (! $this->distribucion->perfilCompleto($usuario)) {
            return Redirect::route('calculadora.edit')
                ->with('error', __('Completa tus parámetros nutricionales antes de generar el plan.'));
        }

        try {
            $planNutricional = $this->calculadora->calculatePlan(
                (float) $usuario->peso_kg,
                (float) $usuario->nivel_actividad,
                $usuario->tipo_deficit,
                (float) $usuario->valor_deficit,
                (float) $usuario->proteina_factor,
                (float) $usuario->grasa_factor,
                // El objetivo vigente, que una RecomendacionSistema confirmada
                // puede haber movido respecto de la fórmula (sección 4.10).
                $usuario->calorias_objetivo !== null ? (float) $usuario->calorias_objetivo : null,
            );

            $this->generador->generarPlan(
                $registroDiario,
                $planNutricional,
                $this->reparto->paraElDia($registroDiario),
            );
        } catch (NoIngredientsAvailableException $e) {
            return Redirect::route('planes.show', $registroDiario)->with('error', $e->getMessage());
        } catch (NegativeCarbohydrateException|InvalidNutritionParameterException $e) {
            return Redirect::route('calculadora.edit')->with('error', $e->getMessage());
        }

        return Redirect::route('planes.show', $registroDiario)->with('status', 'plan-generado');
    }

    /**
     * Los planes diarios del usuario con lo que el listado necesita mostrar de
     * cada uno, sin cargar sus relaciones completas.
     *
     * @return LengthAwarePaginator<int, RegistroDiario>
     */
    private function listado(Request $request): LengthAwarePaginator
    {
        return RegistroDiario::where('usuario_id', $request->user()->id)
            ->withCount([
                'planesComida as comidas_planificadas',
                'planesComida as comidas_registradas' => fn ($consulta) => $consulta->has('comidaReal'),
                'actividadesFisicas as actividades_registradas',
            ])
            ->orderByDesc('fecha')
            ->paginate(self::PLANES_POR_PAGINA)
            ->withQueryString();
    }

    /**
     * Una entrada por comida de MealPlanGeneratorService::DISTRIBUCION_COMIDAS,
     * en ese orden, con su texto de ingredientes, su objetivo y su plan si ya
     * se generó.
     *
     * Las claves siguen saliendo de DISTRIBUCION_COMIDAS —son nombres de
     * columna—, pero el porcentaje de cada una lo dicta el reparto vigente del
     * día (sección 5.14).
     *
     * @param  array{por_comida: array<string, array{calorias: float, proteina_g: float, grasa_g: float, carbohidratos_g: float}>, reparto: array<string, float>}  $objetivos
     * @return array<int, array<string, mixed>>
     */
    private function comidasDelDia(RegistroDiario $registroDiario, array $objetivos, $usuario): array
    {
        $planes = $registroDiario->planesComida()->with('comidaReal')->get()->keyBy('tipo_comida');

        $comidas = [];

        foreach (array_keys(MealPlanGeneratorService::DISTRIBUCION_COMIDAS) as $tipoComida) {
            $plan = $planes->get($tipoComida);
            $cerrada = $plan?->comidaReal !== null;

            $comidas[] = [
                'tipo' => $tipoComida,
                'porcentaje' => $objetivos['reparto'][$tipoComida],
                'objetivos' => $objetivos['por_comida'][$tipoComida],
                'texto' => $registroDiario->{'ingredientes_'.$tipoComida},
                'plan' => $plan,
                // Un PlanComida con origen "reporte" existe solo para colgar de
                // él lo que se comió: no hay ninguna sugerencia que enseñar.
                'sinPlanPrevio' => $plan?->esReporteSinPlan() ?? false,
                'estado' => match (true) {
                    $cerrada => 'registrada',
                    $plan === null => 'pendiente',
                    default => 'planificada',
                },
                // Comidas frecuentes de ese tipo, para cerrarla sin gastar una
                // llamada (sección 5.22). No se buscan si ya está cerrada.
                'frecuentes' => $cerrada
                    ? collect()
                    : $this->frecuentes->paraComida($usuario, $tipoComida),
            ];
        }

        return $comidas;
    }

    private function autorizar(Request $request, RegistroDiario $registroDiario): void
    {
        abort_unless($registroDiario->usuario_id === $request->user()->id, 403);
    }

    private function registroDiarioDeHoy(Request $request): ?RegistroDiario
    {
        return RegistroDiario::where('usuario_id', $request->user()->id)
            ->whereDate('fecha', now()->toDateString())
            ->first();
    }
}
