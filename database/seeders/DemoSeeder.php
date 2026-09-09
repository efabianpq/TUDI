<?php

namespace Database\Seeders;

use App\Models\ActividadFisica;
use App\Models\ComidaReal;
use App\Models\PlanComida;
use App\Models\RecomendacionSistema;
use App\Models\RegistroDiario;
use App\Models\User;
use App\Services\DailyClosureService;
use App\Services\NutritionCalculatorService;
use App\Services\TrendAnalyticsService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;

/**
 * Datos de demostración (CLAUDE.md sección 5.17).
 *
 * Pinta una plataforma "en marcha" para poder enseñarla sin depender de que
 * alguien lleve tres semanas registrando comidas: seis cuentas, cada una
 * parada en un punto distinto del recorrido, con el historial necesario para
 * que la analítica y el motor de recomendaciones tengan de verdad algo que
 * decir.
 *
 * ── Cómo se construye el historial ─────────────────────────────────────────
 *
 * No se inventan cifras de cierre: se crean los PlanComida, las ComidaReal y
 * las ActividadFisica de cada día y se llama a `DailyClosureService::cerrar()`,
 * el mismo que usa el usuario real. Así el déficit, el cumplimiento de proteína
 * y las recomendaciones salen de la lógica de dominio y no de una constante
 * escrita a mano, y lo que se enseña en la demo es lo que hace la aplicación.
 *
 * **No llama al proveedor de IA.** Los PlanComida se escriben directamente con
 * un catálogo de comidas de ejemplo: sembrar tres semanas por seis usuarios
 * costaría cientos de llamadas facturables (sección 5.13).
 *
 * ── Idempotente ────────────────────────────────────────────────────────────
 *
 * Se puede correr varias veces: cada cuenta se busca por correo y su historial
 * se borra antes de rehacerlo. Nunca toca cuentas que no sean las suyas, así
 * que es seguro sobre una base con usuarios reales.
 */
class DemoSeeder extends Seeder
{
    /** Contraseña común de todas las cuentas de demostración. */
    public const CLAVE = 'demo1234';

    /** Sufijo de correo que identifica a una cuenta de demostración. */
    public const DOMINIO = '@demo.tudeficitinteligente.online';

    /**
     * Días de historial. Tres semanas es el mínimo para que la detección de
     * estancamiento (tres variaciones semanales seguidas) tenga datos.
     */
    private const DIAS_HISTORIAL = 21;

