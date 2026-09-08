<?php

use App\Models\ActividadFisica;
use App\Models\ComidaReal;
use App\Models\IngredienteDisponible;
use App\Models\PlanComida;
use App\Models\RecomendacionSistema;
use App\Models\RegistroDiario;
use App\Models\User;
use App\Services\MealPlanGeneratorService;
use Illuminate\Support\Carbon;

/**
 * Perfil base del usuario del flujo, elegido para que la sección 5 dé cifras
 * redondas y verificables a mano:
 *
 *   TMB                     = 80 * 22            = 1760 kcal
 *   calorias_mantenimiento  = 1760 * 1.5         = 2640 kcal
 *   calorias_objetivo       = 2640 * (1 - 0.20)  = 2112 kcal
 *   proteina_g              = 80 * 2.0           =  160 g  (640 kcal)
 *   grasa_g                 = 80 * 0.8           =   64 g  (576 kcal)
 *   carbohidratos_g         = (2112 - 1216) / 4  =  224 g  (896 kcal)
 *
 * @return array<string, mixed>
 */
function perfilDelFlujoDiario(): array
{
    return [
        'peso_kg' => 80,
        'estatura_m' => 1.75,
        'edad' => 35,
        'sexo' => 'masculino',
        'nivel_actividad' => 1.5,
        'tipo_deficit' => 'porcentaje',
        'valor_deficit' => 0.2,
        'proteina_factor' => 2.0,
        'grasa_factor' => 0.8,
    ];
}

const OBJETIVO_KCAL_FLUJO_DIARIO = 2112.0;

const PROTEINA_OBJETIVO_FLUJO_DIARIO = 160.0;

/**
 * Las vistas formatean las kcal con separador de miles "." y decimal ","
 * (locale es), así que las aserciones sobre el HTML tienen que usar el mismo
 * formato que la vista, no `number_format()` a secas.
 */
function kcalDelFlujoDiario(float $valor): string
{
    return number_format($valor, 0, ',', '.');
}

/**
 * Despensa con holgura suficiente (6325 kcal y 399 g de proteína disponibles
 * para un objetivo de 2112 kcal / 160 g) para que el reparto codicioso de la
 * sección 4.2 no se quede corto por falta de inventario.
 *
 * @return array<int, array<string, mixed>>
 */
function despensaDelFlujoDiario(): array
{
    return [
        ['nombre' => 'Pechuga de pollo', 'cantidad_g' => 800, 'calorias_por_100g' => 165, 'proteina_por_100g' => 31, 'grasa_por_100g' => 3.6, 'carbohidratos_por_100g' => 0],
        ['nombre' => 'Arroz integral', 'cantidad_g' => 600, 'calorias_por_100g' => 350, 'proteina_por_100g' => 7.5, 'grasa_por_100g' => 2.7, 'carbohidratos_por_100g' => 72],
        ['nombre' => 'Avena', 'cantidad_g' => 400, 'calorias_por_100g' => 389, 'proteina_por_100g' => 16.9, 'grasa_por_100g' => 6.9, 'carbohidratos_por_100g' => 66],
        ['nombre' => 'Huevo', 'cantidad_g' => 300, 'calorias_por_100g' => 155, 'proteina_por_100g' => 13, 'grasa_por_100g' => 11, 'carbohidratos_por_100g' => 1.1],
        ['nombre' => 'Aceite de oliva', 'cantidad_g' => 100, 'calorias_por_100g' => 884, 'proteina_por_100g' => 0, 'grasa_por_100g' => 100, 'carbohidratos_por_100g' => 0],
    ];
}

/**
 * Día fijo para que el flujo no dependa de cuándo se ejecute la suite (y para
 * que "hoy" no cambie a mitad de test si corre justo sobre medianoche).
 */
beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-03-15 09:00:00'));
});

