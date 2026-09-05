<?php

use App\Exceptions\DayAlreadyClosedException;
use App\Models\ActividadFisica;
use App\Models\ComidaReal;
use App\Models\PlanComida;
use App\Models\RegistroDiario;
use App\Models\User;
use App\Services\DailyClosureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// The service works on Eloquent models, so this file needs the database.
// tests/Pest.php only applies RefreshDatabase to the Feature suite.
uses(TestCase::class, RefreshDatabase::class);

/**
 * Profile behind every hand-checked expectation in this file:
 *   TMB                    = 80 kg * 22          = 1760 kcal
 *   calorias_mantenimiento = 1760 * 1.5          = 2640 kcal
 *   calorias_objetivo      = 2640 * (1 - 0.2)    = 2112 kcal
 *   proteina_objetivo_g    = 80 * 2.0            =  160 g
 */
function usuarioDeCierre(): User
{
    return User::factory()->create([
        'peso_kg' => 80,
        'estatura_m' => 1.75,
        'edad' => 35,
        'sexo' => 'masculino',
        'nivel_actividad' => 1.5,
        'tipo_deficit' => 'porcentaje',
        'valor_deficit' => 0.2,
        'proteina_factor' => 2.0,
        'grasa_factor' => 0.8,
    ]);
}

/**
 * A complete open day for $usuario:
 *   consumido : 600 + 800 + 550 = 1950 kcal, 45 + 60 + 40 = 145 g de proteína
 *   actividad : 400 * 0.85 = 340 kcal, 200 * 0.80 = 160 kcal -> 500 kcal
 */
function diaCompleto(User $usuario): RegistroDiario
{
    $registroDiario = RegistroDiario::factory()->for($usuario, 'usuario')->create([
        'fecha' => now()->toDateString(),
    ]);

    foreach ([
        ['desayuno', 500, 600, 45],
        ['almuerzo', 850, 800, 60],
        ['cena', 762, 550, 40],
    ] as [$tipoComida, $caloriasEstimadas, $caloriasReales, $proteinaReal]) {
        $planComida = PlanComida::factory()->for($registroDiario, 'registroDiario')->create([
            'tipo_comida' => $tipoComida,
            'calorias_estimadas' => $caloriasEstimadas,
        ]);

        ComidaReal::factory()->for($planComida, 'planComida')->create([
            'calorias_reales' => $caloriasReales,
            'proteina_g' => $proteinaReal,
        ]);
    }

    foreach ([
        ['caminata', 400, 0.85, 340],
        ['pesas', 200, 0.80, 160],
    ] as [$tipo, $caloriasDispositivo, $factor, $caloriasAjustadas]) {
        ActividadFisica::factory()->for($registroDiario, 'registroDiario')->create([
            'tipo' => $tipo,
            'calorias_dispositivo' => $caloriasDispositivo,
            'factor_correccion' => $factor,
            'calorias_ajustadas' => $caloriasAjustadas,
        ]);
    }

    return $registroDiario;
}

test('closing a day with complete data produces the five expected closure values', function () {
    $registroDiario = diaCompleto(usuarioDeCierre());

    $resumen = app(DailyClosureService::class)->cerrar($registroDiario);

    // Hand-checked:
    //   1. objetivo 2112 kcal (80*22*1.5*0.8) vs. consumidas 600+800+550 = 1950 kcal
    //   2. actividad ajustada 400*0.85 + 200*0.80 = 340 + 160 = 500 kcal
    //   3. deficit = 2112 - 1950 + 500 = 662 kcal
    //   4. proteína 45+60+40 = 145 g de 80*2.0 = 160 g -> 90.625 %
    //   5. sin recomendaciones: un solo día de historial no alcanza la ventana
    //      de 7 días que exige la sección 6
    expect($resumen['calorias_objetivo'])->toEqualWithDelta(2112.0, 0.01)
        ->and($resumen['calorias_consumidas'])->toEqualWithDelta(1950.0, 0.01)
        ->and($resumen['calorias_actividad_ajustada'])->toEqualWithDelta(500.0, 0.01)
        ->and($resumen['deficit_diario'])->toEqualWithDelta(662.0, 0.01)
        ->and($resumen['proteina_objetivo_g'])->toEqualWithDelta(160.0, 0.01)
        ->and($resumen['proteina_consumida_g'])->toEqualWithDelta(145.0, 0.01)
        ->and($resumen['cumplimiento_proteina_pct'])->toEqualWithDelta(90.625, 0.01)
        ->and($resumen['recomendaciones'])->toBeEmpty();

    // ...and the same five values are persisted on the RegistroDiario.
    $registroDiario->refresh();

    expect((float) $registroDiario->calorias_objetivo_dia)->toBe(2112.0)
        ->and((float) $registroDiario->calorias_consumidas)->toBe(1950.0)
        ->and((float) $registroDiario->calorias_actividad_ajustada)->toBe(500.0)
        ->and((float) $registroDiario->deficit_diario)->toBe(662.0)
        ->and((float) $registroDiario->proteina_objetivo_g)->toBe(160.0)
        ->and((float) $registroDiario->proteina_consumida_g)->toBe(145.0)
        ->and($registroDiario->cerrado)->toBeTrue()
        ->and($registroDiario->cerrado_en)->not->toBeNull();
});

