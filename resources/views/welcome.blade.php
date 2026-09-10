{{--
    Landing pública (CLAUDE.md sección 5.19).

    No usa `layouts.guest` a propósito: aquel es la tarjeta centrada del login y
    el registro, no una página de ancho completo. Sí usa el mismo sistema visual
    —tokens, `.tudi-*` y las utilidades `tudi-` de Tailwind—, así que quien pasa
    de aquí al registro no cambia de mundo.

    Los precios llegan de PlanService (config/planes.php); aquí no hay ni un
    importe escrito a mano.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="scroll-smooth">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">

        <title>{{ __('TUDI · Entiende cómo comes') }}</title>
        <meta name="description"
              content="{{ __('TUDI convierte lo que realmente comes y te mueves en un balance calórico claro, día a día. Para bajar de peso, mantenerte, o solo entender mejor tu alimentación.') }}">

        <link rel="manifest" href="{{ asset('manifest.webmanifest') }}">
        <meta name="theme-color" content="#171512">
        <link rel="apple-touch-icon" href="{{ asset('icons/apple-touch-icon.png') }}">
        <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('icons/favicon-32.png') }}">

        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Instrument+Sans:wght@400..700&family=JetBrains+Mono:wght@400;500&display=swap">

        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>

    <body class="min-h-[100dvh] bg-tudi-bg font-sans text-tudi-ink antialiased">

        {{-- ══ Nav ══ --}}
        <header class="sticky top-0 z-10 border-b border-tudi-border bg-tudi-bg pt-[env(safe-area-inset-top)]">
            <div class="mx-auto flex max-w-6xl items-center justify-between gap-4 px-4 py-3 sm:px-6 sm:py-4">
                <a href="{{ route('landing') }}" class="text-tudi-ink no-underline">
                    <x-tudi.marca :size="32" :texto="19" />
                </a>

                <nav class="flex items-center gap-1 sm:gap-4">
                    <a href="#precios"
                       class="hidden rounded-tudi-pill px-3 py-2 text-sm font-medium text-tudi-ink-2 no-underline hover:bg-tudi-surface sm:inline-flex">
                        {{ __('Precios') }}
                    </a>
                    <a href="{{ route('login') }}"
                       class="inline-flex min-h-[44px] items-center whitespace-nowrap rounded-tudi-pill px-3 text-sm font-medium text-tudi-ink-2 no-underline hover:bg-tudi-surface">
                        {{ __('Iniciar sesión') }}
                    </a>
                    {{--
                        En móvil el botón se acorta: con los tres elementos a la
                        vez, "Empieza gratis" partía en dos líneas y estiraba
                        la barra. El destino es el mismo.
                    --}}
                    <a href="{{ route('register') }}"
                       class="inline-flex min-h-[44px] flex-none items-center whitespace-nowrap rounded-tudi-pill bg-tudi-ink px-4 text-sm font-semibold text-tudi-on-dark no-underline hover:bg-[#262219]">
                        {{ __('Empieza') }}<span class="hidden sm:inline">&nbsp;{{ __('gratis') }}</span>
                    </a>
                </nav>
            </div>
        </header>

        <main>
            {{-- ══ Hero ══ --}}
            <section class="mx-auto grid max-w-6xl gap-10 px-4 pb-10 pt-12 sm:px-6 lg:grid-cols-[minmax(0,1fr)_minmax(280px,420px)] lg:items-center lg:gap-12 lg:pt-16">
                <div class="flex flex-col gap-5">
                    <p class="tudi-label !text-tudi-lime-700">{{ __('Nutrición con datos, no con dietas genéricas') }}</p>

                    <h1 class="text-balance text-[34px] font-semibold leading-[1.03] tracking-tudi-display sm:text-5xl lg:text-[54px]">
                        {{ __('Entiende cómo comes. Decide qué hacer con eso.') }}
                    </h1>

                    <p class="max-w-[46ch] text-lg leading-relaxed text-tudi-ink-2">
                        {{ __('TUDI convierte lo que realmente comes y te mueves en un balance calórico claro, día a día. Sirve tanto si quieres bajar de peso, como si quieres mantenerte, como si simplemente quieres dejar de adivinar cuánta proteína o cuántas calorías llevas en el día.') }}
                    </p>

                    <div class="mt-1 flex flex-wrap items-center gap-3">
                        <a href="{{ route('register') }}"
                           class="tudi-btn tudi-btn-primary !rounded-tudi-pill !px-7 no-underline">
                            {{ __('Calcula tu objetivo gratis') }}
                        </a>
                        <a href="#precios"
                           class="tudi-btn tudi-btn-secondary !rounded-tudi-pill no-underline">
                            {{ __('Ver precios') }}
                        </a>
                    </div>

                    <p class="text-[13px] text-tudi-muted">
                        {{ __('Sin tarjeta. Decide tú si quieres bajar, mantener, o solo entender mejor tu alimentación.') }}
                    </p>
                </div>

                {{-- La única cifra protagonista de la página. --}}
                <div class="on-dark flex flex-col items-center rounded-[32px] bg-tudi-dark px-6 py-7 shadow-tudi-lg">
                    <div class="tudi-ring w-[200px] sm:w-[220px]" style="--pct: 89" role="img"
                         aria-label="{{ __('Ejemplo: 146 kcal por debajo del objetivo') }}">
                        <div>
                            <p class="tudi-label">{{ __('Tu balance de hoy') }}</p>
                            <p class="tudi-num mt-1 text-[52px] text-tudi-lime sm:text-[56px]">146</p>
                            <p class="tudi-meta">{{ __('kcal por debajo') }}</p>
                        </div>
                    </div>
                    <p class="mt-4 text-sm text-tudi-on-dark-2">2.528 {{ __('consumidas') }} · 2.376 {{ __('objetivo') }}</p>
                </div>
            </section>

            {{-- ══ Cómo funciona ══ --}}
            <section class="mx-auto max-w-6xl px-4 py-12 sm:px-6 lg:py-14">
                <p class="tudi-label">{{ __('Cómo funciona') }}</p>

                <div class="mt-7 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ([
                        ['01', __('Define tu objetivo'), __('Mantener, bajar un 10/20/30%, o simplemente ver tus números claros. Tú eliges — nada asume que quieres perder peso.')],
                        ['02', __('Genera tu plan'), __('Le dices qué tienes para comer (o lo dictas), y la IA reparte tus comidas del día para que cuadren con tu objetivo.')],
                        ['03', __('Registra y ajusta'), __('Registra lo que realmente pasó. El sistema mira tendencias de 7 días, no un mal día ni una comida aislada.')],
                    ] as [$numero, $titulo, $texto])
                        <div class="tudi-card flex flex-col gap-3 p-6">
                            <p class="font-mono text-xs text-tudi-lime-700">{{ $numero }}</p>
                            <h2 class="text-[19px] font-semibold tracking-tudi-title">{{ $titulo }}</h2>
                            <p class="text-sm leading-relaxed text-tudi-ink-3">{{ $texto }}</p>
                        </div>
                    @endforeach
                </div>
            </section>

            {{-- ══ Diferenciador ══ --}}
            <section class="mx-auto grid max-w-6xl gap-4 px-4 pb-12 sm:px-6 lg:grid-cols-2 lg:items-start lg:pb-14">
                <div class="flex flex-col gap-3 rounded-tudi-xl bg-tudi-dark p-8 text-tudi-on-dark">
                    <h2 class="text-[22px] font-semibold leading-snug tracking-tudi-title">
                        {{ __('No es una app de dietas ni un contador de calorías más.') }}
                    </h2>
                    <p class="text-[15px] leading-relaxed text-tudi-on-dark-2">
                        {{ __('Es un sistema que aprende de tu semana real, no de una plantilla.') }}
                    </p>

                    <hr class="mt-0.5 border-tudi-dark-3">

                    <ul class="flex flex-col gap-3 text-[15px] leading-relaxed text-tudi-on-dark">
                        <li>{{ __('Genera comidas a partir de lo que de verdad tienes disponible — no de una lista de recetas genérica.') }}</li>
                        <li>{{ __('Corrige la sobreestimación típica de relojes y pulseras al calcular tu gasto energético real.') }}</li>
                        <li>{{ __('Detecta tendencias en 7 días — nunca decide nada por un solo día atípico.') }}</li>
                        <li>{{ __('Te avisa si tu ritmo es demasiado lento, demasiado rápido, o si estás en un punto muerto — sea cual sea tu objetivo.') }}</li>
                    </ul>
                </div>

                <div class="flex flex-col gap-3 rounded-tudi-xl bg-tudi-surface p-8">
                    <h2 class="text-[22px] font-semibold leading-snug tracking-tudi-title">
                        {{ __('Nunca actúa solo.') }}
                    </h2>
                    <p class="text-[15px] leading-relaxed text-tudi-ink-2">
                        {{ __('Cuando tu tendencia de 7 días pide ajustar tu objetivo calórico, TUDI te lo propone — y solo se aplica si tú lo confirmas.') }}
                    </p>
                </div>
            </section>

            {{-- ══ ¿Para quién es esto? ══ --}}
            <section class="mx-auto max-w-6xl px-4 pb-14 sm:px-6 lg:pb-16">
                <div class="flex flex-col gap-2.5">
                    <p class="tudi-label">{{ __('¿Para quién es esto?') }}</p>
                    <h2 class="text-balance text-[26px] font-semibold tracking-tudi-title sm:text-4xl">
                        {{ __('No hace falta estar "a dieta" para usar TUDI') }}
                    </h2>
                </div>

                <div class="mt-7 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ([
                        [70, __('Quieres bajar de peso'), __('Planes ajustados a tu déficit, con seguimiento de tendencia real para saber si vas al ritmo correcto.')],
                        [50, __('Quieres mantenerte'), __('Ajusta tu objetivo a Mantener y usa la misma herramienta para no perder el control cuando ya llegaste a donde querías.')],
                        [25, __('Quieres comer mejor, sin más'), __('Entiende cuánta proteína, grasa y carbohidratos llevas en el día — sin básculas, sin cálculos a mano, sin ser un experto en nutrición.')],
                    ] as [$pct, $titulo, $texto])
                        <div class="tudi-card flex flex-col gap-3 p-6">
                            <span aria-hidden="true" class="h-[34px] w-[34px] flex-none rounded-full"
                                  style="background: conic-gradient(var(--tudi-lime) 0 {{ $pct * 3.6 }}deg, var(--tudi-surface) {{ $pct * 3.6 }}deg 360deg)"></span>
                            <h3 class="text-[19px] font-semibold tracking-tudi-title">{{ $titulo }}</h3>
                            <p class="text-sm leading-relaxed text-tudi-ink-3">{{ $texto }}</p>
                        </div>
                    @endforeach
                </div>

                <p class="mt-7 text-base text-tudi-ink-2">
                    {{ __('La misma herramienta, tres formas de usarla. El objetivo lo pones tú, no la app.') }}
                </p>
            </section>

            {{-- ══ Precios ══ --}}
            <section id="precios" class="mx-auto max-w-6xl scroll-mt-20 px-4 pb-14 sm:px-6">
                <div class="flex flex-col items-center gap-3 text-center">
                    <p class="tudi-label">{{ __('Precios') }}</p>
                    <h2 class="text-[26px] font-semibold tracking-tudi-title sm:text-4xl">
                        {{ __('Empieza gratis. Paga solo por la IA.') }}
                    </h2>
                    <p class="max-w-[52ch] text-[15px] text-tudi-ink-3">
                        {{ __('La calculadora, el registro y el cierre diario son gratis para siempre. Premium desbloquea la distribución de comidas con IA y la estimación de consumo.') }}
                    </p>
                    <p class="max-w-[52ch] text-sm text-tudi-muted">
                        {{ __('Igual de útil si tu objetivo es bajar, mantener, o solo entender mejor lo que comes.') }}
                    </p>
                </div>

                <div class="mt-8 grid items-stretch gap-5 lg:grid-cols-2">
                    {{-- Gratis --}}
                    <div class="tudi-card flex flex-col gap-5 p-8">
                        <div>
                            <p class="font-semibold text-tudi-ink-2">{{ __('Gratis') }}</p>
                            <p class="tudi-num mt-2 text-[38px]">{{ $precios['moneda'] }} $0</p>
                            <p class="mt-0.5 text-[13px] text-tudi-muted">{{ __('Para siempre') }}</p>
                        </div>

                        <hr class="border-tudi-divider">

                        <ul class="flex flex-col gap-2.5 text-sm text-tudi-ink-2">
                            @foreach ($incluyeGratis as $funcion)
                                <li>{{ __($funcion) }}</li>
                            @endforeach
                        </ul>

                        <a href="{{ route('register') }}"
                           class="tudi-btn tudi-btn-secondary tudi-btn-block !rounded-tudi-pill mt-auto no-underline">
                            {{ __('Crear cuenta gratis') }}
                        </a>
                    </div>

                    {{-- Premium --}}
                    <div class="on-dark relative flex flex-col gap-5 rounded-tudi-xl bg-tudi-dark p-8 text-tudi-on-dark">
                        <span class="absolute -top-3 right-7 rounded-tudi-pill bg-tudi-lime px-3 py-1 font-mono text-[10px] tracking-tudi-label text-tudi-ink">
                            {{ __('DISPONIBLE SIN COSTO') }}
                        </span>

                        <div>
                            <p class="font-semibold text-tudi-lime">{{ __('Premium') }}</p>
                            <p class="mt-2 flex items-baseline gap-1.5">
                                <span class="tudi-num text-[38px]">{{ $precios['mensual_formateado'] }}</span>
                                <span class="tudi-meta">/{{ __('mes') }}</span>
                            </p>
                            <p class="mt-0.5 text-[13px] text-tudi-on-dark-2">
                                {{ __('o :precio al año — :pct% menos. Aún no lo cobramos.', [
                                    'precio' => $precios['anual_formateado'],
                                    'pct' => $precios['ahorro_anual_pct'],
                                ]) }}
                            </p>
                        </div>

                        <hr class="border-tudi-dark-3">

                        <ul class="flex flex-col gap-2.5 text-sm">
                            @foreach ($incluyePremium as $funcion)
                                <li>{{ __($funcion) }}</li>
                            @endforeach
                        </ul>

                        <a href="{{ route('register') }}"
                           class="tudi-btn tudi-btn-lime tudi-btn-block !rounded-tudi-pill mt-auto no-underline">
                            {{ __('Empieza gratis') }}
                        </a>
                    </div>
                </div>

                <p class="mt-6 text-center text-[13px] text-tudi-muted">
                    {{ __('Sin tarjeta para el registro. El cobro de Premium aún no está abierto: por ahora puedes crear tu cuenta y usar todo sin pagar. Te avisaremos antes de activar cualquier cobro.') }}
                </p>
            </section>

            {{-- ══ CTA final ══ --}}
            <section class="mx-auto max-w-6xl px-4 pb-16 sm:px-6 lg:pb-20">
                <div class="flex flex-col items-center gap-4 rounded-[28px] bg-tudi-surface px-6 py-12 text-center sm:px-10">
                    <h2 class="text-[26px] font-semibold tracking-tudi-title sm:text-[34px]">
                        {{ __('Deja de adivinar cómo comes.') }}
                    </h2>
                    <p class="max-w-[48ch] text-[15px] text-tudi-ink-3">
                        {{ __('Bajar de peso, mantenerte, o simplemente entender tus macros — empieza gratis, sin tarjeta.') }}
                    </p>
                    <a href="{{ route('register') }}"
                       class="tudi-btn tudi-btn-primary !rounded-tudi-pill !px-8 mt-1 no-underline">
                        {{ __('Crear mi cuenta gratis') }}
                    </a>
                </div>
            </section>
        </main>

        {{-- ══ Footer ══ --}}
        <footer class="border-t border-tudi-border">
            <div class="mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-3 px-4 py-7 pb-[calc(env(safe-area-inset-bottom)+1.75rem)] sm:px-6">
                <span class="flex items-center gap-2.5">
                    <x-tudi.isotipo :size="24" />
                    <span class="tudi-meta">TUDEFICITINTELIGENTE.ONLINE</span>
                </span>
                <span class="text-[13px] text-tudi-muted">© {{ now()->year }} TUDI</span>
            </div>
        </footer>
    </body>
</html>