test('el día completo de un usuario, paso a paso y cuadrando con la sección 5', function () {
    // ── Paso 1: registro + activación + parámetros base ─────────────────────
    $this->post('/register', [
        'name' => 'Ana Flujo',
        'email' => 'ana@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertRedirect(route('activacion.create', absolute: false));

    $this->assertAuthenticated();

    $usuario = User::firstWhere('email', 'ana@example.com');

    // La cuenta nace pendiente: hasta canjear el código que entrega el
    // administrador no se entra a la aplicación (sección 4.26). Que el resto de
    // la aplicación esté cerrada mientras tanto lo cubre tests/Feature/ActivacionTest.php;
    // aquí no se hace ese GET a propósito, porque fijaría la "URL anterior" de
    // la sesión y con ella el destino de los `Redirect::back()` de más abajo.
    $this->actingAs($usuario)
        ->post(route('activacion.store'), ['codigo' => $usuario->codigo_activacion])
        ->assertRedirect(route('calculadora.edit'));

    $usuario->refresh();

    // Recién registrado el perfil nutricional está vacío: sin él no se puede
    // generar plan ni cerrar el día (secciones 4.2 y 4.5).
    expect($usuario->peso_kg)->toBeNull()
        ->and($usuario->calorias_objetivo)->toBeNull()
        ->and($usuario->estado)->toBe(User::ESTADO_ACTIVO);

    $this->actingAs($usuario)
        ->put(route('calculadora.update'), perfilDelFlujoDiario())
        ->assertRedirect(route('calculadora.edit'))
        ->assertSessionHasNoErrors();

    $usuario->refresh();

    expect((float) $usuario->peso_kg)->toBe(80.0)
        ->and((float) $usuario->nivel_actividad)->toBe(1.5)
        ->and($usuario->tipo_deficit)->toBe('porcentaje')
        ->and((float) $usuario->proteina_factor)->toBe(2.0)
        ->and((float) $usuario->grasa_factor)->toBe(0.8)
        // Guardar los parámetros deja también el objetivo calórico vigente,
        // que es lo que después dimensiona plan, cierre y recomendaciones.
        ->and((float) $usuario->calorias_objetivo)->toBe(OBJETIVO_KCAL_FLUJO_DIARIO);

    // ── Paso 2: ingredientes disponibles de hoy ─────────────────────────────
    $this->actingAs($usuario)
        ->post(route('ingredientes.store'), ['ingredientes' => despensaDelFlujoDiario()])
        ->assertRedirect(route('ingredientes.create'))
        ->assertSessionHas('status', 'ingredientes-guardados');

    $registroDiario = RegistroDiario::where('usuario_id', $usuario->id)
        ->whereDate('fecha', now()->toDateString())
        ->first();

    // Reportar ingredientes es lo que crea el RegistroDiario de hoy (sección 4.1).
    expect($registroDiario)->not->toBeNull()
        ->and($registroDiario->fecha->toDateString())->toBe('2026-03-15')
        ->and($registroDiario->cerrado)->toBeFalse()
        ->and(IngredienteDisponible::where('registro_diario_id', $registroDiario->id)->count())->toBe(5);

    // ── Paso 3: generación del plan de comidas ──────────────────────────────
    $this->actingAs($usuario)
        ->post(route('planes.generar', $registroDiario))
        ->assertRedirect(route('planes.show', $registroDiario))
        ->assertSessionHas('status', 'plan-generado');

    $planes = $registroDiario->planesComida()->orderBy('id')->get();

    expect($planes)->toHaveCount(3)
        ->and($planes->pluck('tipo_comida')->all())->toBe(['desayuno', 'almuerzo', 'cena']);

    // Las calorías del plan caen sobre el objetivo de la sección 5; los macros
    // derivan de la heurística codiciosa y tienen la desviación documentada en
    // la sección 4.2 (proteína alta, carbohidratos bajos), así que no se fijan aquí.
    $totalPlanificado = (float) $planes->sum(fn (PlanComida $plan) => (float) $plan->calorias_estimadas);

    expect($totalPlanificado)->toEqualWithDelta(OBJETIVO_KCAL_FLUJO_DIARIO, OBJETIVO_KCAL_FLUJO_DIARIO * 0.05);

    // Cada comida recibe su porcentaje de MealPlanGeneratorService::DISTRIBUCION_COMIDAS.
    foreach (MealPlanGeneratorService::DISTRIBUCION_COMIDAS as $tipoComida => $porcentaje) {
        $comida = $planes->firstWhere('tipo_comida', $tipoComida);

        expect((float) $comida->calorias_estimadas)
            ->toEqualWithDelta(OBJETIVO_KCAL_FLUJO_DIARIO * $porcentaje, OBJETIVO_KCAL_FLUJO_DIARIO * $porcentaje * 0.05)
            ->and($comida->ingredientes_detalle)->not->toBeEmpty();
    }

    $desayuno = $planes->firstWhere('tipo_comida', 'desayuno');
    $almuerzo = $planes->firstWhere('tipo_comida', 'almuerzo');
    $cena = $planes->firstWhere('tipo_comida', 'cena');

    $estimadasDesayuno = (float) $desayuno->calorias_estimadas;
    $estimadasAlmuerzo = (float) $almuerzo->calorias_estimadas;
    $estimadasCena = (float) $cena->calorias_estimadas;

    // ── Paso 4: comida real del desayuno, con una ligera desviación ─────────
    // Comió ~80 kcal de más (≈15% sobre lo planificado para el desayuno).
    $desviacion = 80.0;

    $comidaRealDesayuno = [
        'calorias_reales' => round($estimadasDesayuno + $desviacion, 2),
        'proteina_g' => round((float) $desayuno->proteina_g + 3, 2),
        'grasa_g' => round((float) $desayuno->grasa_g + 2, 2),
        'carbohidratos_g' => round((float) $desayuno->carbohidratos_g + 8, 2),
        'notas' => 'Un poco más de arroz de la cuenta.',
    ];

    $this->actingAs($usuario)
        ->post(route('comida-real.store', $desayuno), $comidaRealDesayuno)
        ->assertRedirect(route('planes.show', $registroDiario))
        ->assertSessionHas('status', 'comida-real-guardada');

    expect(ComidaReal::where('plan_comida_id', $desayuno->id)->count())->toBe(1);

    // El exceso se reparte, con signo, entre las comidas todavía sin registrar,
    // proporcionalmente a su participación en el total pendiente (sección 4.3).
    $pendientePlanificado = $estimadasAlmuerzo + $estimadasCena;
    $esperadoAlmuerzo = round($estimadasAlmuerzo - $desviacion * ($estimadasAlmuerzo / $pendientePlanificado), 2);
    $esperadoCena = round($estimadasCena - $desviacion * ($estimadasCena / $pendientePlanificado), 2);

    expect((float) $almuerzo->fresh()->calorias_estimadas)->toEqualWithDelta($esperadoAlmuerzo, 0.01)
        ->and((float) $cena->fresh()->calorias_estimadas)->toEqualWithDelta($esperadoCena, 0.01)
        // Lo recortado a almuerzo + cena es exactamente el exceso del desayuno.
        ->and(($estimadasAlmuerzo - $esperadoAlmuerzo) + ($estimadasCena - $esperadoCena))
        ->toEqualWithDelta($desviacion, 0.02)
        // La comida ya registrada no se toca nunca.
        ->and((float) $desayuno->fresh()->calorias_estimadas)->toBe($estimadasDesayuno)
        // Y el acumulado del día refleja lo realmente comido.
        ->and((float) $registroDiario->fresh()->calorias_consumidas)
        ->toBe($comidaRealDesayuno['calorias_reales']);

    // ── Paso 5: actividad física ────────────────────────────────────────────
    // caminata → factor 0.85 · 400 = 340 kcal; pesas → factor 0.80 · 200 = 160 kcal.
    $this->actingAs($usuario)->post(route('actividades.store', $registroDiario), [
        'tipo_actividad' => 'caminata',
        'duracion_min' => 45,
        'calorias_dispositivo' => 400,
        'pasos' => 6200,
        'fuente' => 'dispositivo',
    ])->assertRedirect(route('planes.show', $registroDiario))->assertSessionHas('status', 'actividad-guardada');

    $this->actingAs($usuario)->post(route('actividades.store', $registroDiario), [
        'tipo_actividad' => 'pesas',
        'duracion_min' => 50,
        'calorias_dispositivo' => 200,
        'fuente' => 'dispositivo',
    ])->assertRedirect(route('planes.show', $registroDiario));

    $actividades = ActividadFisica::where('registro_diario_id', $registroDiario->id)
        ->orderBy('id')
        ->get();

    expect($actividades)->toHaveCount(2)
        ->and((float) $actividades[0]->factor_correccion)->toBe(0.85)
        ->and((float) $actividades[0]->calorias_ajustadas)->toBe(340.0)
        ->and($actividades[0]->pasos)->toBe(6200)
        ->and($actividades[0]->fuente)->toBe('dispositivo')
        ->and((float) $actividades[1]->factor_correccion)->toBe(0.80)
        ->and((float) $actividades[1]->calorias_ajustadas)->toBe(160.0)
        ->and((float) $registroDiario->fresh()->calorias_actividad_ajustada)->toBe(500.0);

    // ── Paso 6: cierre del día ──────────────────────────────────────────────
    $this->actingAs($usuario)
        ->post(route('cierre.cerrar', $registroDiario))
        ->assertRedirect(route('planes.show', $registroDiario))
        ->assertSessionHas('status', 'dia-cerrado');

    $registroDiario->refresh();

    $caloriasConsumidas = $comidaRealDesayuno['calorias_reales'];
    $caloriasActividad = 500.0;
    $deficitEsperado = round(OBJETIVO_KCAL_FLUJO_DIARIO - $caloriasConsumidas + $caloriasActividad, 2);

    expect($registroDiario->cerrado)->toBeTrue()
        ->and($registroDiario->cerrado_en)->not->toBeNull()
        // Objetivo: el de la sección 5, no el del plan generado.
        ->and((float) $registroDiario->calorias_objetivo_dia)->toBe(OBJETIVO_KCAL_FLUJO_DIARIO)
        // Consumidas: solo el desayuno, que es la única ComidaReal registrada.
        ->and((float) $registroDiario->calorias_consumidas)->toBe($caloriasConsumidas)
        // Gasto por actividad: suma de calorias_ajustadas, no de las del dispositivo.
        ->and((float) $registroDiario->calorias_actividad_ajustada)->toBe($caloriasActividad)
        // deficit = objetivo - consumidas + actividad_ajustada.
        ->and((float) $registroDiario->deficit_diario)->toBe($deficitEsperado)
        // Macros: el cierre congela el par objetivo/real de proteína (sección 4.5).
        ->and((float) $registroDiario->proteina_objetivo_g)->toBe(PROTEINA_OBJETIVO_FLUJO_DIARIO)
        ->and((float) $registroDiario->proteina_consumida_g)->toBe($comidaRealDesayuno['proteina_g']);

    $cumplimientoProteina = $comidaRealDesayuno['proteina_g'] / PROTEINA_OBJETIVO_FLUJO_DIARIO * 100;

    $this->actingAs($usuario)->get(route('planes.show', $registroDiario))
        ->assertOk()
        ->assertSee('Resumen del cierre')
        ->assertSee(kcalDelFlujoDiario(OBJETIVO_KCAL_FLUJO_DIARIO))
        ->assertSee(kcalDelFlujoDiario($caloriasConsumidas))
        ->assertSee(kcalDelFlujoDiario($caloriasActividad))
        ->assertSee(kcalDelFlujoDiario($deficitEsperado))
        ->assertSee(number_format($cumplimientoProteina, 1, ',', '.').'%');

    // ── Paso 7: recomendaciones del sistema ─────────────────────────────────
    // Con un solo día de historial no corresponde ninguna: la sección 6 prohíbe
    // derivar un ajuste de calorias_objetivo de un valor diario aislado, hacen
    // falta los promedios móviles de 7 días de TrendAnalyticsService.
    expect(RecomendacionSistema::where('registro_diario_id', $registroDiario->id)->count())->toBe(0)
        // Y el objetivo vigente sigue intacto: nada lo ajusta automáticamente.
        ->and((float) $usuario->fresh()->calorias_objetivo)->toBe(OBJETIVO_KCAL_FLUJO_DIARIO);

    // Y la pantalla dice POR QUÉ está vacía —falta historial, no es que el
    // ritmo sea correcto— con el avance hacia los 7 días (sección 5.6).
    $this->actingAs($usuario)->get(route('planes.show', $registroDiario))
        ->assertSee('Todavía no hay historial suficiente para sugerirte un ajuste.')
        ->assertSee('Días con plan en la última semana')
        ->assertSee('1 / 7');
});

test('un ajuste calórico confirmado pasa a dimensionar el plan y el cierre', function () {
    $usuario = User::factory()->create(perfilDelFlujoDiario());

    // Punto de partida: el objetivo de la sección 5 derivado del perfil.
    expect((float) $usuario->calorias_objetivo)->toBe(OBJETIVO_KCAL_FLUJO_DIARIO);

    $ayer = RegistroDiario::factory()->for($usuario, 'usuario')->create([
        'fecha' => now()->subDay()->toDateString(),
    ]);

    $recomendacion = RecomendacionSistema::factory()->for($ayer, 'registroDiario')->create([
        'tipo' => 'ajuste_calorico',
        'estado' => 'pendiente',
        'confirmada_en' => null,
        'calorias_objetivo_sugeridas' => 1962,
    ]);

    $this->actingAs($usuario)->post(route('recomendaciones.confirmar', $recomendacion));

    // actingAs() deja fijada esta misma instancia como usuario autenticado de
    // las peticiones siguientes, así que hay que releerla: en producción cada
    // petición la carga de la base de datos ya actualizada.
    $usuario->refresh();

    expect((float) $usuario->calorias_objetivo)->toBe(1962.0);

    // El plan de hoy se dimensiona contra el objetivo vigente, no contra los
    // 2112 kcal que sigue dando la fórmula cruda del perfil.
    $this->actingAs($usuario)->post(route('ingredientes.store'), ['ingredientes' => despensaDelFlujoDiario()]);

    $registroDiario = RegistroDiario::where('usuario_id', $usuario->id)
        ->whereDate('fecha', now()->toDateString())
        ->firstOrFail();

    $this->actingAs($usuario)->post(route('planes.generar', $registroDiario))
        ->assertSessionHas('status', 'plan-generado');

    $totalPlanificado = (float) $registroDiario->planesComida()->get()
        ->sum(fn (PlanComida $plan) => (float) $plan->calorias_estimadas);

    expect($totalPlanificado)->toEqualWithDelta(1962.0, 1962.0 * 0.05)
        ->and($totalPlanificado)->toBeLessThan(OBJETIVO_KCAL_FLUJO_DIARIO);

    // Y el cierre congela ese mismo objetivo vigente.
    $this->actingAs($usuario)->post(route('cierre.cerrar', $registroDiario))->assertSessionHas('status', 'dia-cerrado');

    expect((float) $registroDiario->fresh()->calorias_objetivo_dia)->toBe(1962.0);
});

test('el día cerrado queda congelado y su resumen ya no depende del perfil', function () {
    $usuario = User::factory()->create(perfilDelFlujoDiario());

    $this->actingAs($usuario)->post(route('ingredientes.store'), ['ingredientes' => despensaDelFlujoDiario()]);

    $registroDiario = RegistroDiario::where('usuario_id', $usuario->id)->firstOrFail();

    $this->actingAs($usuario)->post(route('planes.generar', $registroDiario));

    $desayuno = $registroDiario->planesComida()->where('tipo_comida', 'desayuno')->firstOrFail();

    $this->actingAs($usuario)->post(route('comida-real.store', $desayuno), [
        'calorias_reales' => 600,
        'proteina_g' => 45,
        'grasa_g' => 20,
        'carbohidratos_g' => 55,
    ]);

    $this->actingAs($usuario)->post(route('actividades.store', $registroDiario), [
        'tipo_actividad' => 'caminata',
        'duracion_min' => 45,
        'calorias_dispositivo' => 400,
        'fuente' => 'dispositivo',
    ]);

    $this->actingAs($usuario)->post(route('cierre.cerrar', $registroDiario))->assertSessionHas('status', 'dia-cerrado');

    // deficit = 2112 - 600 + 340 = 1852
    expect((float) $registroDiario->fresh()->deficit_diario)->toBe(1852.0);

    // El día cerrado rechaza comidas y actividad nuevas (sección 4.5).
    $almuerzo = $registroDiario->planesComida()->where('tipo_comida', 'almuerzo')->firstOrFail();

    $this->actingAs($usuario)->post(route('comida-real.store', $almuerzo), [
        'calorias_reales' => 800,
        'proteina_g' => 60,
        'grasa_g' => 25,
        'carbohidratos_g' => 70,
    ])->assertRedirect(route('planes.show', $registroDiario))->assertSessionHas('error');

    // Cambiar el perfil después no reescribe la historia: el resumen de un día
    // cerrado se lee del snapshot persistido, no se recalcula.
    $usuario->update(['peso_kg' => 90]);

    $this->actingAs($usuario)->get(route('planes.show', $registroDiario))
        ->assertOk()
        ->assertSee(kcalDelFlujoDiario(OBJETIVO_KCAL_FLUJO_DIARIO))
        ->assertSee('1.852');
});
