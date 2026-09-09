@php
    use App\Services\NutritionCalculatorService as Calculadora;

    /*
     * Calculadora Déficit (CLAUDE.md secciones 4.14 y 4.25).
     *
     * La pantalla pregunta lo mismo que una calculadora calórica al uso —sexo,
     * peso, estatura, edad, cuán activo eres y qué objetivo persigues— y deriva
     * de ahí los factores de proteína y grasa, en vez de pedírselos al usuario.
     * Los factores siguen existiendo (la sección 5 los necesita) y se pueden
     * ajustar a mano en "Ajustes avanzados"; simplemente dejan de estar en el
     * camino principal.
     */

    $tipoDeficit = old('tipo_deficit', $user->tipo_deficit) ?: 'porcentaje';
    $valorDeficit = (float) old('valor_deficit', $user->valor_deficit);

    $inicial = [
        'peso' => (string) ((float) (old('peso_kg', $user->peso_kg) ?: 70)),
        'estatura' => (string) ((float) (old('estatura_m', $user->estatura_m) ?: 1.70)),
        'edad' => (int) (old('edad', $user->edad) ?: 30),
        'sexo' => old('sexo', $user->sexo) ?: 'masculino',
        'nivel' => (float) (old('nivel_actividad', $user->nivel_actividad) ?: 1.55),
        'tipo' => $tipoDeficit,
        'porcentaje' => $tipoDeficit === 'porcentaje' && $valorDeficit > 0 ? round($valorDeficit * 100) : 20,
        'fijo' => $tipoDeficit === 'fijo' && $valorDeficit > 0 ? round($valorDeficit) : 500,
        'proteina' => (string) ((float) (old('proteina_factor', $user->proteina_factor) ?: 2.0)),
        'grasa' => (string) ((float) (old('grasa_factor', $user->grasa_factor) ?: 0.8)),
        'vigente' => $user->calorias_objetivo !== null ? (float) $user->calorias_objetivo : null,
        // Rangos de la sección 5, para no repetir los números en JS.
        'proteinaMin' => Calculadora::PROTEINA_FACTOR_MIN,
        'proteinaMax' => Calculadora::PROTEINA_FACTOR_MAX,
        'grasaMin' => Calculadora::GRASA_FACTOR_MIN,
        'grasaMax' => Calculadora::GRASA_FACTOR_MAX,
    ];

    /*
     * Los cuatro escalones caben dentro del rango 1.2–1.725 que valida
     * ProfileParametersRequest. La explicación importa más que el nombre: es la
     * elección que más mueve el resultado, y la que más gente falla.
     */
    $nivelesActividad = [
        [
            'valor' => 1.2,
            'texto' => __('Sedentario'),
            'explicacion' => __('Trabajo de escritorio y poco movimiento fuera de él. No entrenas, o lo haces menos de una vez por semana.'),
        ],
        [
            'valor' => 1.375,
            'texto' => __('Ligeramente activo · 1-3 veces por semana'),
            'explicacion' => __('Trabajo de escritorio con algo de ejercicio, o un trabajo en el que estás de pie mucho tiempo —docencia, enfermería— sin entrenar aparte.'),
        ],
        [
            'valor' => 1.55,
            'texto' => __('Moderadamente activo · 3-5 veces por semana'),
            'explicacion' => __('Entrenas casi todos los días laborables, o tu trabajo implica caminar y cargar peso buena parte de la jornada.'),
        ],
        [
            'valor' => 1.725,
            'texto' => __('Muy activo · 6-7 veces por semana'),
            'explicacion' => __('Entrenas a diario y con intensidad, o tu trabajo es físico de principio a fin: construcción, mensajería en bici, mudanzas.'),
        ],
    ];

    /*
     * El objetivo sustituye al par "tipo de déficit + valor" en el camino
     * principal: todos son déficit por porcentaje, que es como lo plantean las
     * calculadoras de referencia. El déficit fijo sigue disponible en avanzado.
     *
     * `proteina` es el factor que se deriva de cada objetivo: cuanto más
     * agresivo el déficit, más proteína hace falta para conservar masa magra.
     * Siempre dentro del rango 1.6–2.2 de la sección 5.
     */
    $objetivos = [
        [
            'valor' => 0,
            'texto' => __('Mantener mi peso'),
            'explicacion' => __('Comes aproximadamente lo que gastas. Útil para estabilizar antes o después de una etapa de déficit.'),
            'proteina' => 1.6,
        ],
        [
            'valor' => 10,
            'texto' => __('Perder despacio · −10%'),
            'explicacion' => __('El más sostenible: menos hambre y menos pérdida de músculo. Ronda los 0,25–0,5 kg por semana.'),
            'proteina' => 1.8,
        ],
        [
            'valor' => 20,
            'texto' => __('Perder · −20%'),
            'explicacion' => __('El punto medio, y el que la app usa por defecto. Ronda los 0,5–0,75 kg por semana.'),
            'proteina' => 2.0,
        ],
        [
            'valor' => 30,
            'texto' => __('Perder rápido · −30%'),
            'explicacion' => __('Exige disciplina y cuidar mucho la proteína. No conviene sostenerlo muchos meses seguidos.'),
            'proteina' => 2.2,
        ],
    ];

    /*
     * El escalón/objetivo más cercano al valor guardado, para renderizar su
     * explicación desde el servidor. Un perfil anterior al rediseño puede tener
     * un nivel que no coincida exactamente con ninguno; marcar el más cercano
     * no cambia el valor que se persiste (lo lleva el campo oculto).
     */
    $masCercano = fn (array $opciones, float $valor) => collect($opciones)
        ->sortBy(fn (array $opcion) => abs($opcion['valor'] - $valor))
        ->first();

    $nivelElegido = $masCercano($nivelesActividad, $inicial['nivel']);
    $objetivoElegido = $masCercano($objetivos, (float) $inicial['porcentaje']);

    // Material de apoyo publicado desde la consola (sección 5.15). Sin nada
    // publicado, la pantalla es exactamente la de antes.
    $hayMaterial = $videoIncrustado !== null || $guiaPdf !== null;