test('closing an already closed day throws and does not duplicate or corrupt anything', function () {
    $registroDiario = diaCompleto(usuarioDeCierre());
    $cierre = app(DailyClosureService::class);

    $cierre->cerrar($registroDiario);

    $despuesDelPrimerCierre = $registroDiario->fresh()->only([
        'calorias_objetivo_dia',
        'calorias_consumidas',
        'calorias_actividad_ajustada',
        'deficit_diario',
        'proteina_objetivo_g',
        'proteina_consumida_g',
        'cerrado',
        'cerrado_en',
    ]);

    expect(fn () => $cierre->cerrar($registroDiario->fresh()))
        ->toThrow(DayAlreadyClosedException::class);

    // Same figures, same closing timestamp, and no extra rows anywhere.
    expect($registroDiario->fresh()->only(array_keys($despuesDelPrimerCierre)))
        ->toEqual($despuesDelPrimerCierre)
        ->and($registroDiario->recomendacionesSistema()->count())->toBe(0)
        ->and($registroDiario->planesComida()->count())->toBe(3)
        ->and($registroDiario->actividadesFisicas()->count())->toBe(2);
});

test('the summary of a closed day is the frozen snapshot, not a recomputation', function () {
    $usuario = usuarioDeCierre();
    $registroDiario = diaCompleto($usuario);
    $cierre = app(DailyClosureService::class);

    $cierre->cerrar($registroDiario);

    // The user changes their profile after the day was closed.
    $usuario->update(['peso_kg' => 100]);

    $resumen = $cierre->resumen($registroDiario->fresh());

    expect($resumen['calorias_objetivo'])->toEqualWithDelta(2112.0, 0.01)
        ->and($resumen['proteina_objetivo_g'])->toEqualWithDelta(160.0, 0.01);
});

test('reopening a day allows closing it again with recomputed values', function () {
    $usuario = usuarioDeCierre();
    $registroDiario = diaCompleto($usuario);
    $cierre = app(DailyClosureService::class);

    $cierre->cerrar($registroDiario);
    $cierre->reabrir($registroDiario->fresh());

    $registroDiario->refresh();

    expect($registroDiario->cerrado)->toBeFalse()
        ->and($registroDiario->cerrado_en)->toBeNull();

    // A meal that was missing gets logged, and the day is closed again.
    $planComida = PlanComida::factory()->for($registroDiario, 'registroDiario')->create([
        'tipo_comida' => 'snack',
        'calorias_estimadas' => 200,
    ]);

    ComidaReal::factory()->for($planComida, 'planComida')->create([
        'calorias_reales' => 200,
        'proteina_g' => 15,
    ]);

    $resumen = $cierre->cerrar($registroDiario->fresh());

    // consumidas 1950 + 200 = 2150; deficit 2112 - 2150 + 500 = 462; proteína 145 + 15 = 160
    expect($resumen['calorias_consumidas'])->toEqualWithDelta(2150.0, 0.01)
        ->and($resumen['deficit_diario'])->toEqualWithDelta(462.0, 0.01)
        ->and($resumen['proteina_consumida_g'])->toEqualWithDelta(160.0, 0.01)
        ->and($resumen['cumplimiento_proteina_pct'])->toEqualWithDelta(100.0, 0.01);
});

test('closing a day with a full 7-day trend of slow weight loss generates a pending ajuste_calorico recommendation', function () {
    $usuario = usuarioDeCierre();

    // 13 días previos con peso conocido: ventana anterior (días -13..-7) a 80.5 kg,
    // ventana actual (días -6..-1, sin contar hoy) a 80.3 kg.
    foreach (range(13, 7) as $diasAtras) {
        RegistroDiario::factory()->for($usuario, 'usuario')->create([
            'fecha' => now()->subDays($diasAtras)->toDateString(),
            'peso_kg' => 80.5,
        ]);
    }
    foreach (range(6, 1) as $diasAtras) {
        RegistroDiario::factory()->for($usuario, 'usuario')->create([
            'fecha' => now()->subDays($diasAtras)->toDateString(),
            'peso_kg' => 80.3,
        ]);
    }

    // Hoy: 80.2 kg. Promedio de la ventana actual (80.3*6 + 80.2)/7 ≈ 80.2857 vs.
    // 80.5 de la ventana anterior -> pérdida semanal ≈ 0.266%, por debajo del 0.5%
    // que exige la sección 6 para sugerir "reducir".
    $registroDiario = diaCompleto($usuario);
    $registroDiario->update(['peso_kg' => 80.2]);

    $resumen = app(DailyClosureService::class)->cerrar($registroDiario);

    expect($resumen['recomendaciones'])->toHaveCount(1);

    $recomendacion = $resumen['recomendaciones']->first();

    expect($recomendacion->tipo)->toBe('ajuste_calorico')
        ->and((float) $recomendacion->calorias_objetivo_sugeridas)->toEqualWithDelta(2112.0 - 150.0, 0.01)
        ->and($recomendacion->estado)->toBe('pendiente');
});

test('a day whose meals were never logged closes with zero consumption', function () {
    $registroDiario = RegistroDiario::factory()->for(usuarioDeCierre(), 'usuario')->create([
        'fecha' => now()->toDateString(),
    ]);

    $resumen = app(DailyClosureService::class)->cerrar($registroDiario);

    expect($resumen['calorias_consumidas'])->toBe(0.0)
        ->and($resumen['calorias_actividad_ajustada'])->toBe(0.0)
        ->and($resumen['proteina_consumida_g'])->toBe(0.0)
        ->and($resumen['cumplimiento_proteina_pct'])->toBe(0.0)
        ->and($resumen['deficit_diario'])->toEqualWithDelta(2112.0, 0.01);
});
