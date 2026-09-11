{{--
    Bloque 1 — Bienvenida (CLAUDE.md sección 5.8), solo en el primer login: el
    usuario todavía no tiene ningún RegistroDiario, así que los bloques Hoy /
    Tu tendencia / Tu seguimiento se sustituyen enteros por un único mensaje
    con una sola acción. Mostrarlos vacíos sería un anillo en 0%, un gráfico
    sin puntos y una tabla en blanco — ceros disfrazados de progreso, justo
    en el peor momento para desmotivar a alguien que recién llega.
--}}
<div class="tudi-panel text-center">
    <p class="tudi-label">{{ __('Bienvenido a TUDéficit Inteligente') }}</p>

    @if ($ctaCalculadora)
        <p class="mt-3 text-tudi-on-dark-2">
            {{ __('Para calcular tu objetivo calórico primero necesitamos tu peso, tu nivel de actividad y tu meta. Es un solo paso.') }}
        </p>
        <a href="{{ route('calculadora.edit') }}" class="tudi-btn tudi-btn-lime tudi-btn-block mt-5 no-underline">
            {{ __('Ir a la Calculadora Déficit') }}
        </a>
    @else
        <p class="mt-3 text-tudi-on-dark-2">
            {{ __('Ya tienes tu objetivo calórico listo. El siguiente paso es crear tu primer plan de hoy.') }}
        </p>
        <form method="post" action="{{ route('planes.crear') }}" class="mt-5">
            @csrf
            <button type="submit" class="tudi-btn tudi-btn-lime tudi-btn-block">
                {{ __('Crear tu primer plan de hoy') }}
            </button>
        </form>
    @endif
</div>
