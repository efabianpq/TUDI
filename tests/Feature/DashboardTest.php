<?php

use App\Models\ActividadFisica;
use App\Models\ComidaReal;
use App\Models\PlanComida;
use App\Models\RecomendacionSistema;
use App\Models\RegistroDiario;
use App\Models\User;

/**
 * Inicio: cuatro bloques, cada uno con sus propios sub-estados según cuánto
 * ha usado la app el usuario (CLAUDE.md sección 5.8).
 *
 *  0. Avisos          — sin cambios: plan y comidas de ayer sin reportar.
 *  1. Bienvenida      — solo en el primer login, sin ningún RegistroDiario.
 *  2. Hoy             — sin plan / en curso / cerrado.
 *  3. Tu tendencia    — historial insuficiente / suficiente.
 *  4. Tu seguimiento  — sin semana completa / con datos.
 *
 * Same profile as tests/Feature/CierreDiarioTest.php:
 * objetivo = 80 * 22 * 1.5 * 0.8 = 2112 kcal.
 */
function usuarioParaDashboard(array $sobrescribir = []): User
{
    return User::factory()->create(array_merge([
        'peso_kg' => 80,
        'estatura_m' => 1.75,
        'edad' => 35,
        'sexo' => 'masculino',
        'nivel_actividad' => 1.5,
        'tipo_deficit' => 'porcentaje',
        'valor_deficit' => 0.2,
        'proteina_factor' => 2.0,
        'grasa_factor' => 0.8,
    ], $sobrescribir));
}

/**
 * Siete días de historial cerrado (días 1 a 7 hacia atrás), lo mínimo para que
 * "Tu tendencia" y "Tu seguimiento" salgan de su sub-estado inicial. Deja
 * libre el día de hoy para que cada test construya su propio caso.
 */
function historialDeUnaSemana(User $usuario): void
{
    foreach (range(1, 7) as $atras) {
        RegistroDiario::factory()->for($usuario, 'usuario')->cerrado()->create([
            'fecha' => now()->subDays($atras)->toDateString(),
            'peso_kg' => 80.0,
        ]);
    }
}

it('exige autenticación para ver el inicio', function () {
    $this->get(route('dashboard'))->assertRedirect(route('login'));
});

/*
|--------------------------------------------------------------------------
| Bloque 1 — Bienvenida (primer login, sin ningún RegistroDiario)
|--------------------------------------------------------------------------
*/

it('en el primer login sin parámetros, invita a la Calculadora', function () {
    $usuario = User::factory()->create(['peso_kg' => null, 'nivel_actividad' => null]);

    $this->actingAs($usuario)->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Bienvenido a TUDéficit Inteligente')
        ->assertSee(route('calculadora.edit'))
        ->assertDontSee('Tu tendencia')
        ->assertDontSee('Tu seguimiento');
});

it('en el primer login con parámetros ya completos, invita a crear el primer plan', function () {
    $usuario = usuarioParaDashboard();

    $this->actingAs($usuario)->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Bienvenido a TUDéficit Inteligente')
        ->assertSee('Crear tu primer plan de hoy')
        ->assertDontSee('Tu tendencia')
        ->assertDontSee('Tu seguimiento');
});

/*
|--------------------------------------------------------------------------
| Bloque 2 — Hoy
|--------------------------------------------------------------------------
*/

it('Hoy: sin plan para un usuario recurrente que todavía no abrió el día', function () {
    $usuario = usuarioParaDashboard();
    historialDeUnaSemana($usuario);

    $this->actingAs($usuario)->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Todavía no has creado el plan de hoy.')
        ->assertSee('Generar plan de hoy');
});

it('Hoy: en curso muestra lo que queda del día, no un déficit todavía sin cerrar', function () {
    $usuario = usuarioParaDashboard();
    historialDeUnaSemana($usuario);

    $registroDiario = RegistroDiario::factory()->for($usuario, 'usuario')->create([
        'fecha' => now()->toDateString(),
    ]);

    $desayuno = PlanComida::factory()->for($registroDiario, 'registroDiario')->create([
        'tipo_comida' => 'desayuno',
        'calorias_estimadas' => 528,
    ]);

    ComidaReal::factory()->for($desayuno, 'planComida')->create([
        'calorias_reales' => 600,
        'proteina_g' => 45,
    ]);

    // Almuerzo planificado pero sin comida real todavía; cena ni siquiera planificada.
    PlanComida::factory()->for($registroDiario, 'registroDiario')->create([
        'tipo_comida' => 'almuerzo',
        'calorias_estimadas' => 845,
    ]);

    ActividadFisica::factory()->for($registroDiario, 'registroDiario')->create([
        'tipo' => 'caminata',
        'calorias_dispositivo' => 400,
        'factor_correccion' => 0.85,
        'calorias_ajustadas' => 340,
    ]);

    $respuesta = $this->actingAs($usuario)->get(route('dashboard'));

    // objetivo 2112 · consumidas 600 · actividad 340.
    // Saldo de calorías (objetivo - consumido, sección 5.21) = 2112 - 600 = 1512.
    $respuesta->assertOk()
        ->assertSee('2.112')   // calorías objetivo
        ->assertSee('600')     // calorías consumidas
        ->assertSee('340')     // gasto por actividad ajustado
        ->assertSee('Te quedan')
        ->assertSee('1.512')   // saldo de calorías que aún quedan del día
        ->assertSee('almuerzo')
        ->assertSee('cena')
        ->assertDontSee('Déficit de hoy')
        ->assertSee('desayuno')
        ->assertSee('registrada')
        ->assertSee('planificada')
        ->assertSee('pendiente')
        ->assertSee('Abrir el plan de hoy');
});

