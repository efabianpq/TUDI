<?php

namespace App\Http\Controllers;

use App\Http\Requests\ActivacionRequest;
use App\Services\CuentaService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Illuminate\View\View;

/**
 * Canje del código de activación (CLAUDE.md sección 4.26).
 *
 * Vive fuera del grupo protegido por `cuenta.activa` —si no, el middleware
 * redirigiría aquí en bucle— pero dentro de `auth`: el código se canjea contra
 * la cuenta que ya inició sesión, nunca contra una dirección de correo suelta.
 */
class ActivacionController extends Controller
{
    public function __construct(
        private readonly CuentaService $cuentas,
    ) {}

    public function create(Request $request): View|RedirectResponse
    {
        // Una cuenta ya activa no tiene nada que hacer aquí.
        if ($request->user()->estaActiva()) {
            return Redirect::route('dashboard');
        }

        return view('auth.activacion');
    }

    public function store(ActivacionRequest $request): RedirectResponse
    {
        $usuario = $request->user();

        if ($usuario->estaActiva()) {
            return Redirect::route('dashboard');
        }

        if (! $this->cuentas->activarConCodigo($usuario, $request->validated('codigo'))) {
            return Redirect::back()->withErrors([
                'codigo' => __('Ese código no es el de tu cuenta. Revísalo con el administrador.'),
            ]);
        }

        // Recién activado, lo primero es su objetivo calórico (sección 4.14).
        return Redirect::route('calculadora.edit')->with('status', 'cuenta-activada');
    }
}
