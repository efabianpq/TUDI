@php
    // Valores iniciales de los controles. Un perfil recién creado no tiene
    // ninguno, así que los controles táctiles (segmentados y slider, que
    // siempre tienen una posición) arrancan en un punto medio razonable; hasta
    // que no se guarda, la cifra de arriba se muestra como vista previa.
    $tipoDeficit = old('tipo_deficit', $user->tipo_deficit) ?: 'porcentaje';
    $valorDeficit = (float) old('valor_deficit', $user->valor_deficit);

    $inicial = [
        'peso' => (float) (old('peso_kg', $user->peso_kg) ?: 70),
        'estatura' => (float) (old('estatura_m', $user->estatura_m) ?: 1.70),
        'edad' => (int) (old('edad', $user->edad) ?: 30),
        'sexo' => old('sexo', $user->sexo) ?: 'masculino',
        'nivel' => (float) (old('nivel_actividad', $user->nivel_actividad) ?: 1.55),
        'tipo' => $tipoDeficit,
        'porcentaje' => $tipoDeficit === 'porcentaje' && $valorDeficit > 0 ? round($valorDeficit * 100) : 15,
        'fijo' => $tipoDeficit === 'fijo' && $valorDeficit > 0 ? round($valorDeficit) : 500,
        'proteina' => (float) (old('proteina_factor', $user->proteina_factor) ?: 1.8),
        'grasa' => (float) (old('grasa_factor', $user->grasa_factor) ?: 0.8),
        'vigente' => $user->calorias_objetivo !== null ? (float) $user->calorias_objetivo : null,
    ];

    $nivelesActividad = [
        ['valor' => 1.2, 'texto' => __('Sedentario')],
        ['valor' => 1.375, 'texto' => __('Ligero')],
        ['valor' => 1.55, 'texto' => __('Activo')],
        ['valor' => 1.725, 'texto' => __('Intenso')],
    ];
@endphp

