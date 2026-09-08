<?php

namespace App\Http\Controllers;

use App\Exceptions\InvalidNutritionParameterException;
use App\Exceptions\MealDistributionUnavailableException;
use App\Exceptions\NegativeCarbohydrateException;
use App\Exceptions\NoIngredientsAvailableException;
use App\Http\Requests\DistribucionDiaRequest;
use App\Http\Requests\RegistroPesoRequest;
use App\Http\Requests\RepartoComidasRequest;
use App\Models\RegistroDiario;
use App\Services\ActivitySuggestionService;
use App\Services\DailyClosureService;
use App\Services\MealDistributionService;
use App\Services\MealPlanGeneratorService;
use App\Services\NutritionCalculatorService;
use App\Services\PlanDiarioService;
use App\Services\RepartoComidasService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Illuminate\View\View;
use InvalidArgumentException;

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
 *      3. Cierre del día — el feedback de cumplimiento y el resumen
 *         (DailyClosureService + CierreFeedbackService, vía CierreDiarioController).
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

        $registroDiario ??= RegistroDiario::create([
            'usuario_id' => $request->user()->id,
            'fecha' => now()->toDateString(),
        ]);

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
            // Reparto vigente del día y si es el de fábrica (sección 5.14).
            'reparto' => $this->reparto->paraElDia($registroDiario),
            'repartoDeFabrica' => $this->reparto->deFabrica(),
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
            $datos['diagnosticoRecomendaciones'] = $this->cierre->diagnosticoRecomendaciones($registroDiario);
        } catch (NegativeCarbohydrateException|InvalidNutritionParameterException $e) {
            $datos['errorPerfil'] = $e->getMessage();

            return view('planes.show', $datos);
        }

        $datos['comidas'] = $this->comidasDelDia($registroDiario, $datos['objetivos']);

        return view('planes.show', $datos);
    }

    /**
     * Reparto de calorías entre las tres comidas de ESTE día (sección 5.14).
     *
     * Cambiarlo no recalcula ningún plan ya generado —sus macros están
     * persistidos—: redimensiona los objetivos que se muestran y el presupuesto
     * de lo que quede por generar. Se permite sobre un día cerrado por el mismo
     * motivo que el peso: no altera ninguna cifra del cierre.
     */
    public function reparto(RepartoComidasRequest $request, RegistroDiario $registroDiario): RedirectResponse
    {
        $this->autorizar($request, $registroDiario);

        try {
            $this->reparto->guardar(
                $registroDiario,
                (array) $request->validated('reparto'),
                (bool) $request->validated('como_habitual', false),
            );
        } catch (InvalidArgumentException $e) {
            // El servicio es la última palabra sobre qué reparto es válido y su
            // mensaje ya es legible.
            return Redirect::route('planes.show', $registroDiario)->with('error', $e->getMessage());
        }

        return Redirect::route('planes.show', $registroDiario)->with('status', 'reparto-guardado');
    }

    /**
     * "Reiniciar el día": lo vacía y lo deja como recién creado, sin las
     * sugerencias ya generadas (sección 5.16). No recibe entrada, así que no
     * hay Form Request que aplicar (sección 9).
     */
    public function resetear(Request $request, RegistroDiario $registroDiario): RedirectResponse
    {
        $this->autorizar($request, $registroDiario);

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
     * "Generar distribución": guarda el texto de las tres comidas y pide al
     * motor de IA, en una sola llamada, el reparto de las que haga falta
     * resolver. Un mismo botón hace las dos cosas para que el usuario no tenga
     * que guardar y luego generar.
     *
     * Las comidas ya resueltas cuyo texto no cambió se dejan intactas y su
     * presupuesto se descuenta; las que aún no tienen texto reservan el suyo
     * (sección 4.12).
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

        return Redirect::route('planes.show', $registroDiario)->with('status', 'distribucion-generada');
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
    private function comidasDelDia(RegistroDiario $registroDiario, array $objetivos): array
    {
        $planes = $registroDiario->planesComida()->with('comidaReal')->get()->keyBy('tipo_comida');

        $comidas = [];

        foreach (array_keys(MealPlanGeneratorService::DISTRIBUCION_COMIDAS) as $tipoComida) {
            $plan = $planes->get($tipoComida);

            $comidas[] = [
                'tipo' => $tipoComida,
                'porcentaje' => $objetivos['reparto'][$tipoComida],
                'objetivos' => $objetivos['por_comida'][$tipoComida],
                'texto' => $registroDiario->{'ingredientes_'.$tipoComida},
                'plan' => $plan,
                'estado' => match (true) {
                    $plan === null => 'pendiente',
                    $plan->comidaReal !== null => 'registrada',
                    default => 'planificada',
                },
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
