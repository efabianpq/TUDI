{{--
    Bloque 4 — Tu seguimiento (CLAUDE.md sección 5.8): sin una semana natural
    completa desde el primer RegistroDiario, la tabla de seis semanas se
    vería casi entera en blanco, así que se sustituye por un mensaje con la
    fecha en la que tendrá sentido volver a mirarla.
--}}
@php
    $kcal = fn ($valor) => number_format((float) $valor, 0, ',', '.');
    $kg = fn ($valor) => number_format((float) $valor, 2, ',', '.');
@endphp

<section class="space-y-3">
    <p class="tudi-label px-1">{{ __('Tu seguimiento') }}</p>

    @if (! $seguimiento['completo'])
        <div class="tudi-card p-5">
            <p class="text-sm text-tudi-ink-3">
                {{ __('Todavía no hay una semana completa.') }}
                @if ($seguimiento['fechaEstimada'])
                    {{ __('Vuelve el :fecha para ver tu primera semana.', ['fecha' => $seguimiento['fechaEstimada']->format('d/m/Y')]) }}
                @endif
            </p>
        </div>
    @else
        <div class="tudi-card">
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="border-b border-tudi-divider">
                            <th scope="col" class="tudi-label px-4 py-3 text-start font-normal">{{ __('Semana') }}</th>
                            <th scope="col" class="tudi-label px-4 py-3 text-end font-normal">{{ __('Adherencia') }}</th>
                            <th scope="col" class="tudi-label px-4 py-3 text-end font-normal">{{ __('Comidas') }}</th>
                            <th scope="col" class="tudi-label px-4 py-3 text-end font-normal">{{ __('Peso medio') }}</th>
                            <th scope="col" class="tudi-label px-4 py-3 text-end font-normal">{{ __('Variación') }}</th>
                            <th scope="col" class="tudi-label px-4 py-3 text-end font-normal">{{ __('Déficit medio') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($seguimiento['semanas'] as $semana)
                            <tr class="border-b border-tudi-divider last:border-0">
                                <td class="whitespace-nowrap px-4 py-3">
                                    {{ \Illuminate\Support\Carbon::parse($semana['inicio'])->format('d/m') }}
                                    –
                                    {{ \Illuminate\Support\Carbon::parse($semana['fin'])->format('d/m') }}
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-end font-mono text-xs text-tudi-ink-2">
                                    {{ number_format($semana['adherencia_pct'], 0, ',', '.') }}%
                                    <span class="text-tudi-muted">({{ $semana['dias_cerrados'] }}/7)</span>
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-end font-mono text-xs text-tudi-ink-2">
                                    {{ $semana['comidas_registradas'] }}/{{ $semana['comidas_planificadas'] }}
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-end font-mono text-xs text-tudi-ink-2">
                                    {{ $semana['promedio_peso_kg'] === null ? '—' : $kg($semana['promedio_peso_kg']).' kg' }}
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-end font-mono text-xs">
                                    @if ($semana['variacion_peso_kg'] === null)
                                        <span class="text-tudi-muted">—</span>
                                    @else
                                        <span class="{{ $semana['variacion_peso_kg'] <= 0 ? 'text-tudi-lime-700' : 'text-tudi-amber-ink' }}">
                                            {{ $semana['variacion_peso_kg'] > 0 ? '+' : '' }}{{ $kg($semana['variacion_peso_kg']) }} kg
                                        </span>
                                    @endif
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-end font-mono text-xs text-tudi-ink-2">
                                    {{ $semana['promedio_deficit_kcal'] === null ? '—' : $kcal($semana['promedio_deficit_kcal']).' kcal' }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        {{-- Ajustes de tu objetivo: mismo historial y botones que antes, ahora dentro de "Tu seguimiento" (sección 5.8). --}}
        <div class="flex items-center justify-between gap-3 px-1">
            <p class="tudi-label">{{ __('Ajustes de tu objetivo') }}</p>

            <div x-data="{ abierto: false }" x-on:keydown.escape.window="abierto = false">
                <button type="button" x-on:click="abierto = true" class="tudi-link text-[13px]">
                    {{ __('¿Qué es esto?') }}
                </button>

                <div x-show="abierto" style="display: none"
                     class="fixed inset-0 z-50 flex items-end justify-center bg-tudi-ink/60 p-4 sm:items-center"
                     x-on:click.self="abierto = false" role="dialog" aria-modal="true">
                    <div class="tudi-card w-full max-w-md p-6">
                        <h2 class="text-lg font-semibold tracking-tudi-title">{{ __('Ajustes de tu objetivo') }}</h2>
                        <div class="mt-3 space-y-3 text-sm text-tudi-ink-3">
                            <p>{{ __('Cuando tu ritmo se sale de lo esperado, TUDI te propone subir o bajar tu objetivo calórico. La propuesta nunca se aplica sola: la confirmas o la rechazas tú.') }}</p>
                            <p>{{ __('No mira un día suelto. Compara el promedio de los últimos 7 días con el de los 7 anteriores, así que en las primeras semanas está vacía a propósito.') }}</p>
                            <p>{{ __('Para que aparezca hacen falta 7 días con plan y al menos un pesaje en cada una de las dos semanas. No hay que pesarse a diario: los días sin peso no cuentan como cero, simplemente no entran en el promedio.') }}</p>
                        </div>
                        <button type="button" x-on:click="abierto = false" class="tudi-btn tudi-btn-primary tudi-btn-block mt-5">
                            {{ __('Entendido') }}
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <div class="tudi-card p-5 sm:p-6">
            @if (! $premium)
                <p class="text-sm text-tudi-muted">
                    {{ __('El motor de ajustes es parte de Premium. Tus cifras se siguen guardando.') }}
                </p>
            @elseif ($seguimiento['recomendacionesPendientes']->isEmpty())
                <p class="text-sm text-tudi-muted">{{ __('No tienes recomendaciones pendientes.') }}</p>
            @else
                <ul class="space-y-4">
                    @foreach ($seguimiento['recomendacionesPendientes'] as $recomendacion)
                        <li>
                            <p class="text-sm">{{ $recomendacion->justificacion }}</p>
                            @if ($recomendacion->calorias_objetivo_sugeridas)
                                <p class="tudi-meta mt-1">{{ $kcal($recomendacion->calorias_objetivo_sugeridas) }} kcal</p>
                            @endif
                            <div class="mt-3 flex flex-wrap gap-2">
                                <form method="post" action="{{ route('recomendaciones.confirmar', $recomendacion) }}">
                                    @csrf
                                    <button type="submit" class="tudi-btn tudi-btn-primary">{{ __('Confirmar') }}</button>
                                </form>
                                <form method="post" action="{{ route('recomendaciones.rechazar', $recomendacion) }}">
                                    @csrf
                                    <button type="submit" class="tudi-btn tudi-btn-secondary">{{ __('Rechazar') }}</button>
                                </form>
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif

            @if ($premium)
            <div class="mt-6 border-t border-tudi-divider pt-5">
                <p class="tudi-label">{{ __('Historial') }}</p>

                @if ($seguimiento['historialRecomendaciones']->isEmpty())
                    <p class="mt-2 text-sm text-tudi-muted">{{ __('Todavía no hay ajustes propuestos.') }}</p>
                @else
                    <ol class="mt-3 space-y-3 border-s border-tudi-divider ps-4">
                        @foreach ($seguimiento['historialRecomendaciones'] as $recomendacion)
                            <li class="relative">
                                <span @class([
                                    'absolute -start-[1.4rem] mt-1.5 h-2.5 w-2.5 rounded-full ring-4 ring-tudi-card',
                                    'bg-tudi-lime' => $recomendacion->estado === 'confirmada',
                                    'bg-tudi-input-border' => $recomendacion->estado === 'rechazada',
                                    'bg-tudi-amber' => $recomendacion->estado === 'pendiente',
                                ])></span>
                                <p class="tudi-meta">
                                    {{ $recomendacion->registroDiario?->fecha?->format('d/m/Y') }}
                                    · {{ __($recomendacion->estado) }}
                                </p>
                                <p class="mt-0.5 text-sm">{{ $recomendacion->justificacion }}</p>
                            </li>
                        @endforeach
                    </ol>
                @endif
            </div>
            @endif
        </div>
    @endif
</section>
