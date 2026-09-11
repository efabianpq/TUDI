<?php

namespace App\Http\Controllers;

use App\Http\Requests\IngredienteDisponibleRequest;
use App\Http\Requests\UpdateIngredienteDisponibleRequest;
use App\Models\IngredienteDisponible;
use App\Models\RegistroDiario;
use App\Services\ObjetivoDelDiaService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Illuminate\View\View;

class IngredienteDisponibleController extends Controller
{
    public function __construct(
        private readonly ObjetivoDelDiaService $objetivoDelDia,
    ) {}

    /**
     * Show the form to report today's available ingredients.
     */
    public function create(Request $request): View
    {
        $registroDiario = RegistroDiario::where('usuario_id', $request->user()->id)
            ->whereDate('fecha', now()->toDateString())
            ->first();

        $ingredientes = $registroDiario
            ? $registroDiario->ingredientesDisponibles()->get()
            : collect();

        return view('ingredientes.create', [
            'ingredientes' => $ingredientes,
        ]);
    }

    /**
     * Store a batch of ingredients reported for the current day, creating
     * the RegistroDiario for today if it doesn't exist yet.
     */
    public function store(IngredienteDisponibleRequest $request): RedirectResponse
    {
        $registroDiario = RegistroDiario::where('usuario_id', $request->user()->id)
            ->whereDate('fecha', now()->toDateString())
            ->first();

        if (! $registroDiario) {
            $registroDiario = RegistroDiario::create([
                'usuario_id' => $request->user()->id,
                'fecha' => now()->toDateString(),
            ]);

            // El día nace con su objetivo sellado (sección 5.25), igual que
            // cuando lo crea "Crear plan diario".
            $registroDiario->setRelation('usuario', $request->user());
            $this->objetivoDelDia->sellar($registroDiario);
        }

        foreach ($request->validated('ingredientes') as $ingrediente) {
            $registroDiario->ingredientesDisponibles()->create($ingrediente);
        }

        return Redirect::route('ingredientes.create')->with('status', 'ingredientes-guardados');
    }

    /**
     * List the ingredients belonging to a specific RegistroDiario, scoped to
     * the authenticated user.
     */
    public function index(Request $request, RegistroDiario $registroDiario): View
    {
        abort_unless($registroDiario->usuario_id === $request->user()->id, 403);

        return view('ingredientes.index', [
            'registroDiario' => $registroDiario,
            'ingredientes' => $registroDiario->ingredientesDisponibles()->get(),
        ]);
    }

    /**
     * Update an ingredient reported by mistake.
     */
    public function update(UpdateIngredienteDisponibleRequest $request, IngredienteDisponible $ingrediente): RedirectResponse
    {
        abort_unless($ingrediente->registroDiario->usuario_id === $request->user()->id, 403);

        $ingrediente->update($request->validated());

        return Redirect::route('ingredientes.index', $ingrediente->registro_diario_id)
            ->with('status', 'ingrediente-actualizado');
    }

    /**
     * Delete an ingredient reported by mistake.
     */
    public function destroy(Request $request, IngredienteDisponible $ingrediente): RedirectResponse
    {
        abort_unless($ingrediente->registroDiario->usuario_id === $request->user()->id, 403);

        $registroDiarioId = $ingrediente->registro_diario_id;
        $ingrediente->delete();

        return Redirect::route('ingredientes.index', $registroDiarioId)
            ->with('status', 'ingrediente-eliminado');
    }
}