    /**
     * Comidas de ejemplo por tipo, con los macros que llevaría cada una. Están
     * aquí y no en la base de datos porque son atrezo de la demo, no dominio.
     *
     * @var array<string, array<int, array{descripcion: string, preparacion: string, ingredientes: array<int, array<string, mixed>>}>>
     */
    private const CATALOGO = [
        'desayuno' => [
            [
                'descripcion' => 'Huevos revueltos con arepa y queso',
                'preparacion' => 'Revuelve los huevos a fuego bajo y asa la arepa hasta dorarla.',
                'ingredientes' => [
                    ['nombre' => 'Huevo', 'porcion' => '2 unidades', 'cantidad_g' => 100, 'calorias' => 143, 'proteina_g' => 12.6, 'grasa_g' => 9.5, 'carbohidratos_g' => 0.7],
                    ['nombre' => 'Arepa de maíz', 'porcion' => '1 unidad', 'cantidad_g' => 80, 'calorias' => 176, 'proteina_g' => 3.8, 'grasa_g' => 2.1, 'carbohidratos_g' => 36.0],
                    ['nombre' => 'Queso campesino', 'porcion' => '1 tajada', 'cantidad_g' => 40, 'calorias' => 104, 'proteina_g' => 7.2, 'grasa_g' => 8.0, 'carbohidratos_g' => 0.9],
                    ['nombre' => 'Café negro', 'porcion' => '1 taza', 'cantidad_g' => 240, 'calorias' => 5, 'proteina_g' => 0.3, 'grasa_g' => 0.0, 'carbohidratos_g' => 0.8],
                ],
            ],
            [
                'descripcion' => 'Avena con banano y maní',
                'preparacion' => 'Cocina la avena en agua y añade el banano en rodajas al final.',
                'ingredientes' => [
                    ['nombre' => 'Avena en hojuelas', 'porcion' => 'media taza', 'cantidad_g' => 50, 'calorias' => 190, 'proteina_g' => 6.6, 'grasa_g' => 3.4, 'carbohidratos_g' => 32.5],
                    ['nombre' => 'Banano', 'porcion' => '1 unidad', 'cantidad_g' => 120, 'calorias' => 107, 'proteina_g' => 1.3, 'grasa_g' => 0.4, 'carbohidratos_g' => 27.4],
                    ['nombre' => 'Maní natural', 'porcion' => '1 puñado', 'cantidad_g' => 25, 'calorias' => 143, 'proteina_g' => 6.5, 'grasa_g' => 12.4, 'carbohidratos_g' => 4.0],
                    ['nombre' => 'Leche descremada', 'porcion' => '1 vaso', 'cantidad_g' => 200, 'calorias' => 70, 'proteina_g' => 6.8, 'grasa_g' => 0.4, 'carbohidratos_g' => 9.8],
                ],
            ],
            [
                'descripcion' => 'Yogur griego con fruta y granola',
                'preparacion' => 'Sirve el yogur frío y añade la granola justo antes de comer.',
                'ingredientes' => [
                    ['nombre' => 'Yogur griego natural', 'porcion' => '1 taza', 'cantidad_g' => 200, 'calorias' => 118, 'proteina_g' => 20.0, 'grasa_g' => 0.8, 'carbohidratos_g' => 7.2],
                    ['nombre' => 'Fresas', 'porcion' => '1 taza', 'cantidad_g' => 150, 'calorias' => 48, 'proteina_g' => 1.0, 'grasa_g' => 0.5, 'carbohidratos_g' => 11.5],
                    ['nombre' => 'Granola', 'porcion' => '3 cucharadas', 'cantidad_g' => 35, 'calorias' => 158, 'proteina_g' => 4.0, 'grasa_g' => 6.3, 'carbohidratos_g' => 21.4],
                ],
            ],
        ],
        'almuerzo' => [
            [
                'descripcion' => 'Pechuga a la plancha con arroz y ensalada',
                'preparacion' => 'Sella la pechuga por ambos lados y acompáñala con el arroz recién hecho.',
                'ingredientes' => [
                    ['nombre' => 'Pechuga de pollo', 'porcion' => '1 filete', 'cantidad_g' => 180, 'calorias' => 297, 'proteina_g' => 55.8, 'grasa_g' => 6.5, 'carbohidratos_g' => 0.0],
                    ['nombre' => 'Arroz blanco cocido', 'porcion' => '1 taza', 'cantidad_g' => 160, 'calorias' => 208, 'proteina_g' => 4.3, 'grasa_g' => 0.5, 'carbohidratos_g' => 45.0],
                    ['nombre' => 'Ensalada de tomate y lechuga', 'porcion' => '1 plato', 'cantidad_g' => 150, 'calorias' => 35, 'proteina_g' => 1.5, 'grasa_g' => 0.3, 'carbohidratos_g' => 7.0],
                    ['nombre' => 'Aceite de oliva', 'porcion' => '1 cucharada', 'cantidad_g' => 14, 'calorias' => 124, 'proteina_g' => 0.0, 'grasa_g' => 14.0, 'carbohidratos_g' => 0.0],
                ],
            ],
            [
                'descripcion' => 'Bandeja de carne asada con fríjoles',
                'preparacion' => 'Asa la carne al punto y calienta los fríjoles a fuego lento.',
                'ingredientes' => [
                    ['nombre' => 'Carne de res magra', 'porcion' => '1 porción', 'cantidad_g' => 160, 'calorias' => 288, 'proteina_g' => 46.0, 'grasa_g' => 10.6, 'carbohidratos_g' => 0.0],
                    ['nombre' => 'Fríjoles cocidos', 'porcion' => '1 taza', 'cantidad_g' => 180, 'calorias' => 227, 'proteina_g' => 15.2, 'grasa_g' => 0.9, 'carbohidratos_g' => 40.8],
                    ['nombre' => 'Plátano maduro', 'porcion' => 'media unidad', 'cantidad_g' => 90, 'calorias' => 108, 'proteina_g' => 1.2, 'grasa_g' => 0.3, 'carbohidratos_g' => 28.0],
                    ['nombre' => 'Aguacate', 'porcion' => 'un cuarto', 'cantidad_g' => 50, 'calorias' => 80, 'proteina_g' => 1.0, 'grasa_g' => 7.4, 'carbohidratos_g' => 4.3],
                ],
            ],
            [
                'descripcion' => 'Bowl de atún con quinua y vegetales',
                'preparacion' => 'Mezcla la quinua tibia con el atún escurrido y los vegetales crudos.',
                'ingredientes' => [
                    ['nombre' => 'Atún en agua', 'porcion' => '1 lata', 'cantidad_g' => 140, 'calorias' => 152, 'proteina_g' => 33.6, 'grasa_g' => 1.4, 'carbohidratos_g' => 0.0],
                    ['nombre' => 'Quinua cocida', 'porcion' => '1 taza', 'cantidad_g' => 185, 'calorias' => 222, 'proteina_g' => 8.1, 'grasa_g' => 3.6, 'carbohidratos_g' => 39.4],
                    ['nombre' => 'Brócoli al vapor', 'porcion' => '1 taza', 'cantidad_g' => 150, 'calorias' => 51, 'proteina_g' => 4.2, 'grasa_g' => 0.6, 'carbohidratos_g' => 10.0],
                    ['nombre' => 'Aceite de oliva', 'porcion' => '1 cucharadita', 'cantidad_g' => 7, 'calorias' => 62, 'proteina_g' => 0.0, 'grasa_g' => 7.0, 'carbohidratos_g' => 0.0],
                ],
            ],
        ],
        'cena' => [
            [
                'descripcion' => 'Salmón al horno con papa y espárragos',
                'preparacion' => 'Hornea 18 minutos a 200 grados con las papas y los espárragos alrededor.',
                'ingredientes' => [
                    ['nombre' => 'Salmón', 'porcion' => '1 filete', 'cantidad_g' => 150, 'calorias' => 309, 'proteina_g' => 31.0, 'grasa_g' => 20.2, 'carbohidratos_g' => 0.0],
                    ['nombre' => 'Papa criolla', 'porcion' => '1 taza', 'cantidad_g' => 150, 'calorias' => 116, 'proteina_g' => 2.9, 'grasa_g' => 0.2, 'carbohidratos_g' => 26.0],
                    ['nombre' => 'Espárragos', 'porcion' => '1 taza', 'cantidad_g' => 130, 'calorias' => 26, 'proteina_g' => 2.9, 'grasa_g' => 0.2, 'carbohidratos_g' => 5.0],
                ],
            ],
            [
                'descripcion' => 'Crema de verduras con pollo desmechado',
                'preparacion' => 'Licúa las verduras cocidas y añade el pollo al servir.',
                'ingredientes' => [
                    ['nombre' => 'Pollo desmechado', 'porcion' => '1 taza', 'cantidad_g' => 120, 'calorias' => 198, 'proteina_g' => 37.2, 'grasa_g' => 4.3, 'carbohidratos_g' => 0.0],
                    ['nombre' => 'Verduras mixtas', 'porcion' => '2 tazas', 'cantidad_g' => 300, 'calorias' => 96, 'proteina_g' => 5.1, 'grasa_g' => 0.8, 'carbohidratos_g' => 20.4],
                    ['nombre' => 'Aceite de oliva', 'porcion' => '1 cucharadita', 'cantidad_g' => 7, 'calorias' => 62, 'proteina_g' => 0.0, 'grasa_g' => 7.0, 'carbohidratos_g' => 0.0],
                    ['nombre' => 'Pan integral', 'porcion' => '1 tajada', 'cantidad_g' => 35, 'calorias' => 89, 'proteina_g' => 4.0, 'grasa_g' => 1.2, 'carbohidratos_g' => 15.4],
                ],
            ],
            [
                'descripcion' => 'Omelette de claras con champiñones',
                'preparacion' => 'Saltea los champiñones antes de verter las claras batidas.',
                'ingredientes' => [
                    ['nombre' => 'Claras de huevo', 'porcion' => '5 unidades', 'cantidad_g' => 165, 'calorias' => 86, 'proteina_g' => 18.0, 'grasa_g' => 0.3, 'carbohidratos_g' => 1.2],
                    ['nombre' => 'Champiñones', 'porcion' => '1 taza', 'cantidad_g' => 100, 'calorias' => 22, 'proteina_g' => 3.1, 'grasa_g' => 0.3, 'carbohidratos_g' => 3.3],
                    ['nombre' => 'Queso mozzarella', 'porcion' => '1 porción', 'cantidad_g' => 45, 'calorias' => 127, 'proteina_g' => 9.9, 'grasa_g' => 9.4, 'carbohidratos_g' => 1.0],
                    ['nombre' => 'Tostada integral', 'porcion' => '1 unidad', 'cantidad_g' => 30, 'calorias' => 76, 'proteina_g' => 3.4, 'grasa_g' => 1.0, 'carbohidratos_g' => 13.2],
                ],
            ],
        ],
    ];

