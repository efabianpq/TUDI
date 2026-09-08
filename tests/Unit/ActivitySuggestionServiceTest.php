<?php

use App\Models\User;
use App\Services\ActivityCorrectionService;
use App\Services\ActivitySuggestionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// El usuario sigue sin persistirse (`make()`), pero el servicio lee ahora los
// parámetros maestros ajustables desde la consola (CLAUDE.md sección 4.27) y
// esa tabla tiene que existir.
uses(TestCase::class, RefreshDatabase::class);

function usuarioPara(float $pesoKg = 80.0, float $nivelActividad = 1.5): User
{
    return User::factory()->make([
        'peso_kg' => $pesoKg,
        'nivel_actividad' => $nivelActividad,
    ]);
}

function servicioDeSugerencias(): ActivitySuggestionService
{
    return app(ActivitySuggestionService::class);
}

it('deriva el objetivo de actividad del déficit del propio usuario', function () {
    // Mantenimiento = 80 * 22 * 1.5 = 2640 kcal. Con un objetivo de 2112 kcal
    // (20% de déficit) el hueco es 528 kcal, y el 40% de ese hueco son 211.2.
    $resultado = servicioDeSugerencias()->sugerir(usuarioPara(), 2112.0);

    expect($resultado['deficit_dieta_kcal'])->toEqualWithDelta(528.0, 0.01)
        ->and($resultado['calorias_objetivo_actividad'])->toEqualWithDelta(211.2, 0.01);
});

it('acota el objetivo entre el suelo y el techo definidos', function () {
    $servicio = servicioDeSugerencias();

    // Déficit minúsculo (objetivo casi igual al mantenimiento): sube al suelo.
    $conDeficitPequeno = $servicio->sugerir(usuarioPara(), 2630.0);

    // Déficit enorme: se recorta al techo en vez de pedir una jornada de gimnasio.
    $conDeficitEnorme = $servicio->sugerir(usuarioPara(), 500.0);

    expect($conDeficitPequeno['calorias_objetivo_actividad'])
        ->toBe(ActivitySuggestionService::OBJETIVO_MINIMO_KCAL)
        ->and($conDeficitEnorme['calorias_objetivo_actividad'])
        ->toBe(ActivitySuggestionService::OBJETIVO_MAXIMO_KCAL);
});

it('nunca propone un objetivo negativo cuando el objetivo calórico supera al mantenimiento', function () {
    // Un objetivo confirmado por encima del mantenimiento (sección 4.10) no debe
    // producir un déficit negativo ni un objetivo de actividad absurdo.
    $resultado = servicioDeSugerencias()->sugerir(usuarioPara(), 3200.0);

    expect($resultado['deficit_dieta_kcal'])->toBe(0.0)
        ->and($resultado['calorias_objetivo_actividad'])->toBe(ActivitySuggestionService::OBJETIVO_MINIMO_KCAL);
});

it('calcula la duración de cada actividad con la fórmula MET estándar', function () {
    $resultado = servicioDeSugerencias()->sugerir(usuarioPara(), 2112.0);

    $trote = collect($resultado['sugerencias'])->firstWhere('tipo', 'trote');

    // kcal/min = MET * 3.5 * peso / 200 = 8.0 * 3.5 * 80 / 200 = 11.2
    // 211.2 kcal / 11.2 = 18.86 min → se redondea hacia arriba a 19.
    expect($trote['duracion_min'])->toBe(19)
        ->and($trote['calorias_estimadas'])->toEqualWithDelta(19 * 11.2, 0.01)
        ->and($trote['alcanza_objetivo'])->toBeTrue();
});

it('recorta las actividades poco intensas al tope de duración e informa lo que sí queman', function () {
    // Objetivo al techo (600 kcal): caminar quema 3.5*3.5*80/200 = 4.9 kcal/min,
    // así que necesitaría 123 minutos — más del tope de 90.
    $resultado = servicioDeSugerencias()->sugerir(usuarioPara(), 500.0);

    $caminata = collect($resultado['sugerencias'])->firstWhere('tipo', 'caminata');

    expect($caminata['duracion_min'])->toBe(ActivitySuggestionService::DURACION_MAXIMA_MIN)
        ->and($caminata['alcanza_objetivo'])->toBeFalse()
        // Las calorías informadas son las del tiempo recortado, no las del objetivo.
        ->and($caminata['calorias_estimadas'])->toEqualWithDelta(90 * 4.9, 0.01)
        ->and($caminata['calorias_estimadas'])->toBeLessThan($resultado['calorias_objetivo_actividad']);
});

it('propone tipos de actividad que el registro sabe corregir', function () {
    $resultado = servicioDeSugerencias()->sugerir(usuarioPara(), 2112.0);

    foreach ($resultado['sugerencias'] as $sugerencia) {
        expect(ActivityCorrectionService::FACTORES_POR_TIPO)->toHaveKey($sugerencia['tipo'])
            ->and($sugerencia['factor_correccion'])
            ->toBe(ActivityCorrectionService::FACTORES_POR_TIPO[$sugerencia['tipo']]);
    }

    expect($resultado['sugerencias'])->toHaveCount(count(ActivitySuggestionService::MET_POR_TIPO));
});

it('escala la duración con el peso: alguien más pesado quema lo mismo en menos tiempo', function () {
    $servicio = servicioDeSugerencias();

    // Un objetivo calórico muy bajo lleva a ambos usuarios al techo de 600 kcal,
    // así que la única variable que queda es el peso.
    $ligero = collect($servicio->sugerir(usuarioPara(60.0), 200.0)['sugerencias'])->firstWhere('tipo', 'trote');
    $pesado = collect($servicio->sugerir(usuarioPara(100.0), 200.0)['sugerencias'])->firstWhere('tipo', 'trote');

    // 600 / (8.0*3.5*60/200) = 71.4 → 72 min contra 600 / 14 = 42.9 → 43 min.
    expect($ligero['duracion_min'])->toBe(72)
        ->and($pesado['duracion_min'])->toBe(43);
});
