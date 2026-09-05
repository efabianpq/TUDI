<?php

use App\Models\ActividadFisica;
use App\Models\ComidaReal;
use App\Models\MetricaTendencia;
use App\Models\PlanComida;
use App\Models\RegistroDiario;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;

function usuarioParaComando(): User
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

function diaAbiertoDeAyer(User $usuario): RegistroDiario
{
    $registroDiario = RegistroDiario::factory()->for($usuario, 'usuario')->create([
        'fecha' => now()->subDay()->toDateString(),
        'cerrado' => false,
        'cerrado_en' => null,
    ]);

    $planComida = PlanComida::factory()->for($registroDiario, 'registroDiario')->create([
        'tipo_comida' => 'desayuno',
        'calorias_estimadas' => 500,
    ]);

    ComidaReal::factory()->for($planComida, 'planComida')->create([
        'calorias_reales' => 600,
        'proteina_g' => 45,
    ]);

    ActividadFisica::factory()->for($registroDiario, 'registroDiario')->create([
        'tipo' => 'caminata',
        'calorias_dispositivo' => 400,
        'factor_correccion' => 0.85,
        'calorias_ajustadas' => 340,
    ]);

    return $registroDiario;
}

test('RunDailyClosure cierra los registros diarios abiertos de ayer', function () {
    $usuario = usuarioParaComando();
    $registroDiario = diaAbiertoDeAyer($usuario);

    Artisan::call('app:run-daily-closure');

    expect($registroDiario->refresh()->cerrado)->toBeTrue()
        ->and($registroDiario->cerrado_en)->not->toBeNull()
        ->and((float) $registroDiario->calorias_consumidas)->toBe(600.0);
});

test('RunDailyClosure no toca los registros de ayer que ya estaban cerrados', function () {
    $usuario = usuarioParaComando();
    $registroDiario = diaAbiertoDeAyer($usuario);
    $registroDiario->update(['cerrado' => true, 'cerrado_en' => now()->subHours(2)]);
    $cerradoEnOriginal = $registroDiario->cerrado_en;

    Artisan::call('app:run-daily-closure');

    expect($registroDiario->refresh()->cerrado_en->toDateTimeString())
        ->toBe($cerradoEnOriginal->toDateTimeString());
});

test('RunDailyClosure ignora registros de hoy y de otros usuarios', function () {
    $usuario = usuarioParaComando();
    RegistroDiario::factory()->for($usuario, 'usuario')->create([
        'fecha' => now()->toDateString(),
        'cerrado' => false,
    ]);

    Artisan::call('app:run-daily-closure');

    expect(RegistroDiario::where('cerrado', true)->count())->toBe(0);
});

test('CalculateTrends crea una MetricaTendencia por usuario', function () {
    $usuario1 = usuarioParaComando();
    $usuario2 = usuarioParaComando();

    RegistroDiario::factory()->for($usuario1, 'usuario')->create([
        'fecha' => now()->toDateString(),
        'peso_kg' => 79.5,
    ]);

    Artisan::call('app:calculate-trends');

    expect(MetricaTendencia::where('usuario_id', $usuario1->id)->count())->toBe(1)
        ->and(MetricaTendencia::where('usuario_id', $usuario2->id)->count())->toBe(1)
        ->and(MetricaTendencia::where('usuario_id', $usuario1->id)->first()->fecha->toDateString())
        ->toBe(now()->toDateString());
});

test('CalculateTrends recalcula en sitio sin duplicar la fila del mismo día', function () {
    $usuario = usuarioParaComando();
    RegistroDiario::factory()->for($usuario, 'usuario')->create([
        'fecha' => now()->toDateString(),
        'peso_kg' => 79.5,
    ]);

    Artisan::call('app:calculate-trends');
    Artisan::call('app:calculate-trends');

    expect(MetricaTendencia::where('usuario_id', $usuario->id)->count())->toBe(1);
});