    /**
     * Actividades de ejemplo, con el factor de corrección que les corresponde
     * (ActivityCorrectionService::FACTORES_POR_TIPO).
     *
     * @var array<int, array{tipo: string, duracion_min: int, calorias_dispositivo: float, factor: float, pasos: int}>
     */
    private const ACTIVIDADES = [
        ['tipo' => 'caminata', 'duracion_min' => 45, 'calorias_dispositivo' => 300.0, 'factor' => 0.85, 'pasos' => 7800],
        ['tipo' => 'trote', 'duracion_min' => 30, 'calorias_dispositivo' => 380.0, 'factor' => 0.85, 'pasos' => 5200],
        ['tipo' => 'pesas', 'duracion_min' => 60, 'calorias_dispositivo' => 320.0, 'factor' => 0.80, 'pasos' => 1800],
        ['tipo' => 'bicicleta', 'duracion_min' => 50, 'calorias_dispositivo' => 420.0, 'factor' => 0.85, 'pasos' => 900],
    ];

    public function __construct(
        private readonly DailyClosureService $cierre,
        private readonly TrendAnalyticsService $tendencias,
    ) {}

    public function run(): void
    {
        $hoy = Carbon::today();

        // 1. Administradora: entra a la consola y activa las cuentas nuevas.
        $this->cuenta([
            'name' => 'Ana Gómez (admin)',
            'email' => 'admin'.self::DOMINIO,
            'rol' => User::ROL_ADMIN,
            'estado' => User::ESTADO_ACTIVO,
            'perfil' => $this->perfil(peso: 68, sexo: 'femenino', edad: 38, estatura: 1.62, deficit: 0.20),
        ]);

        // 2. Cuenta recién registrada: sirve para enseñar el flujo de
        //    activación con código sin tener que registrar a nadie en vivo.
        $this->cuenta([
            'name' => 'Nuevo Usuario',
            'email' => 'pendiente'.self::DOMINIO,
            'estado' => User::ESTADO_PENDIENTE,
            'codigo_activacion' => 'DEMO2024',
        ]);

        // 3. Cuenta activa sin Calculadora: el primer paso del recorrido, con
        //    todas las pantallas pidiendo que se complete.
        $this->cuenta([
            'name' => 'Carlos Nuevo',
            'email' => 'sin-calculadora'.self::DOMINIO,
            'estado' => User::ESTADO_ACTIVO,
        ]);

        /*
         * Las tres siguientes llevan el mismo historial de 21 días y solo se
         * diferencian en cómo evoluciona su peso, que es lo que dispara (o no)
         * una recomendación. La pendiente entre `pesoInicial` y `pesoFinal` es
         * lo que decide: el motor compara el promedio móvil de la última semana
         * contra el de la anterior (sección 5.6).
         */

        // 4. Ritmo correcto: entre el 0,5 % y el 1 % semanal. No genera
        //    recomendación, y el cierre lo dice en vez de quedarse en blanco.
        $enRitmo = $this->cuenta([
            'name' => 'Laura Constante',
            'email' => 'en-ritmo'.self::DOMINIO,
            'estado' => User::ESTADO_ACTIVO,
            'perfil' => $this->perfil(peso: 72, sexo: 'femenino', edad: 34, estatura: 1.65, deficit: 0.20),
        ]);
        $this->historial($enRitmo, $hoy, pesoInicial: 73.6, pesoFinal: 72.0, adherencia: 1.0);

        // 5. Baja demasiado despacio: por debajo del 0,5 % semanal, el motor
        //    sugiere REDUCIR el objetivo calórico y la propuesta queda
        //    pendiente de confirmación en Inicio y en el cierre.
        $lento = $this->cuenta([
            'name' => 'Miguel Meseta',
            'email' => 'baja-lento'.self::DOMINIO,
            'estado' => User::ESTADO_ACTIVO,
            'perfil' => $this->perfil(peso: 94, sexo: 'masculino', edad: 45, estatura: 1.78, deficit: 0.10),
        ]);
        $this->historial($lento, $hoy, pesoInicial: 94.7, pesoFinal: 94.0, adherencia: 0.4);

        // 6. Baja demasiado rápido: por encima del 1 % semanal, el motor
        //    sugiere AUMENTAR el objetivo para no perder masa magra.
        $rapido = $this->cuenta([
            'name' => 'Sofía Acelerada',
            'email' => 'baja-rapido'.self::DOMINIO,
            'estado' => User::ESTADO_ACTIVO,
            'perfil' => $this->perfil(peso: 78, sexo: 'femenino', edad: 29, estatura: 1.70, deficit: 0.30),
        ]);
        $this->historial($rapido, $hoy, pesoInicial: 81.1, pesoFinal: 77.9, adherencia: 1.0);

        $this->command?->newLine();
        $this->command?->info('Cuentas de demostración listas. Contraseña de todas: '.self::CLAVE);
        $this->command?->table(
            ['Correo', 'Escenario'],
            [
                ['admin'.self::DOMINIO, 'Administradora · consola y material de apoyo'],
                ['pendiente'.self::DOMINIO, 'Cuenta pendiente · código DEMO2024'],
                ['sin-calculadora'.self::DOMINIO, 'Activa sin Calculadora · primer paso'],
                ['en-ritmo'.self::DOMINIO, '21 días · ritmo correcto, sin ajuste'],
                ['baja-lento'.self::DOMINIO, '21 días · sugiere REDUCIR el objetivo'],
                ['baja-rapido'.self::DOMINIO, '21 días · sugiere AUMENTAR el objetivo'],
            ],
        );
    }

