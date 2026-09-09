<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\PlanService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Illuminate\View\View;

/**
 * Landing pública (CLAUDE.md sección 5.19). Es lo único de la aplicación que se
 * ve sin sesión: hasta ahora `GET /` solo redirigía al login, así que no había
 * ninguna página a la que enlazar desde fuera.
 *
 * Para quien ya tiene sesión no cambia nada: sigue yendo a su panel de inicio.
 * Un usuario dentro de la aplicación no tiene por qué toparse con la página de
 * venta cada vez que teclea el dominio a secas.
 *
 * Los precios NO están escritos en la vista: salen de PlanService, que los lee
 * de `config/planes.php`. Cuando entre la pasarela de pago, el importe que se
 * cobre y el que se anuncia aquí tienen que ser forzosamente el mismo número.
 */
class LandingController extends Controller
{
    public function __construct(
        private readonly PlanService $planes,
    ) {}

    public function __invoke(Request $request): View|RedirectResponse
    {
        if ($request->user() !== null) {
            return Redirect::route('dashboard');
        }

        return view('welcome', [
            'precios' => $this->planes->precios(),
            'incluyeGratis' => (array) config('planes.incluye.'.User::PLAN_GRATIS, []),
            'incluyePremium' => (array) config('planes.incluye.'.User::PLAN_PREMIUM, []),
        ]);
    }
}
