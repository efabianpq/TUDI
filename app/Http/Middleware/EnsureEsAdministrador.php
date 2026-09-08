<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Puerta de la consola de administración (CLAUDE.md sección 4.26).
 *
 * 403 y no un redirect: la consola no debería ni insinuarse a quien no es
 * administrador, y un redirect a "Inicio" sugeriría que la ruta existe pero no
 * está disponible ahora mismo.
 */
class EnsureEsAdministrador
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user()?->esAdministrador(), 403);

        return $next($request);
    }
}
