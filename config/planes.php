<?php

use App\Models\User;

/*
|--------------------------------------------------------------------------
| Planes y precios (CLAUDE.md sección 5.18)
|--------------------------------------------------------------------------
|
| Único sitio donde vive el precio. La landing lo lee de aquí, y cuando entre
| la pasarela de pago (Wompi) el importe que se cobre tiene que salir de este
| mismo archivo: un precio escrito a mano en una vista es un precio que se
| queda desactualizado cuando cambia el otro.
|
| El descuento anual NO se declara: se deriva de los dos importes en
| PlanService::precios(), para que no pueda contradecirlos.
*/

return [

    /*
     * Días de Premium que estrena toda cuenta nueva, sin tarjeta y sin elegirlo
     * (sección 5.18). Configurable por si una campaña quiere alargarlo.
     */
    'prueba_dias' => (int) env('TUDI_PRUEBA_DIAS', 3),

    'precio' => [
        'moneda' => 'COP',
        'mensual' => 14900,
        'anual' => 119000,
    ],

    /*
     * Qué entra en cada plan. Es la tabla que pinta la landing y, a la vez, la
     * descripción de lo que gatea PremiumGatedMealDistributionProvider y
     * compañía: si cambia el control de acceso, cambia primero esta lista.
     */
    'incluye' => [
        User::PLAN_GRATIS => [
            'Calculadora de déficit y objetivo calórico',
            'Registro de ingredientes en texto libre, escrito o dictado',
            'Actividad física y corrección de calorías',
            'Cierre diario con el resultado real',
            'Tendencia de los últimos 7 días',
        ],
        User::PLAN_PREMIUM => [
            'Todo lo del plan Gratis',
            'Distribución de comidas con IA, ilimitada',
            'Estimación de lo que comiste a partir de texto libre',
            'Motor de recomendaciones y alertas de estancamiento',
            'Historial completo de tendencias y seguimiento semanal',
        ],
    ],

];