<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between gap-3">
            <h1 class="text-lg font-semibold tracking-tudi-title sm:text-2xl">{{ __('Calculadora') }}</h1>
            <x-tudi.avatar-menu />
        </div>
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

            <div class="mt-3.5 flex flex-wrap gap-1.5">
                <span class="tudi-chip bg-tudi-dark-3 text-tudi-on-dark" x-text="`P ${entero(proteinaG)} g`"></span>
                <span class="tudi-chip bg-tudi-dark-3 text-tudi-on-dark" x-text="`G ${entero(grasaG)} g`"></span>
                <span class="tudi-chip bg-tudi-dark-3 text-tudi-on-dark" x-text="`C ${entero(carbohidratosG)} g`"></span>
            </div>

            <p class="tudi-meta mt-3" x-show="tocado || vigente === null" style="display: none">
                {{ __('Vista previa · guarda para aplicarlo') }}
            </p>
        </div>

        {{-- ── Los controles ── --}}
        <div class="tudi-card space-y-5 p-5">
            <div>
                <p class="mb-2 text-[13px] font-semibold">{{ __('Sexo') }}</p>
                <div class="tudi-seg">
                    @foreach (['masculino' => __('Masculino'), 'femenino' => __('Femenino')] as $valor => $texto)
                        <button type="button" x-on:click="sexo = '{{ $valor }}'"
                                :aria-pressed="sexo === '{{ $valor }}' ? 'true' : 'false'">{{ $texto }}</button>
                    @endforeach
                </div>
                <input type="hidden" name="sexo" value="{{ $inicial['sexo'] }}" :value="sexo">
            </div>

            <div class="grid grid-cols-3 gap-2">
                <div>
                    <label for="peso_kg" class="mb-1.5 block text-[13px] font-semibold">{{ __('Peso') }}</label>
                    <input id="peso_kg" name="peso_kg" type="number" inputmode="decimal" step="0.1" min="1" max="999.99" required
                           value="{{ $inicial['peso'] }}" x-model.number="peso" class="tudi-input text-center font-semibold">
                </div>
                <div>
                    <label for="estatura_m" class="mb-1.5 block text-[13px] font-semibold">{{ __('Estatura') }}</label>
                    <input id="estatura_m" name="estatura_m" type="number" inputmode="decimal" step="0.01" min="0.5" max="2.99" required
                           value="{{ $inicial['estatura'] }}" x-model.number="estatura" class="tudi-input text-center font-semibold">
                </div>
                <div>
                    <label for="edad" class="mb-1.5 block text-[13px] font-semibold">{{ __('Edad') }}</label>
                    <input id="edad" name="edad" type="number" inputmode="numeric" step="1" min="1" max="255" required
                           value="{{ $inicial['edad'] }}" x-model.number="edad" class="tudi-input text-center font-semibold">
                </div>
            </div>

            <div>
                <div class="mb-2 flex items-center justify-between">
                    <p class="text-[13px] font-semibold">{{ __('Nivel de actividad') }}</p>
                    <span class="tudi-meta" x-text="nivel.toFixed(3).replace(/0+$/, '').replace(/\.$/, '')"></span>
                </div>
                <div class="flex gap-1.5">
                    @foreach ($nivelesActividad as $nivel)
                        <button type="button" x-on:click="nivel = {{ $nivel['valor'] }}"
                                :class="nivelActivo({{ $nivel['valor'] }}) ? 'bg-tudi-ink text-tudi-on-dark font-semibold' : 'bg-tudi-surface text-tudi-ink-2'"
                                :aria-pressed="nivelActivo({{ $nivel['valor'] }}) ? 'true' : 'false'"
                                class="min-h-[44px] flex-1 rounded-tudi-pill px-1 text-xs">{{ $nivel['texto'] }}</button>
                    @endforeach
                </div>
                <input type="hidden" name="nivel_actividad" value="{{ $inicial['nivel'] }}" :value="nivel">
            </div>

            <div>
                <div class="mb-2 flex items-center justify-between">
                    <p class="text-[13px] font-semibold">{{ __('Déficit') }}</p>
                    <span class="tudi-meta font-medium text-tudi-lime-700" x-text="etiquetaDeficit"></span>
                </div>

                <div class="tudi-seg mb-4">
                    @foreach (['porcentaje' => __('Porcentaje'), 'fijo' => __('Fijo')] as $valor => $texto)
                        <button type="button" x-on:click="tipo = '{{ $valor }}'"
                                :aria-pressed="tipo === '{{ $valor }}' ? 'true' : 'false'">{{ $texto }}</button>
                    @endforeach
                </div>

                <input type="range" min="5" max="30" step="1" x-model.number="porcentaje"
                       x-show="tipo === 'porcentaje'" :style="relleno(porcentaje, 5, 30)"
                       aria-label="{{ __('Porcentaje de déficit') }}" class="tudi-slider">
                <input type="range" min="100" max="1000" step="25" x-model.number="fijo"
                       x-show="tipo === 'fijo'" style="display: none" :style="relleno(fijo, 100, 1000)"
                       aria-label="{{ __('Déficit fijo en kcal') }}" class="tudi-slider">

                <input type="hidden" name="tipo_deficit" value="{{ $inicial['tipo'] }}" :value="tipo">
                <input type="hidden" name="valor_deficit" value="{{ $inicial['tipo'] === 'porcentaje' ? number_format($inicial['porcentaje'] / 100, 2, '.', '') : $inicial['fijo'] }}" :value="tipo === 'porcentaje' ? (porcentaje / 100).toFixed(2) : fijo">
            </div>

            <div class="grid grid-cols-2 gap-2">
                <div class="rounded-tudi-md bg-tudi-surface p-4">
                    <label for="proteina_factor" class="tudi-label">{{ __('Proteína g/kg') }}</label>
                    <input id="proteina_factor" name="proteina_factor" type="number" inputmode="decimal"
                           step="0.1" min="1.6" max="2.2" required value="{{ $inicial['proteina'] }}" x-model.number="proteina"
                           class="tudi-num mt-1 w-full border-0 bg-transparent p-0 text-[22px] focus:ring-0">
                </div>
                <div class="rounded-tudi-md bg-tudi-surface p-4">
                    <label for="grasa_factor" class="tudi-label">{{ __('Grasa g/kg') }}</label>
                    <input id="grasa_factor" name="grasa_factor" type="number" inputmode="decimal"
                           step="0.1" min="0.6" max="1" required value="{{ $inicial['grasa'] }}" x-model.number="grasa"
                           class="tudi-num mt-1 w-full border-0 bg-transparent p-0 text-[22px] focus:ring-0">
                </div>
            </div>

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
            // siempre PHP al enviar el formulario.
            function calculadoraDeficit(inicial) {
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
                    tocado: false,

                    get mantenimiento() {
                        return (this.peso || 0) * 22 * this.nivel;
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
                        return (this.peso || 0) * this.proteina;
                    },

                    get grasaG() {
                        return (this.peso || 0) * this.grasa;
                    },

                    get carbohidratosG() {
                        return (this.objetivo - (this.proteinaG * 4 + this.grasaG * 9)) / 4;
                    },

                    get etiquetaDeficit() {
                        var kcal = Math.round(this.mantenimiento - this.objetivoCalculado);

                        return this.tipo === 'porcentaje'
                            ? this.porcentaje + '% · −' + this.entero(kcal) + ' kcal'
                            : '−' + this.entero(this.fijo) + ' kcal';
                    },

                    // Mismo formato que number_format($valor, 0, ',', '.') en PHP:
                    // Intl no agrupa los millares de una cifra de cuatro dígitos.
                    entero(valor) {
                        return String(Math.max(0, Math.round(valor || 0))).replace(/\B(?=(\d{3})+(?!\d))/g, '.');
                    },

                    // El escalón más cercano al nivel guardado.
                    nivelActivo(valor) {
                        var opciones = [1.2, 1.375, 1.55, 1.725];
                        var cercano = opciones.reduce(function (a, b) {
                            return Math.abs(b - this.nivel) < Math.abs(a - this.nivel) ? b : a;
                        }.bind(this));

                        return cercano === valor;
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
