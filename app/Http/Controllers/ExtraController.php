<?php

namespace App\Http\Controllers;

use App\Exceptions\DayAlreadyClosedException;
use App\Exceptions\InvalidNutritionParameterException;
use App\Exceptions\MealDistributionUnavailableException;
use App\Exceptions\NegativeCarbohydrateException;
use App\Exceptions\ReporteComidaInvalidoException;
use App\Http\Requests\RegistrarExtraRequest;
use App\Models\PlanComida;
use App\Models\RegistroDiario;
use App\Services\ReporteComidaService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;

/**
 * Extras del día (CLAUDE.md sección 5.27): lo que se consume fuera del
 * desayuno, el almuerzo y la cena — una gaseosa, una cerveza, un postre de media
 * tarde, unas galletas.
 *
 * Ruta propia y no una reutilización de `comidas.cerrar` por dos razones de
 * fondo, no de organización: un extra **no tiene plan que cerrar** (no se
 * planificó nada) y **se añade N veces al día**, mientras que una comida se
 * cierra una sola vez y luego se reabre. Forzar los dos casos por el mismo
 * endpoint habría obligado a `comidas.cerrar` a llevar dentro un `if` por cada
 * una de esas diferencias.
 *
 * Controlador de composición, como el resto: valida con su Form Request, delega
 * en ReporteComidaService y traduce las excepciones de dominio a un redirect con
 * mensaje — nunca a un 500 (regla 6).
 */
class ExtraController extends Controller
{
    public function __construct(
        private readonly ReporteComidaService $reporte,
    ) {}

    /**
     * "Añadir extra": registra algo consumido fuera de las tres comidas.
     */
    public function store(RegistrarExtraRequest $request, RegistroDiario $registroDiario): RedirectResponse
    {
        abort_unless($registroDiario->usuario_id === $request->user()->id, 403);

        try {
            $this->reporte->registrarExtra($registroDiario, [
                'texto' => $request->validated('texto'),
                'repetir' => $request->validated('repetir'),
                'imagen' => $request->file('imagen'),
            ]);
        } catch (ReporteComidaInvalidoException|DayAlreadyClosedException|MealDistributionUnavailableException $e) {
            return Redirect::route('planes.show', $registroDiario)->with('error', $e->getMessage());
        } catch (NegativeCarbohydrateException|InvalidNutritionParameterException $e) {
            return Redirect::route('calculadora.edit')->with('error', $e->getMessage());
        }

        return Redirect::route('planes.show', $registroDiario)->with('status', 'extra-registrado');
    }

    /**
     * Borra un extra. No devuelve cuota de IA, por el mismo motivo que reabrir
     * una comida tampoco (sección 5.20).
     */
    public function destroy(Request $request, RegistroDiario $registroDiario, PlanComida $extra): RedirectResponse
    {
        abort_unless($registroDiario->usuario_id === $request->user()->id, 403);

        try {
            $borrado = $this->reporte->eliminarExtra($registroDiario, $extra);
        } catch (DayAlreadyClosedException $e) {
            return Redirect::route('planes.show', $registroDiario)->with('error', $e->getMessage());
        }

        // Que la fila no sea un extra de este día no es un error del usuario: se
        // vuelve igual y la pantalla, ya recalculada, enseña lo que hay.
        return Redirect::route('planes.show', $registroDiario)
            ->with('status', $borrado ? 'extra-borrado' : 'extra-no-encontrado');
    }
}