    /**
     * Crea o rehace una cuenta de demostración. Busca por correo, así que
     * volver a correr el seeder actualiza la que ya existe en vez de duplicarla.
     *
     * @param  array<string, mixed>  $datos
     */
    private function cuenta(array $datos): User
    {
        $perfil = $datos['perfil'] ?? [];
        unset($datos['perfil']);

        $usuario = User::firstOrNew(['email' => $datos['email']]);

        $usuario->fill([
            ...$datos,
            ...$perfil,
            'password' => Hash::make(self::CLAVE),
            'email_verified_at' => now(),
            'rol' => $datos['rol'] ?? User::ROL_USUARIO,
            'estado' => $datos['estado'] ?? User::ESTADO_ACTIVO,
            'activado_en' => ($datos['estado'] ?? User::ESTADO_ACTIVO) === User::ESTADO_ACTIVO ? now() : null,
            'codigo_activacion' => $datos['codigo_activacion'] ?? null,
        ]);

        $usuario->save();

        // Rehacer el historial desde cero: si no, correr el seeder dos veces
        // duplicaría los días y desdibujaría los promedios móviles.
        RegistroDiario::where('usuario_id', $usuario->id)->delete();

        $this->command?->line('  · '.$usuario->email);

        return $usuario;
    }

    /**
     * Perfil nutricional completo, con `calorias_objetivo` calculado por
     * NutritionCalculatorService — nunca a mano (CLAUDE.md sección 7).
     *
     * @return array<string, mixed>
     */
    private function perfil(float $peso, string $sexo, int $edad, float $estatura, float $deficit): array
    {
        $nivel = 1.55;
        $proteina = $deficit >= 0.30 ? 2.2 : ($deficit >= 0.20 ? 2.0 : 1.8);

        $plan = app(NutritionCalculatorService::class)->calculatePlan(
            $peso,
            $nivel,
            NutritionCalculatorService::TIPO_DEFICIT_PORCENTAJE,
            $deficit,
            $proteina,
            0.8,
        );

        return [
            'peso_kg' => $peso,
            'estatura_m' => $estatura,
            'edad' => $edad,
            'sexo' => $sexo,
            'nivel_actividad' => $nivel,
            'tipo_deficit' => NutritionCalculatorService::TIPO_DEFICIT_PORCENTAJE,
            'valor_deficit' => $deficit,
            'proteina_factor' => $proteina,
            'grasa_factor' => 0.8,
            'calorias_objetivo' => round($plan['calorias_objetivo'], 2),
        ];
    }

