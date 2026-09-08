{{-- Portada de la consola (CLAUDE.md sección 4.26): lo pendiente de atender. --}}
<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between gap-3">
            <h1 class="text-lg font-semibold tracking-tudi-title sm:text-2xl">{{ __('Administración') }}</h1>
            <x-tudi.avatar-menu />
        </div>
    </x-slot>

    <x-tudi.flash :mensajes="[
        'usuario-actualizado' => __('Cuenta actualizada.'),
        'codigo-regenerado' => __('Código nuevo generado.'),
        'usuario-eliminado' => __('Cuenta eliminada.'),
    ]" />

    <div class="space-y-6">
        {{-- ══ Panel de cifras ══ --}}
        <div class="tudi-panel on-dark">
            <p class="tudi-label">{{ __('Cuentas pendientes de activación') }}</p>
            <p class="mt-1 flex items-baseline gap-2">
                <span class="tudi-num text-[52px] {{ $totales['pendientes'] > 0 ? 'text-tudi-lime' : 'text-tudi-on-dark-3' }}">
                    {{ $totales['pendientes'] }}
                </span>
                <span class="tudi-meta">{{ trans_choice('cuenta|cuentas', $totales['pendientes']) }}</span>
            </p>

            <div class="mt-5 grid grid-cols-2 gap-2.5 sm:grid-cols-4">
                @foreach ([
                    ['etiqueta' => __('Total'), 'valor' => $totales['usuarios']],
                    ['etiqueta' => __('Activas'), 'valor' => $totales['activos']],
                    ['etiqueta' => __('Suspendidas'), 'valor' => $totales['suspendidos']],
                    ['etiqueta' => __('Administradores'), 'valor' => $totales['administradores']],
                ] as $tile)
                    <div class="tudi-panel-tile">
                        <p class="tudi-label">{{ $tile['etiqueta'] }}</p>
                        <p class="tudi-num mt-1 text-xl text-tudi-on-dark">{{ $tile['valor'] }}</p>
                    </div>
                @endforeach
            </div>
        </div>

        {{-- ══ Cola de activación ══ --}}
        <section class="space-y-3">
            <div class="flex items-center justify-between gap-3 px-1">
                <p class="tudi-label">{{ __('Esperando su código') }}</p>
                <a href="{{ route('admin.usuarios.index') }}" class="tudi-link text-[13px]">
                    {{ __('Ver todas las cuentas') }}
                </a>
            </div>

            @if ($pendientes->isEmpty())
                <p class="px-1 text-sm text-tudi-muted">{{ __('No hay ninguna cuenta esperando activación.') }}</p>
            @else
                <ul class="space-y-2">
                    @foreach ($pendientes as $usuario)
                        <li class="tudi-card p-4">
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <p class="font-semibold tracking-tudi-title">{{ $usuario->name }}</p>
                                    <p class="tudi-meta truncate">{{ $usuario->email }}</p>
                                    <p class="tudi-meta mt-0.5">
                                        {{ __('registrado') }} {{ $usuario->created_at->translatedFormat('d M Y · H:i') }}
                                    </p>
                                </div>

                                {{--
                                    El código, a la vista: es lo que el
                                    administrador tiene que entregarle.
                                --}}
                                <span class="tudi-chip tudi-chip-solid flex-none font-mono text-sm tracking-[0.25em]">
                                    {{ $usuario->codigo_activacion }}
                                </span>
                            </div>

                            <div class="mt-3 flex flex-wrap gap-2">
                                <form method="post" action="{{ route('admin.usuarios.update', $usuario) }}">
                                    @csrf
                                    @method('patch')
                                    <input type="hidden" name="estado" value="{{ \App\Models\User::ESTADO_ACTIVO }}">
                                    <button type="submit" class="tudi-btn tudi-btn-primary">{{ __('Activar ahora') }}</button>
                                </form>

                                <form method="post" action="{{ route('admin.usuarios.codigo', $usuario) }}">
                                    @csrf
                                    <button type="submit" class="tudi-btn tudi-btn-secondary">{{ __('Generar otro código') }}</button>
                                </form>
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>

        <a href="{{ route('admin.parametros.edit') }}"
           class="tudi-card flex items-center justify-between gap-4 p-5 text-tudi-ink no-underline hover:bg-tudi-card-inset">
            <span>
                <span class="block font-semibold tracking-tudi-title">{{ __('Parámetros maestros') }}</span>
                <span class="tudi-meta">{{ __('Umbrales del motor de recomendaciones y de la sugerencia de actividad') }}</span>
            </span>
            <svg class="h-5 w-5 flex-none text-tudi-muted" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="m9 5 7 7-7 7" />
            </svg>
        </a>
    </div>
</x-app-layout>
