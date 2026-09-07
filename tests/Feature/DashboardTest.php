<?php

use App\Models\ActividadFisica;
use App\Models\ComidaReal;
use App\Models\PlanComida;
use App\Models\RecomendacionSistema;
use App\Models\RegistroDiario;
use App\Models\User;

/**
 * Inicio: la pantalla que fusiona el antiguo dashboard con "Mi progreso"
 * (CLAUDE.md sección 4.17). Tres alturas de mirada: hoy, la tendencia de 7
 * días, y el seguimiento semanal con el historial de ajustes.
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

it('exige autenticación para ver el inicio', function () {
    $this->get(route('dashboard'))->assertRedirect(route('login'));
});

it('muestra el resumen de hoy, el estado de las comidas, las tendencias y las recomendaciones pendientes', function () {
    $usuario = usuarioParaDashboard();

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

    $recomendacion = RecomendacionSistema::factory()->for($registroDiario, 'registroDiario')->create([
        'tipo' => 'ajuste_calorico',
        'calorias_objetivo_sugeridas' => 1950,
        'justificacion' => 'Pérdida semanal por debajo del 0.5%: se sugiere reducir el objetivo.',
        'estado' => 'pendiente',
        'confirmada_en' => null,
    ]);

    $respuesta = $this->actingAs($usuario)->get(route('dashboard'));

    // objetivo 2112 · consumidas 600 · actividad 340 · déficit 2112-600+340 = 1852
    $respuesta->assertOk()
        ->assertSee('2.112')   // calorías objetivo
        ->assertSee('600')     // calorías consumidas
        ->assertSee('340')     // gasto por actividad ajustado
        ->assertSee('1.852')   // déficit estimado
        ->assertSee('desayuno')
        ->assertSee('almuerzo')
        ->assertSee('registrada')
        ->assertSee('planificada')
        ->assertSee('pendiente')
        ->assertSee('Abrir el plan de hoy')
        ->assertSee($recomendacion->justificacion)
        ->assertSee('grafico-peso')
        ->assertSee('chart.js', false);
});

it('absorbe lo que antes era "Mi progreso": promedios móviles, consistencia y gráfico', function () {
    $usuario = usuarioParaDashboard();

    // Siete días cerrados con peso y déficit conocidos.
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
        ->assertSee('Peso (promedio móvil 7 días)')
        ->assertSee('80,00 kg')
        ->assertSee('Déficit promedio (7 días)')
        ->assertSee('Índice de consistencia')
        ->assertSee('100%')
        ->assertSee('7 de 7 días cerrados');
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
    $registroDiario = RegistroDiario::factory()->for($usuario, 'usuario')->create([
        'fecha' => now()->subDays(3)->toDateString(),
    ]);

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

it('muestra un aviso cuando faltan parámetros nutricionales en vez de fallar con un 500', function () {
    $usuario = usuarioParaDashboard(['proteina_factor' => null]);
    RegistroDiario::factory()->for($usuario, 'usuario')->create(['fecha' => now()->toDateString()]);

    $this->actingAs($usuario)->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Completa tus parámetros nutricionales para ver el resumen de hoy.');
});

it('no falla para un usuario sin nada registrado hoy', function () {
    $usuario = usuarioParaDashboard();

    $this->actingAs($usuario)->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Todavía no has creado el plan de hoy.')
        ->assertSee('No tienes recomendaciones pendientes.');
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
