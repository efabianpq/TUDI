<?php

use App\Exceptions\RecomendacionYaProcesadaException;
use App\Models\RegistroDiario;
use App\Models\User;
use App\Services\RulesEngineService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// El servicio toca Eloquent (RecomendacionSistema, User), así que este archivo
// necesita la base de datos explícitamente, igual que DailyClosureServiceTest.
uses(TestCase::class, RefreshDatabase::class);

function registroParaRecomendacion(float $caloriasObjetivo = 2000.0): RegistroDiario
{
    $usuario = User::factory()->create(['calorias_objetivo' => $caloriasObjetivo]);

    return RegistroDiario::factory()->for($usuario, 'usuario')->create();
}

it('sugiere reducir el objetivo calórico cuando la pérdida semanal es menor a 0.5%', function () {
    $registro = registroParaRecomendacion(2000.0);

    $recomendacion = app(RulesEngineService::class)->generarRecomendacionAjusteCalorico($registro, 0.3);

    expect($recomendacion)->not->toBeNull()
        ->and($recomendacion->tipo)->toBe('ajuste_calorico')
        ->and($recomendacion->estado)->toBe('pendiente')
        ->and((float) $recomendacion->calorias_objetivo_sugeridas)->toBeLessThan(2000.0);
});

it('sugiere aumentar el objetivo calórico cuando la pérdida semanal es mayor a 1%', function () {
    $registro = registroParaRecomendacion(2000.0);

    $recomendacion = app(RulesEngineService::class)->generarRecomendacionAjusteCalorico($registro, 1.5);

    expect($recomendacion)->not->toBeNull()
        ->and($recomendacion->tipo)->toBe('ajuste_calorico')
        ->and($recomendacion->estado)->toBe('pendiente')
        ->and((float) $recomendacion->calorias_objetivo_sugeridas)->toBeGreaterThan(2000.0);
});

it('no genera recomendación cuando la pérdida semanal está entre 0.5% y 1%', function () {
    $registro = registroParaRecomendacion(2000.0);

    $recomendacion = app(RulesEngineService::class)->generarRecomendacionAjusteCalorico($registro, 0.75);

    expect($recomendacion)->toBeNull()
        ->and($registro->recomendacionesSistema()->count())->toBe(0);
});

it('confirmar una recomendación de ajuste calórico actualiza calorias_objetivo del usuario', function () {
    $registro = registroParaRecomendacion(2000.0);
    $motor = app(RulesEngineService::class);
    $recomendacion = $motor->generarRecomendacionAjusteCalorico($registro, 0.3);
    $caloriasSugeridas = (float) $recomendacion->calorias_objetivo_sugeridas;

    $motor->confirmar($recomendacion);

    expect((float) $registro->usuario->fresh()->calorias_objetivo)->toEqualWithDelta($caloriasSugeridas, 0.01)
        ->and($recomendacion->fresh()->estado)->toBe('confirmada')
        ->and($recomendacion->fresh()->confirmada_en)->not->toBeNull();
});

it('rechazar una recomendación no modifica calorias_objetivo del usuario', function () {
    $registro = registroParaRecomendacion(2000.0);
    $motor = app(RulesEngineService::class);
    $recomendacion = $motor->generarRecomendacionAjusteCalorico($registro, 0.3);

    $motor->rechazar($recomendacion);

    expect((float) $registro->usuario->fresh()->calorias_objetivo)->toEqualWithDelta(2000.0, 0.01)
        ->and($recomendacion->fresh()->estado)->toBe('rechazada');
});

it('no permite confirmar dos veces la misma recomendación', function () {
    $registro = registroParaRecomendacion(2000.0);
    $motor = app(RulesEngineService::class);
    $recomendacion = $motor->generarRecomendacionAjusteCalorico($registro, 0.3);
    $motor->confirmar($recomendacion);

    expect(fn () => $motor->confirmar($recomendacion))->toThrow(RecomendacionYaProcesadaException::class);
});

it('detecta estancamiento tras varias semanas consecutivas con variación de peso mínima', function () {
    $registro = registroParaRecomendacion();
    $motor = app(RulesEngineService::class);

    $recomendacion = $motor->detectarEstancamiento($registro, [0.1, -0.1, 0.05]);

    expect($recomendacion)->not->toBeNull()
        ->and($recomendacion->tipo)->toBe('alerta_estancamiento')
        ->and($recomendacion->calorias_objetivo_sugeridas)->toBeNull()
        ->and($recomendacion->estado)->toBe('pendiente');
});

it('no detecta estancamiento si alguna semana reciente tuvo variación significativa', function () {
    $registro = registroParaRecomendacion();
    $motor = app(RulesEngineService::class);

    $recomendacion = $motor->detectarEstancamiento($registro, [0.1, -0.1, 0.8]);

    expect($recomendacion)->toBeNull();
});

it('no detecta estancamiento con menos semanas que el mínimo requerido', function () {
    $registro = registroParaRecomendacion();
    $motor = app(RulesEngineService::class);

    $recomendacion = $motor->detectarEstancamiento($registro, [0.05, 0.05]);

    expect($recomendacion)->toBeNull();
});
