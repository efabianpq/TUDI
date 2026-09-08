<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Deja pasar a la aplicación solo a las cuentas activas (CLAUDE.md sección 4.26).
 *
 *  - **pendiente** → a la pantalla del código de activación. No se cierra la
 *    sesión: el usuario acaba de registrarse y solo le falta el código.
 *  - **suspendida** → se cierra la sesión y vuelve al login con el motivo. Un
 *    usuario bloqueado no debe conservar una sesión viva.
 *
 * Se aplica al grupo de rutas de la aplicación, no a las de autenticación: el
 * login, el logout y la propia pantalla de activación tienen que seguir siendo
 * alcanzables desde una cuenta que aún no está activa.
 */
class EnsureCuentaActiva
{
    public function handle(Request $request, Closure $next): Response
    {
        $usuario = $request->user();

        if ($usuario === null || $usuario->estaActiva()) {
            return $next($request);
        }

        if ($usuario->estado === User::ESTADO_SUSPENDIDO) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->with('error', __(
                'Tu cuenta está suspendida. Ponte en contacto con el administrador.'
            ));
        }

        return redirect()->route('activacion.create');
    }
}
