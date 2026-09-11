@props([
    // La forma que devuelve TrendAnalyticsService::diagnosticoRecomendaciones()
    // / DailyClosureService::diagnosticoRecomendaciones() — mismo checklist
    // en el cierre del plan diario y en "Tu tendencia" de Inicio (sección 5.8).
    'diagnostico',
])

<p {{ $attributes->merge(['class' => 'mt-2 text-sm text-tudi-ink-3']) }}>
    {{ __('Todavía no hay historial suficiente para sugerirte un ajuste.') }}
</p>

<ul class="mt-3 space-y-2">
    @foreach ([
        [
            'hecho' => $diagnostico['historial_completo'],
            'texto' => __('Días con plan en la última semana'),
            'cifra' => $diagnostico['dias_con_datos'].' / '.$diagnostico['dias_necesarios'],
        ],
        [
            'hecho' => $diagnostico['dias_con_peso'] > 0,
            'texto' => __('Pesajes en la última semana'),
            'cifra' => (string) $diagnostico['dias_con_peso'],
        ],
        [
            'hecho' => $diagnostico['dias_con_peso_anterior'] > 0,
            'texto' => __('Pesajes en la semana anterior'),
            'cifra' => (string) $diagnostico['dias_con_peso_anterior'],
        ],
    ] as $requisito)
        <li class="flex items-center justify-between gap-3 text-sm">
            <span class="flex items-center gap-2.5">
                <span @class([
                    'h-2 w-2 flex-none rounded-full',
                    'bg-tudi-lime' => $requisito['hecho'],
                    'bg-tudi-input-border' => ! $requisito['hecho'],
                ])></span>
                {{ $requisito['texto'] }}
            </span>
            <span class="tudi-meta">{{ $requisito['cifra'] }}</span>
        </li>
    @endforeach
</ul>