    /**
     * Siembra los últimos DIAS_HISTORIAL días del usuario y los cierra con el
     * servicio de dominio, que es quien genera las recomendaciones.
     *
     * @param  float  $adherencia  proporción de días en que cumplió el plan; el resto se come de más
     */
    private function historial(User $usuario, Carbon $hoy, float $pesoInicial, float $pesoFinal, float $adherencia): void
    {
        $dias = self::DIAS_HISTORIAL;
        $paso = ($pesoFinal - $pesoInicial) / max(1, $dias - 1);

        for ($i = $dias - 1; $i >= 0; $i--) {
            $fecha = $hoy->copy()->subDays($i);
            $indice = $dias - 1 - $i;

            // Nadie se pesa a diario (sección 5.7): se pesa día sí, día no, que
            // es suficiente para el promedio móvil y bastante más realista.
            $sePesa = $indice % 2 === 0;
            $peso = round($pesoInicial + $paso * $indice + $this->ruido($indice), 1);

            $registro = RegistroDiario::create([
                'usuario_id' => $usuario->id,
                'fecha' => $fecha->toDateString(),
                'peso_kg' => $sePesa ? $peso : null,
                'ingredientes_desayuno' => 'huevos, arepa y queso',
                'ingredientes_almuerzo' => 'pollo, arroz y ensalada',
                'ingredientes_cena' => 'sopa de verduras con pollo',
            ]);

            $this->comidasDelDia($registro, $indice, $adherencia);
            $this->actividadDelDia($registro, $indice);

            // El día de hoy se deja abierto a propósito: es el que se enseña
            // en la demo, con el cierre todavía por hacer.
            if ($i > 0) {
                $this->cierre->cerrar($registro);
            }
        }

        $this->depurarRecomendaciones($usuario);

        // El snapshot de tendencias que normalmente escribe app:calculate-trends.
        $this->tendencias->calcularYPersistir($usuario, $hoy->copy()->subDay());
    }

