<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\RecursosDidacticosRequest;
use App\Services\RecursosDidacticosService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Illuminate\View\View;

/**
 * Material de apoyo de la consola (CLAUDE.md sección 5.15): el video y la guía
 * en PDF que acompañan a la Calculadora Déficit.
 *
 * El controlador no sabe qué recursos existen ni de qué tipo son: eso lo
 * declara el catálogo de RecursosDidacticosService, que es también quien
 * persiste y quien borra el archivo anterior al reemplazarlo.
 */
class RecursoDidacticoController extends Controller
{
    public function __construct(
        private readonly RecursosDidacticosService $recursos,
    ) {}

    public function edit(): View
    {
        return view('admin.recursos', [
            'catalogo' => RecursosDidacticosService::CATALOGO,
            'recursos' => $this->recursos->todos(),
            'videoIncrustado' => $this->recursos->urlIncrustable('calculadora_video_url'),
        ]);
    }

    public function update(RecursosDidacticosRequest $request): RedirectResponse
    {
        // El campo de la URL siempre viaja: vacío significa "retira el video".
        $this->recursos->guardarUrl(
            'calculadora_video_url',
            $request->validated('calculadora_video_url'),
            $request->user()->id,
        );

        // El archivo solo viaja cuando se elige uno: no enviarlo conserva el
        // que ya estuviera publicado.
        if ($request->hasFile('calculadora_guia_pdf')) {
            $this->recursos->guardarArchivo(
                'calculadora_guia_pdf',
                $request->file('calculadora_guia_pdf'),
                $request->user()->id,
            );
        }

        return Redirect::route('admin.recursos.edit')->with('status', 'recursos-guardados');
    }

    /**
     * Retira un recurso publicado. No recibe entrada más allá de la clave de la
     * ruta, así que no hay Form Request que aplicar (sección 9).
     */
    public function destroy(Request $request, string $clave): RedirectResponse
    {
        abort_unless(array_key_exists($clave, RecursosDidacticosService::CATALOGO), 404);

        $this->recursos->retirar($clave);

        return Redirect::route('admin.recursos.edit')->with('status', 'recurso-retirado');
    }
}
