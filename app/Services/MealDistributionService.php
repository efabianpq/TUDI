<?php

namespace App\Services;

use App\Exceptions\MealDistributionUnavailableException;
use App\Models\PlanComida;
use App\Models\RegistroDiario;
use App\Models\User;
use App\Services\AI\MealDistributionProviderInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Convierte el texto libre de ingredientes que el usuario escribe (o dicta) en
 * el plan diario en PlanComida persistidos, delegando la parte de
 * interpretación en un MealDistributionProviderInterface (CLAUDE.md sección
 * 4.12).
 *
 * ── Un solo "Generar distribución" para las tres comidas ────────────────────
 *
 * El reparto del día es un único problema: cuánto de las calorías objetivo va a
 * cada comida depende de lo que haya en las otras. Por eso hay un único botón y
 * una única llamada al proveedor, con las comidas que toque resolver:
 *
 *  - **fijas** — las que ya tienen una distribución generada cuyo texto no ha
 *    cambiado, y las que ya tienen ComidaReal registrada. No se tocan: su
 *    presupuesto ya está gastado y se descuenta del día (así, si por la tarde
 *    se escribe la cena, el desayuno y el almuerzo de la mañana quedan intactos).
 *  - **a generar** — las que tienen texto y todavía no tienen plan, o cuyo
 *    texto cambió respecto del que produjo el plan anterior.
 *  - **reservadas** — las que aún no tienen texto. No se resuelven, pero su
 *    parte del objetivo diario se aparta igualmente, para que las comidas de
 *    ahora no se coman las calorías de la cena que todavía no se ha escrito.
 *
 * No reimplementa ninguna fórmula: los objetivos de calorías y macros salen de
 * NutritionCalculatorService (sección 7) y cuánto le toca a cada comida lo
 * resuelve RepartoComidasService (sección 5.14), que sabe si ese día lleva un
 * reparto propio, el habitual del usuario o el 25/40/35 de fábrica. Los
 * presupuestos por comida y los totales de cada plan se calculan en PHP, nunca
 * los devuelve el modelo (regla 7 de la sección 13).
 */
class MealDistributionService
{
    /**
     * Campos del perfil sin los cuales no hay objetivo calórico que repartir.
     */
    /**
     * Diferencia en kcal entre lo comido y lo planificado por debajo de la cual
     * el saldo del día se considera intacto. Solo absorbe el ruido de redondeo:
     * cualquier desviación real tiene que llegar al reparto de lo que falta.
     */
    public const TOLERANCIA_SALDO_KCAL = 1.0;

    public const PARAMETROS_REQUERIDOS = [
        'peso_kg',
        'nivel_actividad',
        'tipo_deficit',
        'valor_deficit',
        'proteina_factor',
        'grasa_factor',
    ];

    public function __construct(
        private readonly NutritionCalculatorService $calculadora,
        private readonly MealDistributionProviderInterface $proveedor,
        private readonly RepartoComidasService $reparto,
        private readonly ObjetivoDelDiaService $objetivoDelDia,
    ) {}

    /**
     * ¿Tiene el usuario los parámetros que hacen falta para calcular su plan?
     */
    public function perfilCompleto(User $usuario): bool
    {
        foreach (self::PARAMETROS_REQUERIDOS as $parametro) {
            if ($usuario->{$parametro} === null) {
                return false;
            }
        }

        return true;
    }

    /**
     * Objetivos de un día concreto: los del usuario, repartidos con el reparto
     * vigente de ESE día (sección 5.14).
     *
     * Es la variante que usa todo lo que trabaja sobre un RegistroDiario; la de
     * abajo queda para cuando solo hay usuario y todavía no hay día.
     *
     * @return array{dia: array{calorias_objetivo: float, proteina_g: float, grasa_g: float, carbohidratos_g: float}, por_comida: array<string, array{calorias: float, proteina_g: float, grasa_g: float, carbohidratos_g: float}>, reparto: array<string, float>}
     */
    public function objetivosDelRegistro(RegistroDiario $registroDiario): array
    {
        // El objetivo SELLADO de ese día, no el que dicte hoy el perfil
        // (sección 5.25): cambiar la Calculadora no puede reescribir hacia
        // atrás los objetivos de días que ya se vivieron.
        return $this->repartir(
            $this->objetivoDelDia->vigente($registroDiario),
            $this->reparto->paraElDia($registroDiario),
        );
    }