    /**
     * Deja un historial de recomendaciones creíble.
     *
     * Cerrar veintiún días seguidos genera una recomendación por cada día en
     * que la tendencia se sale de rango, así que el usuario acababa con una
     * docena de propuestas pendientes idénticas — algo que no pasaría nunca en
     * uso real, donde cada una se confirma o se rechaza el mismo día. Se
     * conserva la más reciente pendiente (que es la que se enseña), las dos
     * anteriores resueltas para que "Tu seguimiento" tenga historial, y el
     * resto se descarta.
     *
     * Los estados se escriben directamente, sin pasar por
     * RulesEngineService::confirmar(): confirmar doce ajustes seguidos movería
     * `calorias_objetivo` cientos de kcal y dejaría un perfil absurdo.
     */
    private function depurarRecomendaciones(User $usuario): void
    {
        $recomendaciones = RecomendacionSistema::whereIn(
            'registro_diario_id',
            RegistroDiario::where('usuario_id', $usuario->id)->select('id'),
        )->orderByDesc('id')->get();

        if ($recomendaciones->count() <= 1) {
            return;
        }

        $recomendaciones->skip(1)->take(2)->values()->each(
            fn (RecomendacionSistema $recomendacion, int $posicion) => $recomendacion->update([
                'estado' => $posicion === 0 ? 'confirmada' : 'rechazada',
                'confirmada_en' => $posicion === 0 ? $recomendacion->created_at : null,
            ]),
        );

        RecomendacionSistema::whereIn('id', $recomendaciones->skip(3)->pluck('id'))->delete();
    }