it('Hoy: cerrado conserva la misma tarjeta principal y solo cambia lo que dicen sus cifras', function () {
    $usuario = usuarioParaDashboard();
    historialDeUnaSemana($usuario);

    RegistroDiario::factory()->for($usuario, 'usuario')->cerrado()->create([
        'fecha' => now()->toDateString(),
        'calorias_objetivo_dia' => 2112,
        'calorias_consumidas' => 1800,
        'calorias_actividad_ajustada' => 0,
        'deficit_diario' => 312,
        'proteina_objetivo_g' => 160,
        'proteina_consumida_g' => 140,
    ]);

    $this->actingAs($usuario)->get(route('dashboard'))
        ->assertOk()
        // La tarjeta no se sustituye por otra: sigue el mismo panel con su anillo.
        ->assertSee('tudi-ring', escape: false)
        ->assertSee('cerrado')
        // Con el día cerrado el déficit ya es un resultado y se llama por su nombre.
        ->assertSee('Déficit de')
        ->assertSee('312')
        ->assertSee('Ver el detalle del día')
        ->assertDontSee('Abrir el plan de hoy')
        // La tarjeta de cierre es la del plan diario, no la de Inicio.
        ->assertDontSee('Resultado real del día');
});

it('Hoy: la tarjeta principal muestra los tres macros, no solo la proteína', function () {
    $usuario = usuarioParaDashboard();

    $registroDiario = RegistroDiario::factory()->for($usuario, 'usuario')->create([
        'fecha' => now()->toDateString(),
    ]);

    $desayuno = PlanComida::factory()->for($registroDiario, 'registroDiario')->create([
        'tipo_comida' => 'desayuno',
    ]);

    ComidaReal::factory()->for($desayuno, 'planComida')->create([
        'calorias_reales' => 600,
        'proteina_g' => 45,
        'grasa_g' => 20,
        'carbohidratos_g' => 60,
    ]);

    // Perfil de 80 kg: 160 g de proteína, 64 g de grasa y el resto en carbohidratos.
    $this->actingAs($usuario)->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Proteína')
        ->assertSee('Grasas')
        ->assertSee('Carbohidratos')
        ->assertSee('45 / 160 g')
        ->assertSee('20 / 64 g');
});

it('muestra un aviso cuando faltan parámetros nutricionales en vez de fallar con un 500', function () {
    $usuario = usuarioParaDashboard(['proteina_factor' => null]);
    RegistroDiario::factory()->for($usuario, 'usuario')->create(['fecha' => now()->toDateString()]);

    $this->actingAs($usuario)->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Completa tus parámetros nutricionales para ver el resumen de hoy.');
});

/*
|--------------------------------------------------------------------------
| Bloque 3 — Tu tendencia
|--------------------------------------------------------------------------
*/

it('Tu tendencia: con menos de 7 días muestra el checklist, nunca un gráfico vacío', function () {
    $usuario = usuarioParaDashboard();

    // Solo 2 días de historial: ni de lejos la ventana de 7 que exige la sección 6.
    RegistroDiario::factory()->for($usuario, 'usuario')->create(['fecha' => now()->toDateString(), 'peso_kg' => 80]);
    RegistroDiario::factory()->for($usuario, 'usuario')->create(['fecha' => now()->subDay()->toDateString(), 'peso_kg' => 79.8]);

    $this->actingAs($usuario)->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Todavía no hay historial suficiente para sugerirte un ajuste.')
        ->assertSee('Días con plan en la última semana')
        ->assertDontSee('Último peso registrado')
        ->assertDontSee('Racha de días cerrados');
});