    /**
     * El saldo del día, macro a macro: cuánto queda del objetivo después de lo
     * que ya se reportó como comido (CLAUDE.md sección 5.21).
     *
     * Es la cifra que guía "Ajustar mi plan" y la que la pantalla enseña en el
     * panel de objetivo ("te quedan 40 g de proteína"). La calcula PHP a partir
     * de las ComidaReal del día, nunca el modelo (regla 7 de la sección 13), y
     * no persiste nada: es una lectura del estado actual.
     *
     * @return array{objetivo: array<string, float>, consumido: array<string, float>, saldo: array<string, float>, comidas_pendientes: array<int, string>, agotado: bool}
     */
    public function saldoDelDia(RegistroDiario $registroDiario): array
    {
        $objetivos = $this->objetivosDelRegistro($registroDiario);

        $objetivo = [
            'calorias' => $objetivos['dia']['calorias_objetivo'],
            'proteina_g' => $objetivos['dia']['proteina_g'],
            'grasa_g' => $objetivos['dia']['grasa_g'],
            'carbohidratos_g' => $objetivos['dia']['carbohidratos_g'],
        ];

        $planes = $registroDiario->planesComida()->with('comidaReal')->get()->keyBy('tipo_comida');

        $consumido = ['calorias' => 0.0, 'proteina_g' => 0.0, 'grasa_g' => 0.0, 'carbohidratos_g' => 0.0];
        $pendientes = [];

        foreach (array_keys(MealPlanGeneratorService::DISTRIBUCION_COMIDAS) as $tipoComida) {
            $real = $planes->get($tipoComida)?->comidaReal;

            if ($real === null) {
                $pendientes[] = $tipoComida;

                continue;
            }

            $consumido['calorias'] += (float) $real->calorias_reales;
            $consumido['proteina_g'] += (float) $real->proteina_g;
            $consumido['grasa_g'] += (float) $real->grasa_g;
            $consumido['carbohidratos_g'] += (float) $real->carbohidratos_g;
        }

        $saldo = [];

        foreach ($objetivo as $macro => $valor) {
            $saldo[$macro] = $valor - $consumido[$macro];
        }

        return [
            'objetivo' => $objetivo,
            'consumido' => $consumido,
            'saldo' => $saldo,
            'comidas_pendientes' => $pendientes,
            'agotado' => $saldo['calorias'] <= 0,
        ];
    }

    /**
     * Objetivos del día y su reparto nominal por comida, a partir del objetivo
     * calórico vigente del usuario (users.calorias_objetivo — sección 4.10).
     *
     * @param  array<string, float>|null  $reparto  proporción por comida; null usa el reparto balanceado de partida
     * @return array{dia: array{calorias_objetivo: float, proteina_g: float, grasa_g: float, carbohidratos_g: float}, por_comida: array<string, array{calorias: float, proteina_g: float, grasa_g: float, carbohidratos_g: float}>, reparto: array<string, float>}
     */
    public function objetivosDelDia(User $usuario, ?array $reparto = null): array
    {
        return $this->repartir($this->objetivoDelDia->calcularDelUsuario($usuario), $reparto);
    }

    /**
     * Parte un objetivo diario entre las comidas según el reparto vigente.
     *
     * @param  array{calorias_objetivo: float, proteina_g: float, grasa_g: float, carbohidratos_g: float}  $dia
     * @param  array<string, float>|null  $reparto  proporción por comida; null usa el reparto balanceado de partida
     * @return array{dia: array{calorias_objetivo: float, proteina_g: float, grasa_g: float, carbohidratos_g: float}, por_comida: array<string, array{calorias: float, proteina_g: float, grasa_g: float, carbohidratos_g: float}>, reparto: array<string, float>}
     */
    private function repartir(array $dia, ?array $reparto = null): array
    {
        $reparto ??= $this->reparto->balanceado();

        $porComida = [];

        foreach ($reparto as $tipoComida => $porcentaje) {
            $porComida[$tipoComida] = [
                'calorias' => $dia['calorias_objetivo'] * $porcentaje,
                'proteina_g' => $dia['proteina_g'] * $porcentaje,
                'grasa_g' => $dia['grasa_g'] * $porcentaje,
                'carbohidratos_g' => $dia['carbohidratos_g'] * $porcentaje,
            ];
        }

        return ['dia' => $dia, 'por_comida' => $porComida, 'reparto' => $reparto];
    }

