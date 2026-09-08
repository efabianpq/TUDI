<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ParametrosMaestrosRequest;
use App\Services\ParametrosMaestrosService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Illuminate\View\View;
use InvalidArgumentException;

/**
 * Parámetros maestros de la consola (CLAUDE.md sección 4.27).
 *
 * El controlador no sabe qué parámetros existen ni entre qué límites se mueven:
 * eso lo declara el catálogo de ParametrosMaestrosService, que es también quien
 * valida y persiste.
 */
class ParametroMaestroController extends Controller
{
    public function __construct(
        private readonly ParametrosMaestrosService $parametros,
    ) {}

    public function edit(): View
    {
        return view('admin.parametros', [
            'catalogo' => ParametrosMaestrosService::CATALOGO,
            'valores' => $this->parametros->todos(),
        ]);
    }

    public function update(ParametrosMaestrosRequest $request): RedirectResponse
    {
        try {
            $this->parametros->guardar(
                (array) $request->validated('parametros'),
                $request->user()->id,
            );
        } catch (InvalidArgumentException $e) {
            // Rango fuera de límites: el servicio es la última palabra sobre
            // qué es un valor aceptable, y su mensaje ya es legible.
            return Redirect::back()->withInput()->withErrors(['parametros' => $e->getMessage()]);
        }

        return Redirect::route('admin.parametros.edit')->with('status', 'parametros-guardados');
    }

    /**
     * Devuelve todo a los valores de fábrica. No recibe entrada, así que no hay
     * Form Request que aplicar (sección 7).
     */
    public function restablecer(Request $request): RedirectResponse
    {
        $this->parametros->restablecer();

        return Redirect::route('admin.parametros.edit')->with('status', 'parametros-restablecidos');
    }
}
