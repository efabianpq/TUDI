<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            {{ __('Cierre del día') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            @if (session('error'))
                <div class="p-4 bg-red-50 border border-red-200 text-red-700 rounded-lg">
                    {{ session('error') }}
                </div>
            @endif

            @if (session('status') === 'dia-cerrado')
                <div class="p-4 bg-green-50 border border-green-200 text-green-700 rounded-lg">
                    {{ __('Tu día se cerró correctamente.') }}
                </div>
            @endif

            @if (session('status') === 'dia-reabierto')
                <div class="p-4 bg-green-50 border border-green-200 text-green-700 rounded-lg">
                    {{ __('Tu día se reabrió: puedes volver a registrar comidas y actividad.') }}
                </div>
            @endif

            @if (session('status') === 'recomendacion-confirmada')
                <div class="p-4 bg-green-50 border border-green-200 text-green-700 rounded-lg">
                    {{ __('Recomendación confirmada.') }}
                </div>
            @endif

            @if (session('status') === 'recomendacion-rechazada')
                <div class="p-4 bg-green-50 border border-green-200 text-green-700 rounded-lg">
                    {{ __('Recomendación rechazada.') }}
                </div>
            @endif

            @if ($errorResumen)
                <div class="p-4 bg-red-50 border border-red-200 text-red-700 rounded-lg">
                    {{ $errorResumen }}
                    <a href="{{ route('profile.parametros.edit') }}" class="underline">{{ __('Ir a mis parámetros') }}</a>
                </div>
            @endif

            <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg">
                <div class="flex items-center justify-between gap-4">
                    <div>
                        <h3 class="text-lg font-medium text-gray-900">
                            {{ __('Cerrar mi día') }}
                            @if ($registroDiario)
                                <span class="text-sm font-normal text-gray-500">
                                    ({{ $registroDiario->fecha->format('d/m/Y') }})
                                </span>
                            @endif
                        </h3>
                        <p class="mt-1 text-sm text-gray-600">
                            @if ($registroDiario?->cerrado)
                                {{ __('Día cerrado el') }}
                                {{ $registroDiario->cerrado_en?->format('d/m/Y H:i') }}.
                                {{ __('No se pueden registrar más comidas ni actividad hasta que lo reabras.') }}
                            @else
                                {{ __('Al cerrar el día se congelan sus cifras: no podrás registrar más comidas ni actividad sin reabrirlo.') }}
                            @endif
                        </p>
                    </div>

                    @if ($registroDiario?->cerrado)
                        <form method="post" action="{{ route('cierre.reabrir', $registroDiario) }}">
                            @csrf
                            <x-secondary-button type="submit">{{ __('Reabrir mi día') }}</x-secondary-button>
                        </form>
                    @else
                        <form method="post" action="{{ route('cierre.cerrar') }}">
                            @csrf
                            <x-primary-button>{{ __('Cerrar mi día') }}</x-primary-button>
                        </form>
                    @endif
                </div>
            </div>

            @if (! $registroDiario)
                <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg">
                    <p class="text-sm text-gray-600">
                        {{ __('Todavía no hay nada registrado hoy.') }}
                        <a href="{{ route('ingredientes.create') }}" class="text-indigo-600 underline">{{ __('Reportar ingredientes') }}</a>
                    </p>
                </div>
            @elseif ($resumen)
                <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg">
                    <h3 class="text-lg font-medium text-gray-900">
                        {{ $registroDiario->cerrado ? __('Resumen del cierre') : __('Resumen previo (todavía sin cerrar)') }}
                    </h3>

                    <dl class="mt-6 grid grid-cols-1 sm:grid-cols-2 gap-6">
                        <div>
                            <dt class="text-sm text-gray-500">{{ __('Calorías objetivo vs. consumidas') }}</dt>
                            <dd class="mt-1 text-gray-900">
                                <strong>{{ number_format($resumen['calorias_objetivo'], 0) }} kcal</strong>
                                {{ __('objetivo') }} ·
                                <strong>{{ number_format($resumen['calorias_consumidas'], 0) }} kcal</strong>
                                {{ __('consumidas') }}
                            </dd>
                        </div>

                        <div>
                            <dt class="text-sm text-gray-500">{{ __('Gasto por actividad (ajustado)') }}</dt>
                            <dd class="mt-1 text-gray-900">
                                <strong>{{ number_format($resumen['calorias_actividad_ajustada'], 0) }} kcal</strong>
                            </dd>
                        </div>

                        <div>
                            <dt class="text-sm text-gray-500">{{ __('Déficit calórico estimado') }}</dt>
                            <dd class="mt-1 {{ $resumen['deficit_diario'] >= 0 ? 'text-green-700' : 'text-red-700' }}">
                                <strong>{{ number_format($resumen['deficit_diario'], 0) }} kcal</strong>
                            </dd>
                        </div>

                        <div>
                            <dt class="text-sm text-gray-500">{{ __('Cumplimiento de proteína') }}</dt>
                            <dd class="mt-1 text-gray-900">
                                <strong>{{ number_format($resumen['cumplimiento_proteina_pct'], 1) }}%</strong>
                                ({{ number_format($resumen['proteina_consumida_g'], 1) }} g
                                {{ __('de') }}
                                {{ number_format($resumen['proteina_objetivo_g'], 1) }} g)
                            </dd>
                        </div>
                    </dl>
                </div>

                <div class="p-4 sm:p-8 bg-white shadow sm:rounded-lg">
                    <h3 class="text-lg font-medium text-gray-900">{{ __('Recomendaciones') }}</h3>

                    @if ($resumen['recomendaciones']->isEmpty())
                        <p class="mt-2 text-sm text-gray-600">
                            {{ __('No hay recomendaciones para este día. Los ajustes de calorías objetivo se basan en promedios móviles de 7 días y siempre requieren tu confirmación.') }}
                        </p>
                    @else
                        <ul class="mt-4 divide-y text-sm">
                            @foreach ($resumen['recomendaciones'] as $recomendacion)
                                <li class="py-2">
                                    <span class="text-gray-900">{{ $recomendacion->justificacion }}</span>
                                    <span class="text-gray-500">
                                        — {{ $recomendacion->estado }}
                                        @if ($recomendacion->calorias_objetivo_sugeridas)
                                            ({{ $recomendacion->calorias_objetivo_sugeridas }} kcal)
                                        @endif
                                    </span>

                                    @if ($recomendacion->estado === 'pendiente')
                                        <div class="mt-2 flex gap-2">
                                            <form method="post" action="{{ route('recomendaciones.confirmar', $recomendacion) }}">
                                                @csrf
                                                <x-primary-button type="submit">{{ __('Confirmar') }}</x-primary-button>
                                            </form>
                                            <form method="post" action="{{ route('recomendaciones.rechazar', $recomendacion) }}">
                                                @csrf
                                                <x-secondary-button type="submit">{{ __('Rechazar') }}</x-secondary-button>
                                            </form>
                                        </div>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            @endif
        </div>
    </div>
</x-app-layout>