it('Tu tendencia: con 7 días muestra el último peso real y el promedio, por separado', function () {
    $usuario = usuarioParaDashboard();

    foreach (range(0, 6) as $dias) {
        RegistroDiario::factory()->for($usuario, 'usuario')->cerrado()->create([
            'fecha' => now()->subDays($dias)->toDateString(),
            'peso_kg' => 80.0,
            'deficit_diario' => 500,
            'calorias_consumidas' => 1800,
        ]);
    }

    $this->actingAs($usuario)->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Último peso registrado')
        ->assertSee('Tendencia 7 días')
        ->assertSee('80,00 kg')
        ->assertSee('no es tu peso de hoy')
        ->assertSee('Déficit promedio')
        ->assertSee('Racha de días cerrados')
        // El sparkline se dibuja en el servidor: ni Chart.js ni CDN.
        ->assertSee('<polyline', false)
        ->assertDontSee('chart.js', false);
});

it('fecha el último pesaje en días, no en horas desde la medianoche', function () {
    $usuario = usuarioParaDashboard();

    // Ventana completa, con el pesaje más reciente hace dos días: `fecha` no
    // guarda hora, así que "hace 9 horas" sería una precisión que el dato no
    // tiene. Hoy y ayer se dicen por su nombre.
    foreach (range(0, 6) as $dias) {
        RegistroDiario::factory()->for($usuario, 'usuario')->cerrado()->create([
            'fecha' => now()->subDays($dias)->toDateString(),
            'peso_kg' => $dias >= 2 ? 80.0 + $dias * 0.1 : null,
        ]);
    }

    $this->actingAs($usuario)->get(route('dashboard'))
        ->assertOk()
        ->assertSee('hace 2 días')
        ->assertDontSee('horas');
});

it('dice "hoy" cuando el pesaje más reciente es el de hoy', function () {
    $usuario = usuarioParaDashboard();

    foreach (range(0, 6) as $dias) {
        RegistroDiario::factory()->for($usuario, 'usuario')->cerrado()->create([
            'fecha' => now()->subDays($dias)->toDateString(),
            'peso_kg' => 80.0,
        ]);
    }

    $this->actingAs($usuario)->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Último peso registrado')
        ->assertDontSee('hace 0 días');
});

it('muestra la racha de días seguidos cerrados', function () {
    $usuario = usuarioParaDashboard();

    // Ventana completa de 7 días (datos_suficientes), con una racha de 3
    // días cerrados que empieza ayer: hoy queda libre (día en curso).
    foreach (range(0, 6) as $atras) {
        $cerrado = in_array($atras, [1, 2, 3], true);

        RegistroDiario::factory()->for($usuario, 'usuario')->create([
            'fecha' => now()->subDays($atras)->toDateString(),
            'cerrado' => $cerrado,
            'cerrado_en' => $cerrado ? now()->subDays($atras) : null,
        ]);
    }

    $this->actingAs($usuario)->get(route('dashboard'))
        ->assertOk()
        ->assertViewHas('tendencia', fn ($tendencia) => $tendencia['suficiente'] === true && $tendencia['racha'] === 3)
        ->assertSee('Racha de días cerrados')
        ->assertSee('3 días');
});

/*
|--------------------------------------------------------------------------
| Bloque 4 — Tu seguimiento
|--------------------------------------------------------------------------
*/

it('Tu seguimiento: sin una semana completa desde el primer día, no muestra la tabla en blanco', function () {
    $usuario = usuarioParaDashboard();

    RegistroDiario::factory()->for($usuario, 'usuario')->create(['fecha' => now()->subDays(2)->toDateString()]);

    $this->actingAs($usuario)->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Todavía no hay una semana completa.')
        ->assertDontSee('Adherencia')
        ->assertDontSee('Historial');
});

it('resume el seguimiento semana a semana a partir de los planes diarios', function () {
    $usuario = usuarioParaDashboard();

    // Semana actual: 80.0 kg de media. Semana anterior: 81.0 kg.
    foreach (range(0, 6) as $dias) {
        RegistroDiario::factory()->for($usuario, 'usuario')->cerrado()->create([
            'fecha' => now()->subDays($dias)->toDateString(),
            'peso_kg' => 80.0,
            'deficit_diario' => 500,
        ]);
    }

    foreach (range(7, 13) as $dias) {
        RegistroDiario::factory()->for($usuario, 'usuario')->cerrado()->create([
            'fecha' => now()->subDays($dias)->toDateString(),
            'peso_kg' => 81.0,
            'deficit_diario' => 400,
        ]);
    }

    $this->actingAs($usuario)->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Tu seguimiento')
        ->assertSee('Adherencia')
        ->assertSee('80,00 kg')
        ->assertSee('81,00 kg')
        // Perdió 1 kg de media respecto de la semana anterior.
        ->assertSee('-1,00 kg');
});

