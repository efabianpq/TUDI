<?php

namespace App\Http\Controllers;

use App\Services\DashboardEstadoService;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Pantalla de inicio (CLAUDE.md sección 5.8). Cuatro bloques, cada uno con
 * sus propios sub-estados según cuánto ha usado la app el usuario y en qué
 * punto del día está:
 *
 *  0. Avisos — estado del plan y comidas de ayer sin reportar.
 *  1. Bienvenida — solo en el primer login, sin ningún RegistroDiario.
 *  2. Hoy — sin plan / en curso / cerrado.
 *  3. Tu tendencia — historial insuficiente / suficiente.
 *  4. Tu seguimiento — sin semana completa / con datos.
 *
 * Qué sub-estado corresponde en cada bloque lo decide DashboardEstadoService,
 * no esta clase ni la vista: el controlador solo pide el estado y lo pasa a
 * Blade, tal y como pide la sección 13 (controladores delgados).
 */
class DashboardController extends Controller
{
    public function __construct(
        private readonly DashboardEstadoService $estado,
    ) {}

    public function index(Request $request): View
    {
        return view('dashboard', $this->estado->calcular($request->user()));
    }
}
