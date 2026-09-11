<?php

namespace App\Http\Controllers;

use App\Exceptions\DayAlreadyClosedException;
use App\Exceptions\InvalidNutritionParameterException;
use App\Exceptions\MealDistributionUnavailableException;
use App\Exceptions\NegativeCarbohydrateException;
use App\Exceptions\ReporteComidaInvalidoException;
use App\Http\Requests\ReporteComidaRequest;
use App\Models\RegistroDiario;
use App\Services\ComidaRealService;
use App\Services\MealPlanGeneratorService;
use App\Services\ReporteComidaService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;

/**
 * "Reporte de comidas" del plan diario (CLAUDE.md sección 5.5): cerrar y
 * reabrir cada comida por separado.
 *
 * Es la sección que sustituye a la batería de preguntas que antes vivía dentro
 * del cierre del día. La diferencia no es de sitio sino de momento: cerrando el
 * desayuno a las nueve de la mañana, sus calorías y sus macros entran en el
 * saldo del día desde ese instante, y el almuerzo y la cena se ajustan a lo que
 * de verdad queda. Cerrar el día ya no pregunta nada ni llama a la IA
 * (CierreDiarioController).
 *
 * Controlador de composición, como el resto: valida con su Form Request,
 * delega en ReporteComidaService y ComidaRealService, y traduce las excepciones
 * de dominio a un redirect con mensaje — nunca a un 500 (regla 6).
 */
class ReporteComidaController extends Controller
{
    public function __construct(
        private readonly ReporteComidaService $reporte,
        private readonly ComidaRealService $comidaRealService,
    ) {}

    /**
     * "Cerrar desayuno" / "Cerrar almuerzo" / "Cerrar cena".
     */
    public function cerrar(ReporteComidaRequest $request, RegistroDiario $registroDiario, string $tipoComida): RedirectResponse
    {
        $this->autorizar($request, $registroDiario, $tipoComida);

        try {
            $this->reporte->reportar($registroDiario, $tipoComida, [
                'cumplio' => $request->validated('cumplio'),
                'texto' => $request->validated('texto'),
                'repetir' => $request->validated('repetir'),
                'imagen' => $request->file('imagen'),
            ]);
        } catch (ReporteComidaInvalidoException|DayAlreadyClosedException|MealDistributionUnavailableException $e) {
            return Redirect::route('planes.show', $registroDiario)->with('error', $e->getMessage());
        } catch (NegativeCarbohydrateException|InvalidNutritionParameterException $e) {
            return Redirect::route('calculadora.edit')->with('error', $e->getMessage());
        }

        return Redirect::route('planes.show', $registroDiario)->with('status', 'comida-cerrada');
    }

    /**
     * "Reabrir desayuno": borra lo reportado para esa comida y devuelve la
     * pregunta. Es lo que hace que una respuesta dada por error se pueda
     * corregir sin borrar el día entero.
     *
     * Reabrir NO devuelve cuota de IA (sección 5.20): si la devolviera, abrir y
     * cerrar la misma comida sería una llamada gratis infinita, que es justo lo
     * que el límite diario evita.
     */
    public function reabrir(Request $request, RegistroDiario $registroDiario, string $tipoComida): RedirectResponse
    {
        $this->autorizar($request, $registroDiario, $tipoComida);

        $plan = $registroDiario->planesComida()
            ->with('comidaReal')
            ->where('tipo_comida', $tipoComida)
            ->first();

        if ($plan === null) {
            return Redirect::route('planes.show', $registroDiario)->with('status', 'comida-sin-reporte');
        }

        try {
            $borrada = $this->comidaRealService->eliminar($plan);
        } catch (DayAlreadyClosedException $e) {
            return Redirect::route('planes.show', $registroDiario)->with('error', $e->getMessage());
        }

        return Redirect::route('planes.show', $registroDiario)
            ->with('status', $borrada ? 'comida-reabierta' : 'comida-sin-reporte');
    }

    /**
     * El día tiene que ser suyo, y el tipo de comida tiene que existir: llega
     * por la URL, así que no basta con que el formulario lo pinte bien.
     */
    private function autorizar(Request $request, RegistroDiario $registroDiario, string $tipoComida): void
    {
        abort_unless($registroDiario->usuario_id === $request->user()->id, 403);
        abort_unless(
            array_key_exists($tipoComida, MealPlanGeneratorService::DISTRIBUCION_COMIDAS),
            404,
        );
    }
}
