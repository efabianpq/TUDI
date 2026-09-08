{{--
    Canje del código de activación (CLAUDE.md sección 4.26).

    Se apoya en el layout de invitado aunque el usuario ya tenga sesión: la
    navegación de la aplicación llevaría a rutas que su cuenta todavía no puede
    abrir. Lo único que se le ofrece aquí es introducir el código o salir.
--}}
<x-guest-layout>
    <h1 class="text-xl font-semibold tracking-tudi-title">{{ __('Activa tu cuenta') }}</h1>

    <p class="mt-2 text-sm leading-snug text-tudi-ink-3">
        {{ __('Tu cuenta está creada. Para entrar necesitas el código de activación que entrega el administrador de TUDéficit Inteligente.') }}
    </p>

    <x-auth-session-status class="mt-4" :status="session('status')" />

    <form method="post" action="{{ route('activacion.store') }}" class="mt-5 space-y-4">
        @csrf

        <div>
            <x-input-label for="codigo" :value="__('Código de activación')" />
            {{--
                Ocho caracteres de un alfabeto sin 0/O ni 1/I/L, así que se
                muestra en mono y espaciado: se dicta y se copia a mano.
            --}}
            <x-text-input id="codigo" name="codigo" type="text" required autofocus
                          autocomplete="off" autocapitalize="characters" spellcheck="false"
                          maxlength="8" placeholder="XXXXXXXX"
                          class="mt-1.5 text-center font-mono text-xl uppercase tracking-[0.3em]"
                          :value="old('codigo')" />
            <x-input-error :messages="$errors->get('codigo')" class="mt-2" />
        </div>

        <x-primary-button class="tudi-btn-block">{{ __('Activar mi cuenta') }}</x-primary-button>
    </form>

    <div class="mt-5 flex items-center justify-between gap-3 border-t border-tudi-divider pt-4">
        <p class="text-[13px] text-tudi-muted">{{ __('¿Todavía no tienes el código?') }}</p>

        <form method="post" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="tudi-link text-[13px]">{{ __('Cerrar sesión') }}</button>
        </form>
    </div>
</x-guest-layout>
