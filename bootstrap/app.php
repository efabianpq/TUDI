<?php

use App\Http\Middleware\EnsureCuentaActiva;
use App\Http\Middleware\EnsureEsAdministrador;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // CLAUDE.md sección 4.26: ciclo de vida de la cuenta y consola de
        // administración. Se registran como alias y se aplican por grupo de
        // rutas en routes/web.php, no globalmente: login, logout y la propia
        // pantalla de activación tienen que seguir siendo alcanzables desde una
        // cuenta que todavía no está activa.
        $middleware->alias([
            'cuenta.activa' => EnsureCuentaActiva::class,
            'admin' => EnsureEsAdministrador::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