@endphp

<x-app-layout>
    <x-slot name="header">
        <h1 class="text-lg font-semibold tracking-tudi-title sm:text-2xl">{{ __('Calculadora') }}</h1>
    </x-slot>

    <x-tudi.flash :mensajes="['parametros-updated' => __('Guardado.')]" />

    {{--
        El objetivo se recalcula en vivo mientras se mueven los controles: es
        una vista previa que refleja la fórmula de CLAUDE.md sección 5, no la
        cifra persistida. Quien calcula y guarda `users.calorias_objetivo` es
        siempre NutritionCalculatorService en el servidor, al pulsar el botón.
    --}}
    <form method="post" action="{{ route('calculadora.update') }}" class="mx-auto max-w-2xl space-y-5"
          x-data="calculadoraDeficit(@js($inicial))"
          x-on:input="tocado = true"
          x-on:change="tocado = true">
        @csrf
        @method('put')

        {{-- ── El resultado, arriba ── --}}
        <div class="tudi-panel on-dark">
            <p class="tudi-label">{{ __('Tu objetivo diario') }}</p>

            <p class="mt-1 flex items-baseline gap-2">
                <span class="tudi-num text-[52px] text-tudi-lime" x-text="entero(objetivo)"></span>
                <span class="tudi-meta">kcal</span>
            </p>

            <p class="tudi-meta mt-1" x-text="resumenDelDeficit"></p>

            {{-- Palabra completa, no la inicial: aquí el espacio lo permite
                 (CLAUDE.md sección 5.12). --}}
            <div class="mt-3.5 flex flex-wrap gap-1.5">
                <span class="tudi-chip bg-tudi-dark-3 text-tudi-on-dark" x-text="`{{ __('Proteína') }} ${entero(proteinaG)} g`"></span>
                <span class="tudi-chip bg-tudi-dark-3 text-tudi-on-dark" x-text="`{{ __('Grasas') }} ${entero(grasaG)} g`"></span>
                <span class="tudi-chip bg-tudi-dark-3 text-tudi-on-dark" x-text="`{{ __('Carbohidratos') }} ${entero(carbohidratosG)} g`"></span>
            </div>

            {{--
                Igual que la calculadora de referencia: en vez de pedir factores
                de macros, se explica el rango que conviene y de dónde sale.
            --}}
            <div class="mt-4 border-t border-tudi-dark-3 pt-3 text-[13px] text-tudi-on-dark-2">
                <p>
                    {{ __('Proteína recomendada:') }}
                    <span class="text-tudi-on-dark" x-text="`${entero(proteinaMinG)} – ${entero(proteinaMaxG)} g`"></span>
                    {{ __('al día. Ayuda a conservar tu masa muscular mientras pierdes peso.') }}
                </p>
                <p class="mt-1.5">
                    {{ __('Grasa mínima:') }}
                    <span class="text-tudi-on-dark" x-text="`${entero(grasaMinG)} g`"></span>.
                    {{ __('El resto de tus calorías van a carbohidratos.') }}
                </p>
            </div>

            <p class="tudi-meta mt-3" x-show="tocado || vigente === null" style="display: none">
                {{ __('Vista previa · guarda para aplicarlo') }}
            </p>

            {{--
                ── Ayuda para el usuario (CLAUDE.md sección 5.15) ──
                Dos botones y nada más. La versión anterior incrustaba el video
                en una tarjeta propia que ensanchaba la pantalla y desplazaba
                los controles: el material es una ayuda, no puede reordenar la
                Calculadora. El video se abre en una capa sobre la página, así
                que verlo no hace perder lo que se estaba ajustando.
            --}}
            @if ($hayMaterial)
                <div class="mt-4 flex flex-wrap gap-2 border-t border-tudi-dark-3 pt-4"
                     x-data="{ video: false }" x-on:keydown.escape.window="video = false">
                    @if ($videoIncrustado)
                        <button type="button" x-on:click="video = true"
                                class="tudi-btn gap-2 bg-tudi-dark-3 text-tudi-on-dark">
                            <svg class="h-4 w-4 flex-none" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 8.5v7l6-3.5-6-3.5ZM4 6a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6Z" />
                            </svg>
                            {{ __('Ver el video') }}
                        </button>

                        <div x-show="video" style="display: none"
                             class="fixed inset-0 z-50 flex items-center justify-center bg-tudi-ink/80 p-4"
                             x-on:click.self="video = false" role="dialog" aria-modal="true">
                            <div class="w-full max-w-2xl">
                                <div class="aspect-video w-full overflow-hidden rounded-tudi-md bg-tudi-dark">
                                    {{-- x-if, no x-show: sin esto el iframe se
                                         carga (y YouTube empieza a contar) en
                                         cuanto abre la Calculadora. --}}
                                    <template x-if="video">
                                        <iframe src="{{ $videoIncrustado }}"
                                                title="{{ __('Video explicativo de la Calculadora Déficit') }}"
                                                class="h-full w-full"
                                                referrerpolicy="strict-origin-when-cross-origin"
                                                allow="accelerometer; clipboard-write; encrypted-media; picture-in-picture; web-share"
                                                allowfullscreen></iframe>
                                    </template>
                                </div>
                                <button type="button" x-on:click="video = false"
                                        class="tudi-btn tudi-btn-lime tudi-btn-block mt-3">
                                    {{ __('Cerrar') }}
                                </button>
                            </div>
                        </div>
                    @endif

                    @if ($guiaPdf)
                        {{-- target y rel: en móvil, "download" sobre un PDF
                             servido desde otro origen no siempre descarga, y
                             abrirlo en una pestaña siempre funciona. --}}
                        <a href="{{ $guiaPdf['url'] }}" download target="_blank" rel="noopener"
                           class="tudi-btn gap-2 bg-tudi-dark-3 text-tudi-on-dark no-underline">
                            <svg class="h-4 w-4 flex-none" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v10m0 0 4-4m-4 4-4-4M5 18h14" />
                            </svg>
                            {{ __('Guía en PDF') }}
                        </a>
                    @endif
                </div>
            @endif
        </div>


        {{-- ── Los controles ── --}}
        <div class="tudi-card mx-auto w-full max-w-2xl space-y-6 p-5">
            <div>
                <p class="mb-2 text-[13px] font-semibold">{{ __('Sexo biológico') }}</p>
                <div class="tudi-seg">
                    @foreach (['masculino' => __('Hombre'), 'femenino' => __('Mujer')] as $valor => $texto)
                        <button type="button" x-on:click="sexo = '{{ $valor }}'"
                                :aria-pressed="sexo === '{{ $valor }}' ? 'true' : 'false'">{{ $texto }}</button>
                    @endforeach
                </div>
                <input type="hidden" name="sexo" value="{{ $inicial['sexo'] }}" :value="sexo">
            </div>

            <div class="grid grid-cols-3 gap-2">
                <div>
                    <label for="peso_kg" class="mb-1.5 block text-[13px] font-semibold">{{ __('Peso (kg)') }}</label>
                    {{--
                        inputmode="decimal" sobre type="text", nunca type="number"
                        (sección 4.24): con type="number" el navegador devuelve
                        valor vacío mientras se escribe "70." y valida `step` por
                        su cuenta, así que no se podían teclear decimales desde
                        el móvil. La coma la normaliza ProfileParametersRequest.
                    --}}
                    <input id="peso_kg" name="peso_kg" type="text" inputmode="decimal" autocomplete="off" required
                           value="{{ $inicial['peso'] }}" x-model="peso" class="tudi-input text-center font-semibold">
                </div>
                <div>
                    <label for="estatura_m" class="mb-1.5 block text-[13px] font-semibold">{{ __('Estatura (m)') }}</label>
                    <input id="estatura_m" name="estatura_m" type="text" inputmode="decimal" autocomplete="off" required
                           value="{{ $inicial['estatura'] }}" x-model="estatura" class="tudi-input text-center font-semibold">
                </div>
                <div>
                    <label for="edad" class="mb-1.5 block text-[13px] font-semibold">{{ __('Edad') }}</label>
                    <input id="edad" name="edad" type="number" inputmode="numeric" step="1" min="1" max="255" required
                           value="{{ $inicial['edad'] }}" x-model="edad" class="tudi-input text-center font-semibold">
                </div>
            </div>

            {{-- ── ¿Qué tan activo eres? ── --}}
            <div>
                <div class="mb-2 flex items-baseline justify-between gap-3">
                    <p class="text-[13px] font-semibold">{{ __('¿Qué tan activo eres?') }}</p>
                    <span class="tudi-meta" x-text="factor(nivel)"></span>
                </div>

                <div class="space-y-1.5">
                    @foreach ($nivelesActividad as $nivel)
                        <button type="button" x-on:click="nivel = {{ $nivel['valor'] }}"
                                :aria-pressed="nivelActivo({{ $nivel['valor'] }}) ? 'true' : 'false'"
                                :class="nivelActivo({{ $nivel['valor'] }})
                                    ? 'bg-tudi-ink text-tudi-on-dark font-semibold'
                                    : 'bg-tudi-surface text-tudi-ink-2'"
                                class="flex min-h-[48px] w-full items-center gap-2.5 rounded-tudi-md px-4 text-start text-sm">
                            <span class="grid h-4 w-4 flex-none place-items-center rounded-full border"
                                  :class="nivelActivo({{ $nivel['valor'] }}) ? 'border-tudi-lime' : 'border-tudi-input-border'">
                                <span class="h-2 w-2 rounded-full bg-tudi-lime"
                                      x-show="nivelActivo({{ $nivel['valor'] }})" style="display: none"></span>
                            </span>
                            {{ $nivel['texto'] }}
                        </button>
                    @endforeach
                </div>

                {{--
                    La explicación del escalón elegido: es lo que más mueve el
                    resultado. Va renderizada desde el servidor (la del nivel
                    guardado) y Alpine solo la reemplaza al cambiar de escalón,
                    para que se lea también sin JavaScript.
                --}}
                <p class="mt-2.5 rounded-tudi-sm bg-tudi-card-inset p-3 text-[13px] leading-snug text-tudi-ink-3"
                   x-text="explicacionDelNivel">{{ $nivelElegido['explicacion'] }}</p>

                <input type="hidden" name="nivel_actividad" value="{{ $inicial['nivel'] }}" :value="nivel">
            </div>

            {{-- ── Tu objetivo ── --}}
            <div>
                <div class="mb-2 flex items-baseline justify-between gap-3">
                    <p class="text-[13px] font-semibold">{{ __('Tu objetivo') }}</p>
                    <span class="tudi-meta font-medium text-tudi-lime-700" x-text="etiquetaDeficit"></span>
                </div>

                <div class="space-y-1.5" x-show="tipo === 'porcentaje'">
                    @foreach ($objetivos as $objetivo)
                        <button type="button"
                                x-on:click="elegirObjetivo({{ $objetivo['valor'] }}, {{ $objetivo['proteina'] }})"
                                :aria-pressed="objetivoActivo({{ $objetivo['valor'] }}) ? 'true' : 'false'"
                                :class="objetivoActivo({{ $objetivo['valor'] }})
                                    ? 'bg-tudi-ink text-tudi-on-dark font-semibold'
                                    : 'bg-tudi-surface text-tudi-ink-2'"
                                class="flex min-h-[48px] w-full items-center gap-2.5 rounded-tudi-md px-4 text-start text-sm">
                            <span class="grid h-4 w-4 flex-none place-items-center rounded-full border"
                                  :class="objetivoActivo({{ $objetivo['valor'] }}) ? 'border-tudi-lime' : 'border-tudi-input-border'">
                                <span class="h-2 w-2 rounded-full bg-tudi-lime"
                                      x-show="objetivoActivo({{ $objetivo['valor'] }})" style="display: none"></span>
                            </span>
                            {{ $objetivo['texto'] }}
                        </button>
                    @endforeach
                </div>

                <p class="mt-2.5 rounded-tudi-sm bg-tudi-card-inset p-3 text-[13px] leading-snug text-tudi-ink-3"
                   x-show="tipo === 'porcentaje'"
                   x-text="explicacionDelObjetivo">{{ $objetivoElegido['explicacion'] }}</p>

                {{-- Déficit fijo: se elige en avanzado, y entonces manda sobre el objetivo. --}}
                <div x-show="tipo === 'fijo'" style="display: none">
                    <input type="range" min="100" max="1000" step="25" x-model.number="fijo"
                           :style="relleno(fijo, 100, 1000)"
                           aria-label="{{ __('Déficit fijo en kcal') }}" class="tudi-slider">
                    <p class="mt-2.5 rounded-tudi-sm bg-tudi-card-inset p-3 text-[13px] leading-snug text-tudi-ink-3">
                        {{ __('Estás restando una cantidad fija de calorías a tu mantenimiento, en vez de un porcentaje.') }}
                    </p>
                </div>

                <input type="hidden" name="tipo_deficit" value="{{ $inicial['tipo'] }}" :value="tipo">
                <input type="hidden" name="valor_deficit" value="{{ $inicial['tipo'] === 'porcentaje' ? number_format($inicial['porcentaje'] / 100, 2, '.', '') : $inicial['fijo'] }}" :value="tipo === 'porcentaje' ? (porcentaje / 100).toFixed(2) : fijo">
            </div>

            {{-- ── Ajustes avanzados ── --}}
            {{--
                Proteína y grasa salen del camino principal (sección 4.25): se
                derivan del objetivo elegido. Siguen siendo editables porque la
                sección 5 los necesita y hay perfiles que quieren afinarlos.
            --}}
            <details class="rounded-tudi-md bg-tudi-surface"
                     {{ $inicial['tipo'] === 'fijo' || $errors->any() ? 'open' : '' }}>
                <summary class="flex min-h-[48px] cursor-pointer list-none items-center justify-between px-4 text-[13px] font-semibold">
                    {{ __('Ajustes avanzados') }}
                    <span class="tudi-meta">{{ __('opcional') }}</span>
                </summary>

                <div class="space-y-4 px-4 pb-4">
                    <label class="flex min-h-[44px] cursor-pointer items-center justify-between gap-3">
                        <span class="text-[13px]">{{ __('Usar un déficit fijo en kcal') }}</span>
                        <input type="checkbox" class="peer sr-only"
                               :checked="tipo === 'fijo'"
                               x-on:change="tipo = $event.target.checked ? 'fijo' : 'porcentaje'">
                        <span class="tudi-switch"></span>
                    </label>

                    <div class="grid grid-cols-2 gap-2">
                        <div class="rounded-tudi-md bg-tudi-card p-4">
                            <label for="proteina_factor" class="tudi-label">{{ __('Proteína g/kg') }}</label>
                            <input id="proteina_factor" name="proteina_factor" type="text" inputmode="decimal"
                                   autocomplete="off" required value="{{ $inicial['proteina'] }}"
                                   x-model="proteina" x-on:input="macrosManuales = true"
                                   class="tudi-num mt-1 w-full border-0 bg-transparent p-0 text-[22px] focus:ring-0">
                            <p class="tudi-meta mt-1">{{ $inicial['proteinaMin'] }} – {{ $inicial['proteinaMax'] }}</p>
                        </div>
                        <div class="rounded-tudi-md bg-tudi-card p-4">
                            <label for="grasa_factor" class="tudi-label">{{ __('Grasa g/kg') }}</label>
                            <input id="grasa_factor" name="grasa_factor" type="text" inputmode="decimal"
                                   autocomplete="off" required value="{{ $inicial['grasa'] }}"
                                   x-model="grasa" x-on:input="macrosManuales = true"
                                   class="tudi-num mt-1 w-full border-0 bg-transparent p-0 text-[22px] focus:ring-0">
                            <p class="tudi-meta mt-1">{{ $inicial['grasaMin'] }} – {{ $inicial['grasaMax'] }}</p>
                        </div>
                    </div>

                    <p class="text-[13px] leading-snug text-tudi-ink-3">
                        {{ __('Si no los tocas, se ajustan solos al objetivo que elijas: cuanto más agresivo el déficit, más proteína para conservar músculo.') }}
                    </p>
                </div>
            </details>

            <p class="tudi-note" x-show="carbohidratosG < 0" style="display: none">
                {{ __('Con este déficit no quedan carbohidratos: baja el déficit o los factores de proteína y grasa.') }}
            </p>

            <button type="submit" class="tudi-btn tudi-btn-primary tudi-btn-block">
                {{ __('Guardar y recalcular') }}
            </button>
        </div>
    </form>

    @push('scripts')
        <script>
            // Espejo en JS de NutritionCalculatorService (CLAUDE.md sección 5),
            // solo para la vista previa: la cifra que se persiste la calcula
            // siempre PHP al enviar el formulario. Si la fórmula cambia, hay
            // que cambiar los dos sitios.
            function calculadoraDeficit(inicial) {
                var NIVELES = @json(collect($nivelesActividad)->map(fn ($n) => ['valor' => $n['valor'], 'explicacion' => $n['explicacion']])->values());
                var OBJETIVOS = @json(collect($objetivos)->map(fn ($o) => ['valor' => $o['valor'], 'explicacion' => $o['explicacion'], 'proteina' => $o['proteina']])->values());

                return {
                    peso: inicial.peso,
                    estatura: inicial.estatura,
                    edad: inicial.edad,
                    sexo: inicial.sexo,
                    nivel: inicial.nivel,
                    tipo: inicial.tipo,
                    porcentaje: inicial.porcentaje,
                    fijo: inicial.fijo,
                    proteina: inicial.proteina,
                    grasa: inicial.grasa,
                    vigente: inicial.vigente,
                    // El usuario editó los factores a mano: dejan de seguir al
                    // objetivo elegido.
                    macrosManuales: false,
                    tocado: false,

                    // Los campos decimales son de texto (sección 4.24), así que
                    // aquí se convierten aceptando también la coma decimal.
                    num(valor) {
                        var numero = parseFloat(String(valor ?? '').replace(',', '.'));

                        return isNaN(numero) ? 0 : numero;
                    },

                    get pesoKg() {
                        return this.num(this.peso);
                    },

                    get mantenimiento() {
                        return this.pesoKg * 22 * this.nivel;
                    },

                    get objetivoCalculado() {
                        return this.tipo === 'porcentaje'
                            ? this.mantenimiento * (1 - this.porcentaje / 100)
                            : this.mantenimiento - this.fijo;
                    },

                    // Mientras no se toque nada manda el objetivo vigente: una
                    // recomendación confirmada puede haberlo movido respecto de
                    // la fórmula (CLAUDE.md sección 4.10).
                    get objetivo() {
                        return this.tocado || this.vigente === null ? this.objetivoCalculado : this.vigente;
                    },

                    get proteinaG() {
                        return this.pesoKg * this.num(this.proteina);
                    },

                    get grasaG() {
                        return this.pesoKg * this.num(this.grasa);
                    },

                    get carbohidratosG() {
                        return (this.objetivo - (this.proteinaG * 4 + this.grasaG * 9)) / 4;
                    },

                    // Rango recomendado de proteína y mínimo de grasa: los de la
                    // sección 5, expresados en gramos para este peso.
                    get proteinaMinG() {
                        return this.pesoKg * inicial.proteinaMin;
                    },

                    get proteinaMaxG() {
                        return this.pesoKg * inicial.proteinaMax;
                    },

                    get grasaMinG() {
                        return this.pesoKg * inicial.grasaMin;
                    },

                    get etiquetaDeficit() {
                        var kcal = Math.round(this.mantenimiento - this.objetivoCalculado);

                        if (this.tipo === 'fijo') {
                            return '−' + this.entero(this.fijo) + ' kcal';
                        }

                        return this.porcentaje === 0
                            ? 'sin déficit'
                            : this.porcentaje + '% · −' + this.entero(kcal) + ' kcal';
                    },

                    get resumenDelDeficit() {
                        return 'Mantenimiento ' + this.entero(this.mantenimiento) + ' kcal · ' + this.etiquetaDeficit;
                    },

                    get explicacionDelNivel() {
                        var elegido = NIVELES.find((nivel) => this.nivelActivo(nivel.valor));

                        return elegido ? elegido.explicacion : '';
                    },

                    get explicacionDelObjetivo() {
                        var elegido = OBJETIVOS.find((objetivo) => this.objetivoActivo(objetivo.valor));

                        return elegido ? elegido.explicacion : '';
                    },

                    /**
                     * Elegir un objetivo arrastra el factor de proteína, salvo
                     * que el usuario lo haya fijado a mano en avanzado.
                     */
                    elegirObjetivo(porcentaje, proteina) {
                        this.tipo = 'porcentaje';
                        this.porcentaje = porcentaje;

                        if (! this.macrosManuales) {
                            this.proteina = String(proteina);
                        }
                    },

                    // Mismo formato que number_format($valor, 0, ',', '.') en PHP:
                    // Intl no agrupa los millares de una cifra de cuatro dígitos.
                    entero(valor) {
                        return String(Math.max(0, Math.round(valor || 0))).replace(/\B(?=(\d{3})+(?!\d))/g, '.');
                    },

                    factor(valor) {
                        return String(valor);
                    },

                    // El escalón más cercano al valor guardado: un perfil
                    // anterior puede tener un nivel que no coincida con ninguno,
                    // y marcar el más cercano no cambia lo que se persiste.
                    masCercano(opciones, valor) {
                        return opciones.reduce((a, b) => (Math.abs(b - valor) < Math.abs(a - valor) ? b : a));
                    },

                    nivelActivo(valor) {
                        return this.masCercano(NIVELES.map((nivel) => nivel.valor), this.nivel) === valor;
                    },

                    objetivoActivo(valor) {
                        return this.masCercano(OBJETIVOS.map((objetivo) => objetivo.valor), this.porcentaje) === valor;
                    },

                    relleno(valor, minimo, maximo) {
                        var pct = ((valor - minimo) / (maximo - minimo)) * 100;

                        return 'background: linear-gradient(to right, var(--tudi-lime) 0 ' + pct + '%, var(--tudi-surface) ' + pct + '% 100%)';
                    },
                };
            }
        </script>
    @endpush
</x-app-layout>
