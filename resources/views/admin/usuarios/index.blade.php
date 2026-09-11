@php
    use App\Models\User;

    $colorDeEstado = [
        User::ESTADO_ACTIVO => 'bg-tudi-lime',
        User::ESTADO_PENDIENTE => 'bg-tudi-amber',
        User::ESTADO_SUSPENDIDO => 'bg-tudi-input-border',
    ];
@endphp

{{-- Gestión de usuarios (CLAUDE.md sección 4.26). --}}
<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between gap-3">
            <div class="flex min-w-0 items-center gap-3">
                <a href="{{ route('admin.inicio') }}"
                   class="grid h-11 w-11 flex-none place-items-center rounded-full text-tudi-muted no-underline hover:bg-tudi-surface"
                   aria-label="{{ __('Volver a Administración') }}">
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7" />
                    </svg>
                </a>
                <h1 class="truncate text-lg font-semibold tracking-tudi-title sm:text-2xl">{{ __('Usuarios') }}</h1>
            </div>
            <span class="tudi-meta">{{ trans_choice(':count cuenta|:count cuentas', $usuarios->total()) }}</span>
        </div>
    </x-slot>

    <x-tudi.flash :mensajes="[
        'usuario-actualizado' => __('Cuenta actualizada.'),
        'usuario-eliminado' => __('Cuenta eliminada.'),
        'plan-premium' => __('Cuenta habilitada en Premium.'),
        'plan-degradado' => __('Cuenta devuelta al plan Gratis.'),
    ]" />

    <div class="space-y-4">
        {{-- ══ Búsqueda y filtro ══ --}}
        <form method="get" action="{{ route('admin.usuarios.index') }}" class="tudi-card flex flex-wrap items-end gap-3 p-4">
            <div class="min-w-[200px] flex-1">
                <label for="q" class="tudi-label">{{ __('Buscar') }}</label>
                <input id="q" name="q" type="search" value="{{ $busqueda }}"
                       placeholder="{{ __('nombre, correo o código') }}" class="tudi-input mt-1">
            </div>
            <div>
                <label for="estado" class="tudi-label">{{ __('Estado') }}</label>
                <select id="estado" name="estado" class="tudi-input mt-1">
                    <option value="">{{ __('Todos') }}</option>
                    @foreach ([User::ESTADO_PENDIENTE, User::ESTADO_ACTIVO, User::ESTADO_SUSPENDIDO] as $opcion)
                        <option value="{{ $opcion }}" @selected($estado === $opcion)>{{ __(ucfirst($opcion)) }}</option>
                    @endforeach
                </select>
            </div>
            <button type="submit" class="tudi-btn tudi-btn-primary">{{ __('Filtrar') }}</button>
        </form>

        {{-- ══ Listado ══ --}}
        @if ($usuarios->isEmpty())
            <p class="px-1 text-sm text-tudi-muted">{{ __('Ninguna cuenta coincide con la búsqueda.') }}</p>
        @else
            <ul class="space-y-2">
                @foreach ($usuarios as $usuario)
                    @php $esUnoMismo = $usuario->is(Auth::user()); @endphp

                    <li class="tudi-card p-4">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="flex items-center gap-2 font-semibold tracking-tudi-title">
                                    <span class="h-2 w-2 flex-none rounded-full {{ $colorDeEstado[$usuario->estado] ?? 'bg-tudi-input-border' }}"></span>
                                    {{ $usuario->name }}
                                    <span class="sr-only">{{ __($usuario->estado) }}</span>
                                    @if ($usuario->esAdministrador())
                                        <span class="tudi-chip tudi-chip-solid">{{ __('admin') }}</span>
                                    @endif
                                    @if ($usuario->tienePremium())
                                        <span class="tudi-chip tudi-chip-solid text-tudi-lime">
                                            {{ $usuario->enPrueba() ? __('prueba') : __('premium') }}
                                        </span>
                                    @endif
                                    @if ($esUnoMismo)
                                        <span class="tudi-chip">{{ __('tú') }}</span>
                                    @endif
                                </p>
                                <p class="tudi-meta truncate">{{ $usuario->email }}</p>
                                <p class="tudi-meta mt-0.5">
                                    {{ __(ucfirst($usuario->estado)) }}
                                    @if ($usuario->calorias_objetivo !== null)
                                        · {{ number_format((float) $usuario->calorias_objetivo, 0, ',', '.') }} kcal/día
                                    @else
                                        · {{ __('sin objetivo calculado') }}
                                    @endif
                                </p>
                            </div>

                            @if ($usuario->codigo_activacion)
                                <span class="tudi-chip tudi-chip-solid flex-none font-mono text-sm tracking-[0.25em]">
                                    {{ $usuario->codigo_activacion }}
                                </span>
                            @endif
                        </div>

                        {{--
                            Un administrador no puede tocarse a sí mismo: es la
                            forma más fácil de quedarse sin consola accesible.
                        --}}
                        @unless ($esUnoMismo)
                            <div class="mt-3 flex flex-wrap gap-2 border-t border-tudi-divider pt-3">
                                @if ($usuario->estado !== User::ESTADO_ACTIVO)
                                    <form method="post" action="{{ route('admin.usuarios.update', $usuario) }}">
                                        @csrf
                                        @method('patch')
                                        <input type="hidden" name="estado" value="{{ User::ESTADO_ACTIVO }}">
                                        <button type="submit" class="tudi-btn tudi-btn-primary">{{ __('Activar') }}</button>
                                    </form>
                                @else
                                    <form method="post" action="{{ route('admin.usuarios.update', $usuario) }}">
                                        @csrf
                                        @method('patch')
                                        <input type="hidden" name="estado" value="{{ User::ESTADO_SUSPENDIDO }}">
                                        <button type="submit" class="tudi-btn tudi-btn-secondary">{{ __('Suspender') }}</button>
                                    </form>
                                @endif

                                {{--
                                    Premium a mano hasta que cobre la pasarela de
                                    pago (sección 5.18). Quitarlo no cierra nada:
                                    la cuenta sigue entrando y con su historial.
                                --}}
                                <form method="post" action="{{ route('admin.usuarios.plan', $usuario) }}">
                                    @csrf
                                    <button type="submit" class="tudi-btn tudi-btn-secondary">
                                        {{ $usuario->tienePremium() ? __('Quitar Premium') : __('Dar Premium') }}
                                    </button>
                                </form>

                                <form method="post" action="{{ route('admin.usuarios.update', $usuario) }}">
                                    @csrf
                                    @method('patch')
                                    <input type="hidden" name="rol"
                                           value="{{ $usuario->esAdministrador() ? User::ROL_USUARIO : User::ROL_ADMIN }}">
                                    <button type="submit" class="tudi-btn tudi-btn-secondary">
                                        {{ $usuario->esAdministrador() ? __('Quitar admin') : __('Hacer admin') }}
                                    </button>
                                </form>

                                {{--
                                    Borrar arrastra en cascada todo el historial
                                    del usuario, así que pide confirmación
                                    explícita antes de enviarse.
                                --}}
                                <div x-data="{ confirmando: false }">
                                    <button type="button" x-show="! confirmando" x-on:click="confirmando = true"
                                            class="tudi-btn tudi-btn-secondary text-tudi-amber-ink">
                                        {{ __('Eliminar') }}
                                    </button>

                                    <form method="post" action="{{ route('admin.usuarios.destroy', $usuario) }}"
                                          x-show="confirmando" style="display: none" class="flex gap-2">
                                        @csrf
                                        @method('delete')
                                        <button type="submit" class="tudi-btn bg-tudi-amber text-tudi-ink">
                                            {{ __('Sí, eliminar y borrar su historial') }}
                                        </button>
                                        <button type="button" x-on:click="confirmando = false" class="tudi-btn tudi-btn-ghost">
                                            {{ __('Cancelar') }}
                                        </button>
                                    </form>
                                </div>
                            </div>
                        @endunless
                    </li>
                @endforeach
            </ul>

            @if ($usuarios->hasPages())
                <div class="tudi-card p-4">
                    {{ $usuarios->links() }}
                </div>
            @endif
        @endif
    </div>
</x-app-layout>
