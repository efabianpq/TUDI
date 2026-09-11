<?php

use App\Models\ComidaReal;
use App\Models\PlanComida;
use App\Models\RegistroDiario;
use App\Models\User;
use App\Services\ComidasFrecuentesService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "Tu desayuno habitual" (CLAUDE.md sección 5.22): detectar lo que el usuario
 * repite para ofrecérselo como atajo, y ahorrarle la llamada al proveedor que
 * costaría volver a estimar los mismos macros.
 */
uses(TestCase::class, RefreshDatabase::class);

/**
 * Un reporte de $tipoComida hace $diasAtras días, con la nota que lo identifica.
 */
function reportar(User $usuario, string $tipoComida, string $notas, int $diasAtras, float $calorias = 420): ComidaReal
{
    $dia = RegistroDiario::factory()->for($usuario, 'usuario')->create([
        'fecha' => now()->subDays($diasAtras)->toDateString(),
    ]);

    $plan = PlanComida::factory()->for($dia, 'registroDiario')->create([
        'tipo_comida' => $tipoComida,
        'descripcion' => 'Plan de '.$tipoComida,
    ]);

    return ComidaReal::factory()->for($plan, 'planComida')->create([
        'calorias_reales' => $calorias,
        'proteina_g' => 25,
        'grasa_g' => 12,
        'carbohidratos_g' => 40,
        'notas' => $notas,
        'consumido_en' => now()->subDays($diasAtras),
    ]);
}

it('no ofrece nada sin historial', function () {
    expect(app(ComidasFrecuentesService::class)->paraComida(User::factory()->create(), 'desayuno'))
        ->toBeEmpty();
});

it('no ofrece una comida que solo se hizo una vez', function () {
    $usuario = User::factory()->create();
    reportar($usuario, 'desayuno', 'arepa con huevo', 2);

    expect(app(ComidasFrecuentesService::class)->paraComida($usuario, 'desayuno'))->toBeEmpty();
});

it('ofrece la comida que se repite, con cuántas veces y los macros del último reporte', function () {
    $usuario = User::factory()->create();

    reportar($usuario, 'desayuno', 'arepa con huevo', 5, 400);
    reportar($usuario, 'desayuno', 'arepa con huevo', 3, 400);
    $ultima = reportar($usuario, 'desayuno', 'arepa con huevo', 1, 430);

    $frecuentes = app(ComidasFrecuentesService::class)->paraComida($usuario, 'desayuno');

    expect($frecuentes)->toHaveCount(1)
        ->and($frecuentes[0]['veces'])->toBe(3)
        ->and($frecuentes[0]['etiqueta'])->toBe('arepa con huevo')
        // El más reciente es el que se copia al repetirla.
        ->and($frecuentes[0]['comida_real_id'])->toBe($ultima->id)
        ->and($frecuentes[0]['calorias'])->toBe(430.0);
});

it('agrupa las variantes de escritura como la misma comida', function () {
    $usuario = User::factory()->create();

    reportar($usuario, 'desayuno', 'Arepa con huevo.', 4);
    reportar($usuario, 'desayuno', 'arepa con   huevo', 2);

    expect(app(ComidasFrecuentesService::class)->paraComida($usuario, 'desayuno')[0]['veces'])->toBe(2);
});

it('ignora el detalle que el modelo añade detrás del guion', function () {
    $usuario = User::factory()->create();

    reportar($usuario, 'desayuno', 'arepa con huevo — Arepa de maíz y huevo frito', 4);
    reportar($usuario, 'desayuno', 'arepa con huevo — Arepa asada con huevo revuelto', 2);

    expect(app(ComidasFrecuentesService::class)->paraComida($usuario, 'desayuno'))->toHaveCount(1);
});

it('no mezcla comidas de distinto tipo', function () {
    $usuario = User::factory()->create();

    reportar($usuario, 'desayuno', 'arepa con huevo', 4);
    reportar($usuario, 'almuerzo', 'arepa con huevo', 2);

    expect(app(ComidasFrecuentesService::class)->paraComida($usuario, 'desayuno'))->toBeEmpty()
        ->and(app(ComidasFrecuentesService::class)->paraComida($usuario, 'almuerzo'))->toBeEmpty();
});

it('no mira más atrás de la ventana de historial', function () {
    $usuario = User::factory()->create();

    reportar($usuario, 'desayuno', 'arepa con huevo', ComidasFrecuentesService::DIAS_HISTORIAL + 5);
    reportar($usuario, 'desayuno', 'arepa con huevo', ComidasFrecuentesService::DIAS_HISTORIAL + 3);
    reportar($usuario, 'desayuno', 'arepa con huevo', 2);

    expect(app(ComidasFrecuentesService::class)->paraComida($usuario, 'desayuno'))->toBeEmpty();
});

it('ordena por lo más repetido y ofrece como mucho tres', function () {
    $usuario = User::factory()->create();

    foreach (['a', 'b', 'c', 'd'] as $indice => $nombre) {
        // "a" se repite 5 veces, "b" 4, "c" 3 y "d" 2.
        foreach (range(1, 5 - $indice) as $vez) {
            reportar($usuario, 'desayuno', $nombre, $indice * 6 + $vez);
        }
    }

    $frecuentes = app(ComidasFrecuentesService::class)->paraComida($usuario, 'desayuno');

    expect($frecuentes)->toHaveCount(ComidasFrecuentesService::MAXIMO_SUGERENCIAS)
        ->and($frecuentes->pluck('etiqueta')->all())->toBe(['a', 'b', 'c']);
});

it('usa la descripción del plan cuando la comida se cerró con "cumplí lo sugerido"', function () {
    $usuario = User::factory()->create();

    reportar($usuario, 'desayuno', 'Cumplí con lo sugerido.', 4);
    reportar($usuario, 'desayuno', 'Cumplí con lo sugerido.', 2);

    expect(app(ComidasFrecuentesService::class)->paraComida($usuario, 'desayuno')[0]['etiqueta'])
        ->toBe('Plan de desayuno');
});

it('solo devuelve un reporte si es del propio usuario', function () {
    $usuario = User::factory()->create();
    $otro = User::factory()->create();

    $suya = reportar($usuario, 'desayuno', 'arepa con huevo', 2);
    $ajena = reportar($otro, 'desayuno', 'arepa con huevo', 2);

    $servicio = app(ComidasFrecuentesService::class);

    expect($servicio->deUsuario($usuario, $suya->id))->not->toBeNull()
        ->and($servicio->deUsuario($usuario, $ajena->id))->toBeNull();
});