    /**
     * Guarda (o corrige) el texto de ingredientes de una comida del día.
     *
     * Se permite sobre un día ya cerrado: el texto no alimenta ninguna cifra
     * del cierre, igual que el pesaje diario (sección 4.11).
     */
    public function guardarIngredientes(RegistroDiario $registroDiario, string $tipoComida, ?string $texto): void
    {
        $registroDiario->update([
            $this->columnaDeIngredientes($tipoComida) => $this->normalizarTexto($texto),
        ]);
    }

    /**
     * "Ajustar mi plan": guarda los textos de las comidas que llegan y pide al
     * proveedor, en una sola llamada, el reparto de las que haya que resolver.
     *
     * El nombre de cara al usuario es ese y no "Generar distribución" porque lo
     * que hace, en cuanto hay comidas cerradas, es exactamente ajustar: mide
     * cuánto queda del objetivo del día después de lo ya comido de verdad y
     * reparte ESE saldo entre las comidas que faltan. Las cerradas no se tocan
     * (sección 5.5).
     *
     * Los textos se guardan siempre, aunque la generación falle después: no se
     * pierde lo que el usuario escribió o dictó.
     *
     * @param  array<string, string|null>  $textos  texto por tipo de comida; las claves ausentes conservan lo que ya hubiera guardado
     * @param  string|null  $rehacer  tipo de comida a regenerar aunque su texto no haya cambiado
     * @return Collection<int, PlanComida> los planes generados en esta llamada
     *
     * @throws MealDistributionUnavailableException cuando no hay nada que distribuir o el proveedor falla
     */
    public function distribuirDia(RegistroDiario $registroDiario, array $textos, ?string $rehacer = null): Collection
    {
        $estado = $this->guardarYClasificar($registroDiario, $textos, $rehacer);

        if ($estado['a_generar'] === []) {
            throw MealDistributionUnavailableException::nadaQueDistribuir();
        }

        $presupuestos = $this->presupuestos(
            $this->objetivosDelRegistro($registroDiario),
            $estado,
            $this->reparto->explicacion($registroDiario)['comida'],
        );

        $distribuciones = $this->proveedor->distribuirDia(
            $presupuestos['comidas'],
            $presupuestos['contexto_dia'],
        );

        return DB::transaction(function () use ($registroDiario, $estado, $distribuciones): Collection {
            $generados = collect();

            foreach ($distribuciones as $tipoComida => $distribucion) {
                $estado['planes'][$tipoComida]?->delete();

                $generados->push($registroDiario->planesComida()->create(
                    $this->atributosDelPlan($tipoComida, $distribucion),
                ));
            }

            return $generados;
        });
    }

    /**
     * Persiste los textos que llegan y clasifica las tres comidas del día en
     * fijas / a generar / reservadas.
     *
     * @param  array<string, string|null>  $textos
     * @return array{planes: array<string, PlanComida|null>, fijas: array<string, array{descripcion: string, calorias: float, proteina_g: float, grasa_g: float, carbohidratos_g: float}>, a_generar: array<string, string>, reservadas: array<int, string>}
     */
    private function guardarYClasificar(RegistroDiario $registroDiario, array $textos, ?string $rehacer): array
    {
        $planes = $registroDiario->planesComida()->with('comidaReal')->get()->keyBy('tipo_comida');

        // ¿Se movió el saldo del día desde que se hicieron los planes que
        // siguen abiertos? Si alguna comida cerrada se comió por encima (o por
        // debajo) de lo que se le había planificado, el presupuesto con el que
        // se generaron las que faltan ya no vale, y "Ajustar mi plan" tiene que
        // rehacerlas aunque su texto no haya cambiado — es justamente para eso
        // que existe el botón (sección 5.3).
        $saldoDesplazado = $this->saldoDesplazado($planes);

        $estado = ['planes' => [], 'fijas' => [], 'a_generar' => [], 'reservadas' => []];

        foreach (array_keys(MealPlanGeneratorService::DISTRIBUCION_COMIDAS) as $tipoComida) {
            $plan = $planes->get($tipoComida);
            $estado['planes'][$tipoComida] = $plan;

            $textoAnterior = $registroDiario->{$this->columnaDeIngredientes($tipoComida)};

            // Una comida ya cerrada no se regenera ni se le reescribe el texto:
            // se preserva el historial "planificado vs. ejecutado" (sección 4) y
            // su presupuesto ya está gastado (sección 5.5). Se sale antes de
            // guardar nada para que ni siquiera un formulario manipulado a mano
            // pueda cambiarle el texto con el que se generó.
            if ($plan?->comidaReal !== null) {
                $estado['fijas'][$tipoComida] = $this->fijaDesdePlan($plan, $plan->comidaReal);

                continue;
            }

            $texto = array_key_exists($tipoComida, $textos)
                ? $this->normalizarTexto($textos[$tipoComida])
                : $textoAnterior;

            if ($texto !== $textoAnterior) {
                $this->guardarIngredientes($registroDiario, $tipoComida, $texto);
            }

            $hayQueGenerar = filled($texto)
                && ($plan === null || $texto !== $textoAnterior || $rehacer === $tipoComida || $saldoDesplazado);

            if ($hayQueGenerar) {
                $estado['a_generar'][$tipoComida] = (string) $texto;

                continue;
            }

            if ($plan !== null) {
                $estado['fijas'][$tipoComida] = $this->fijaDesdePlan($plan);

                continue;
            }

            $estado['reservadas'][] = $tipoComida;
        }

        return $estado;
    }

