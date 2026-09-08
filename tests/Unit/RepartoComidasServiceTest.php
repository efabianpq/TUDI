<?php

use App\Models\RegistroDiario;
use App\Models\User;
use App\Services\MealPlanGeneratorService;
use App\Services\RepartoComidasService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// El servicio persiste sobre Eloquent, así que este archivo activa TestCase +
// RefreshDatabase explícitamente (tests/Pest.php solo los aplica a Feature).
uses(TestCase::class, RefreshDatabase::class);

/**
 * Reparto de calorías entre comidas (CLAUDE.md sección 5.14): el día manda
 * sobre el habitual del usuario, y el habitual sobre el 25/40/35 de fábrica.
 */
function servicioDeReparto(): RepartoComidasService
{
    return app(RepartoComidasService::class);
}

function diaDeReparto(array $atributosDelDia = [], array $atributosDelUsuario = []): RegistroDiario
{
    $usuario = User::factory()->create($atributosDelUsuario);

    return RegistroDiario::factory()->for($usuario, 'usuario')->create($atributosDelDia);
}

it('cae al reparto de fábrica cuando ni el día ni el usuario tienen uno propio', function () {
    $dia = diaDeReparto();

    expect(servicioDeReparto()->paraElDia($dia))
        ->toBe(MealPlanGeneratorService::DISTRIBUCION_COMIDAS);
});

it('usa el reparto habitual del usuario cuando el día no tiene uno propio', function () {
    $habitual = ['desayuno' => 0.30, 'almuerzo' => 0.30, 'cena' => 0.40];

    $dia = diaDeReparto([], ['reparto_comidas' => $habitual]);

    expect(servicioDeReparto()->paraElDia($dia))->toBe($habitual);
});

it('el reparto del día manda sobre el habitual del usuario', function () {
    $dia = diaDeReparto(
        ['reparto_comidas' => ['desayuno' => 0.10, 'almuerzo' => 0.50, 'cena' => 0.40]],
        ['reparto_comidas' => ['desayuno' => 0.30, 'almuerzo' => 0.30, 'cena' => 0.40]],
    );

    expect(servicioDeReparto()->paraElDia($dia)['desayuno'])->toBe(0.10);
});

it('guardar el reparto de fábrica limpia la columna en vez de persistirlo', function () {
    $dia = diaDeReparto(['reparto_comidas' => ['desayuno' => 0.10, 'almuerzo' => 0.50, 'cena' => 0.40]]);

    servicioDeReparto()->guardar($dia, ['desayuno' => 25, 'almuerzo' => 40, 'cena' => 35]);

    // Así un cambio futuro del valor de fábrica alcanza a quien nunca lo tocó.
    expect($dia->fresh()->reparto_comidas)->toBeNull();
});

it('guarda el reparto solo en el día si no se pide adoptarlo como habitual', function () {
    $dia = diaDeReparto();

    servicioDeReparto()->guardar($dia, ['desayuno' => 20, 'almuerzo' => 45, 'cena' => 35]);

    expect($dia->fresh()->reparto_comidas['desayuno'])->toBe(0.2)
        ->and($dia->usuario->fresh()->reparto_comidas)->toBeNull();
});

it('adopta el reparto como habitual del usuario cuando se pide', function () {
    $dia = diaDeReparto();

    servicioDeReparto()->guardar($dia, ['desayuno' => 20, 'almuerzo' => 45, 'cena' => 35], comoHabitual: true);

    expect($dia->usuario->fresh()->reparto_comidas)
        ->toBe(['desayuno' => 0.2, 'almuerzo' => 0.45, 'cena' => 0.35]);
});

it('rechaza un reparto que no suma 100', function () {
    servicioDeReparto()->desdePorcentajes(['desayuno' => 25, 'almuerzo' => 40, 'cena' => 40]);
})->throws(InvalidArgumentException::class, 'sumar 100%');

it('rechaza una comida por debajo del mínimo', function () {
    servicioDeReparto()->desdePorcentajes(['desayuno' => 2, 'almuerzo' => 58, 'cena' => 40]);
})->throws(InvalidArgumentException::class, 'al menos el 5%');

it('rechaza un reparto al que le falta una comida', function () {
    servicioDeReparto()->desdePorcentajes(['desayuno' => 30, 'almuerzo' => 70]);
})->throws(InvalidArgumentException::class, 'Falta el porcentaje del cena');

it('ignora un reparto persistido que no suma 1.0 y cae al siguiente escalón', function () {
    // Una fila manipulada a mano no puede desdibujar el objetivo del día.
    $dia = diaDeReparto(['reparto_comidas' => ['desayuno' => 0.5, 'almuerzo' => 0.5, 'cena' => 0.5]]);

    expect(servicioDeReparto()->paraElDia($dia))
        ->toBe(MealPlanGeneratorService::DISTRIBUCION_COMIDAS);
});