it('muestra el historial de ajustes propuestos y su estado', function () {
    $usuario = usuarioParaDashboard();
    historialDeUnaSemana($usuario);

    $registroDiario = RegistroDiario::where('usuario_id', $usuario->id)
        ->whereDate('fecha', now()->subDays(3)->toDateString())
        ->firstOrFail();

    RecomendacionSistema::factory()->for($registroDiario, 'registroDiario')->create([
        'tipo' => 'ajuste_calorico',
        'justificacion' => 'Ajuste ya confirmado la semana pasada.',
        'estado' => 'confirmada',
    ]);

    $this->actingAs($usuario)->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Historial')
        ->assertSee('Ajuste ya confirmado la semana pasada.')
        ->assertSee('confirmada');
});

it('confirmar una recomendación desde el inicio vuelve al inicio', function () {
    $usuario = usuarioParaDashboard();
    $registroDiario = RegistroDiario::factory()->for($usuario, 'usuario')->create();

    $recomendacion = RecomendacionSistema::factory()->for($registroDiario, 'registroDiario')->create([
        'tipo' => 'ajuste_calorico',
        'calorias_objetivo_sugeridas' => 1950,
        'estado' => 'pendiente',
        'confirmada_en' => null,
    ]);

    $this->actingAs($usuario)
        ->from(route('dashboard'))
        ->post(route('recomendaciones.confirmar', $recomendacion))
        ->assertRedirect(route('dashboard'));

    expect($recomendacion->fresh()->estado)->toBe('confirmada');
});

it('no mezcla las recomendaciones o el resumen de otro usuario', function () {
    $usuario = usuarioParaDashboard();
    $otro = usuarioParaDashboard();

    $registroOtro = RegistroDiario::factory()->for($otro, 'usuario')->create(['fecha' => now()->toDateString()]);

    RecomendacionSistema::factory()->for($registroOtro, 'registroDiario')->create([
        'tipo' => 'ajuste_calorico',
        'justificacion' => 'Recomendación del otro usuario',
        'estado' => 'pendiente',
        'confirmada_en' => null,
    ]);

    $this->actingAs($usuario)->get(route('dashboard'))
        ->assertOk()
        ->assertDontSee('Recomendación del otro usuario');
});

it('redirige "Mi progreso" al inicio para no romper enlaces guardados', function () {
    $usuario = usuarioParaDashboard();

    $this->actingAs($usuario)->get(route('progreso.index'))->assertRedirect(route('dashboard'));
});

/*
|--------------------------------------------------------------------------
| Aviso de comidas sin reportar (CLAUDE.md sección 5.24)
|--------------------------------------------------------------------------
*/

it('avisa de las comidas que quedaron sin reportar ayer, con enlace para completarlas', function () {
    $usuario = usuarioParaDashboard();

    $ayer = RegistroDiario::factory()->for($usuario, 'usuario')->create([
        'fecha' => now()->subDay()->toDateString(),
    ]);

    // Desayuno reportado; almuerzo y cena no.
    $desayuno = PlanComida::factory()->for($ayer, 'registroDiario')->create(['tipo_comida' => 'desayuno']);
    ComidaReal::factory()->for($desayuno, 'planComida')->create();

    $this->actingAs($usuario)->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Ayer te quedó sin reportar:')
        ->assertSee('almuerzo, cena')
        ->assertSee(route('planes.show', $ayer));
});

it('no avisa de un día de ayer que no se usó en absoluto', function () {
    $usuario = usuarioParaDashboard();

    RegistroDiario::factory()->for($usuario, 'usuario')->create([
        'fecha' => now()->subDay()->toDateString(),
    ]);

    // Ninguna comida reportada no es un descuido: es un día que no se usó.
    $this->actingAs($usuario)->get(route('dashboard'))
        ->assertOk()
        ->assertViewHas('avisoAyer', null)
        ->assertDontSee('Ayer te quedó sin reportar:');
});

it('no avisa cuando ayer quedó reportado entero', function () {
    $usuario = usuarioParaDashboard();

    $ayer = RegistroDiario::factory()->for($usuario, 'usuario')->create([
        'fecha' => now()->subDay()->toDateString(),
    ]);

    foreach (['desayuno', 'almuerzo', 'cena'] as $tipoComida) {
        $plan = PlanComida::factory()->for($ayer, 'registroDiario')->create(['tipo_comida' => $tipoComida]);
        ComidaReal::factory()->for($plan, 'planComida')->create();
    }

    $this->actingAs($usuario)->get(route('dashboard'))
        ->assertOk()
        ->assertViewHas('avisoAyer', null);
});