    /**
     * ¿Alguna comida cerrada se comió por una cifra distinta de la que se le
     * había planificado?
     *
     * Es la señal de que el saldo del día se movió después de generar los
     * planes que siguen abiertos: esos se calcularon contra un presupuesto que
     * ya no es el que queda. Una comida cerrada con "cumplí lo sugerido" no la
     * dispara —lo real y lo planificado coinciden—, así que pulsar el botón sin
     * que haya pasado nada sigue sin gastar una llamada.
     *
     * @param  Collection<string, PlanComida>  $planes
     */
    private function saldoDesplazado($planes): bool
    {
        foreach ($planes as $plan) {
            $real = $plan->comidaReal;

            if ($real === null) {
                continue;
            }

            $desviacion = abs((float) $real->calorias_reales - (float) $plan->calorias_estimadas);

            if ($desviacion > self::TOLERANCIA_SALDO_KCAL) {
                return true;
            }
        }

        return false;
    }

    /**
     * Reparte lo que queda del objetivo diario entre las comidas a generar,
     * después de descontar lo ya fijado y lo reservado para las comidas que el
     * usuario todavía no ha escrito.
     *
     * @param  array{dia: array<string, float>, por_comida: array<string, array<string, float>>, reparto: array<string, float>}  $objetivos
     * @param  array{planes: array<string, PlanComida|null>, fijas: array<string, array<string, mixed>>, a_generar: array<string, string>, reservadas: array<int, string>}  $estado
     * @return array{comidas: array<string, array{texto: string, objetivos: array{calorias: float, proteina_g: float, grasa_g: float, carbohidratos_g: float}}>, contexto_dia: array<string, mixed>}
     */
    private function presupuestos(array $objetivos, array $estado, ?string $comidaPostActividad = null): array
    {
        $macros = ['calorias', 'proteina_g', 'grasa_g', 'carbohidratos_g'];

        $totalDia = [
            'calorias' => $objetivos['dia']['calorias_objetivo'],
            'proteina_g' => $objetivos['dia']['proteina_g'],
            'grasa_g' => $objetivos['dia']['grasa_g'],
            'carbohidratos_g' => $objetivos['dia']['carbohidratos_g'],
        ];

        $reservadas = [];

        foreach ($estado['reservadas'] as $tipoComida) {
            $reservadas[$tipoComida] = $objetivos['por_comida'][$tipoComida];
        }

        // Lo que queda disponible para las comidas a generar, macro a macro.
        $disponible = [];

        foreach ($macros as $macro) {
            $gastado = array_sum(array_column($estado['fijas'], $macro))
                + array_sum(array_column($reservadas, $macro));

            $disponible[$macro] = max(0.0, $totalDia[$macro] - $gastado);
        }

        // Se reparte proporcionalmente al peso que cada comida a generar tiene
        // dentro del reparto vigente del día (sección 5.14), no a partes
        // iguales: si el almuerzo pesa más que el desayuno, debe seguir pesando
        // más sobre lo que queda.
        $reparto = $objetivos['reparto'];

        $pesoTotal = array_sum(array_map(
            fn (string $tipoComida): float => $reparto[$tipoComida],
            array_keys($estado['a_generar']),
        ));

        $comidas = [];

        foreach ($estado['a_generar'] as $tipoComida => $texto) {
            $participacion = $pesoTotal > 0
                ? $reparto[$tipoComida] / $pesoTotal
                : 0.0;

            $comidas[$tipoComida] = [
                'texto' => $texto,
                'objetivos' => array_combine($macros, array_map(
                    fn (string $macro): float => round($disponible[$macro] * $participacion, 2),
                    $macros,
                )),
            ];
        }

        return [
            'comidas' => $comidas,
            'contexto_dia' => [
                'calorias_objetivo_dia' => $totalDia['calorias'],
                'proteina_objetivo_dia_g' => $totalDia['proteina_g'],
                'grasa_objetivo_dia_g' => $totalDia['grasa_g'],
                'carbohidratos_objetivo_dia_g' => $totalDia['carbohidratos_g'],
                'reparto' => $reparto,
                'comidas_fijas' => $estado['fijas'],
                'comidas_reservadas' => $reservadas,
                // La comida posterior al entrenamiento (sección 5.14). Va como
                // contexto y no como un objetivo de macros distinto: cambiar
                // solo los carbohidratos de una comida rompería la coherencia
                // entre sus macros y sus calorías. Lo que se le pide al modelo
                // es que, DENTRO de esa comida, prefiera los carbohidratos.
                'comida_post_actividad' => $comidaPostActividad,
                // Cuánto del objetivo del día ya está comido de verdad: es lo
                // que hace que "Ajustar mi plan" reparta sobre el saldo real y
                // no sobre el objetivo entero (sección 5.3).
                'calorias_ya_comidas' => array_sum(array_column($estado['fijas'], 'calorias')),
            ],
        ];
    }