    /**
     * Las tres comidas del día: el plan siempre, y lo realmente comido en todos
     * los días menos hoy (hoy se deja a medias para poder enseñar el cierre).
     */
    private function comidasDelDia(RegistroDiario $registro, int $indice, float $adherencia): void
    {
        $esHoy = $registro->fecha->isToday();

        // ¿Cumplió el plan este día? Determinista, no aleatorio: la demo tiene
        // que verse igual cada vez que se siembra.
        $cumplio = ($indice % 10) / 10 < $adherencia;

        foreach (['desayuno', 'almuerzo', 'cena'] as $posicion => $tipo) {
            $receta = self::CATALOGO[$tipo][($indice + $posicion) % count(self::CATALOGO[$tipo])];
            $sumar = fn (string $campo): float => round(array_sum(array_column($receta['ingredientes'], $campo)), 2);

            $plan = PlanComida::create([
                'registro_diario_id' => $registro->id,
                'tipo_comida' => $tipo,
                'descripcion' => $receta['descripcion'],
                'preparacion' => $receta['preparacion'],
                'ingredientes_detalle' => $receta['ingredientes'],
                'calorias_estimadas' => $sumar('calorias'),
                'proteina_g' => $sumar('proteina_g'),
                'grasa_g' => $sumar('grasa_g'),
                'carbohidratos_g' => $sumar('carbohidratos_g'),
            ]);

            // Hoy: solo el desayuno registrado, para que el plan del día se vea
            // a medio camino y el cierre tenga algo que preguntar.
            if ($esHoy && $tipo !== 'desayuno') {
                continue;
            }

            // Un día "flojo" se come de más, que es lo que acaba recortando el
            // déficit y frenando la tendencia.
            $exceso = $cumplio ? 1.0 : 1.25;

            ComidaReal::create([
                'plan_comida_id' => $plan->id,
                'calorias_reales' => round((float) $plan->calorias_estimadas * $exceso, 2),
                'proteina_g' => round((float) $plan->proteina_g * ($cumplio ? 1.0 : 1.05), 2),
                'grasa_g' => round((float) $plan->grasa_g * $exceso, 2),
                'carbohidratos_g' => round((float) $plan->carbohidratos_g * $exceso, 2),
                'consumido_en' => $registro->fecha->copy()->addHours(7 + $posicion * 6),
                'notas' => $cumplio
                    ? 'Cumplí con lo sugerido.'
                    : 'Me pasé con la porción y añadí algo de postre.',
            ]);
        }
    }

    /** Actividad física de la mayoría de los días, no de todos. */
    private function actividadDelDia(RegistroDiario $registro, int $indice): void
    {
        // Descansa uno de cada cuatro días.
        if ($indice % 4 === 3) {
            return;
        }

        $actividad = self::ACTIVIDADES[$indice % count(self::ACTIVIDADES)];

        ActividadFisica::create([
            'registro_diario_id' => $registro->id,
            'tipo' => $actividad['tipo'],
            'duracion_min' => $actividad['duracion_min'],
            'pasos' => $actividad['pasos'],
            'calorias_dispositivo' => $actividad['calorias_dispositivo'],
            'factor_correccion' => $actividad['factor'],
            'calorias_ajustadas' => round($actividad['calorias_dispositivo'] * $actividad['factor'], 2),
            'fuente' => $indice % 2 === 0 ? 'dispositivo' : 'manual',
        ]);
    }

    /**
     * Oscilación de báscula: el peso real no baja en línea recta, y una serie
     * perfectamente lineal delata que los datos son inventados. Determinista a
     * propósito, para que la demo se vea igual cada vez.
     *
     * El periodo es 4 y no 7 porque solo se registra el peso los días pares:
     * con periodo 7 la oscilación no se compensaba entre una ventana móvil y la
     * anterior y desplazaba el % de pérdida semanal medio punto, que es
     * justamente la diferencia entre "ritmo correcto" y "demasiado rápido".
     */
    private function ruido(int $indice): float
    {
        return [0.15, 0.0, -0.15, 0.0][$indice % 4];
    }
}
