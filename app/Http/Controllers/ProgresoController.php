<?php

namespace App\Http\Controllers;

use App\Services\TrendAnalyticsService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ProgresoController extends Controller
{
    /**
     * Días de historial que se dibujan en el gráfico. Fijo y no configurable por
     * query string: así la pantalla no recibe ninguna entrada del usuario y no
     * necesita Form Request (mismo criterio que PlanComidaController@generar).
     */
    private const DIAS_GRAFICO = 30;

    public function __construct(
        private readonly TrendAnalyticsService $tendencias,
    ) {}

    /**
     * "Mi progreso": promedio móvil de peso, déficit promedio y consistencia.
     *
     * Persiste de paso el snapshot de hoy en metricas_tendencia. Es una escritura
     * idempotente (una única fila por usuario+fecha, que se actualiza en sitio),
     * no una acción del usuario: mientras no exista el comando programado
     * CalculateTrends, esta es la única vía por la que la serie histórica llega
     * a la tabla.
     */
    public function index(Request $request): View
    {
        $usuario = $request->user();

        $metrica = $this->tendencias->calcularYPersistir($usuario);

        return view('progreso.index', [
            'metricas' => $this->tendencias->calcular($usuario),
            'serie' => $this->tendencias->serieHistorica($usuario, self::DIAS_GRAFICO),
            'calculadoEn' => $metrica->fecha,
        ]);
    }
}