    /**
     * Una comida que no se toca, con las cifras que gastan su presupuesto: las
     * reales si ya se registró lo que se comió, las del plan si no.
     *
     * @return array{descripcion: string, calorias: float, proteina_g: float, grasa_g: float, carbohidratos_g: float}
     */
    private function fijaDesdePlan(PlanComida $plan, mixed $comidaReal = null): array
    {
        return [
            'descripcion' => (string) ($plan->descripcion ?? ''),
            'calorias' => (float) ($comidaReal?->calorias_reales ?? $plan->calorias_estimadas),
            'proteina_g' => (float) ($comidaReal?->proteina_g ?? $plan->proteina_g),
            'grasa_g' => (float) ($comidaReal?->grasa_g ?? $plan->grasa_g),
            'carbohidratos_g' => (float) ($comidaReal?->carbohidratos_g ?? $plan->carbohidratos_g),
        ];
    }

    /**
     * Totales de la comida sumados en PHP a partir de los ingredientes.
     *
     * A propósito no se le piden los totales al modelo: estima bien los macros
     * de un alimento, pero no es una calculadora, y estas cifras entran
     * directamente en el balance energético del cierre diario.
     *
     * @param  array{descripcion: string, preparacion: string, notas: string, ingredientes: array<int, array<string, mixed>>}  $distribucion
     * @return array<string, mixed>
     */
    private function atributosDelPlan(string $tipoComida, array $distribucion): array
    {
        $sumar = fn (string $campo): float => (float) array_sum(array_column($distribucion['ingredientes'], $campo));

        return [
            'tipo_comida' => $tipoComida,
            'origen' => PlanComida::ORIGEN_PLAN,
            'descripcion' => $distribucion['descripcion'] !== ''
                ? $distribucion['descripcion']
                : ucfirst($tipoComida),
            'preparacion' => $distribucion['preparacion'] !== '' ? $distribucion['preparacion'] : null,
            'notas_ia' => $distribucion['notas'] !== '' ? $distribucion['notas'] : null,
            'ingredientes_detalle' => $distribucion['ingredientes'],
            'calorias_estimadas' => round($sumar('calorias'), 2),
            'proteina_g' => round($sumar('proteina_g'), 2),
            'grasa_g' => round($sumar('grasa_g'), 2),
            'carbohidratos_g' => round($sumar('carbohidratos_g'), 2),
        ];
    }

    private function normalizarTexto(?string $texto): ?string
    {
        return $texto !== null && trim($texto) !== '' ? trim($texto) : null;
    }

    private function columnaDeIngredientes(string $tipoComida): string
    {
        if (! array_key_exists($tipoComida, MealPlanGeneratorService::DISTRIBUCION_COMIDAS)) {
            throw new InvalidArgumentException("Tipo de comida desconocido: {$tipoComida}");
        }

        return 'ingredientes_'.$tipoComida;
    }
}
