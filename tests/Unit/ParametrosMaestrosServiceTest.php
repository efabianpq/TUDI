<?php

use App\Models\ParametroMaestro;
use App\Services\ActivitySuggestionService;
use App\Services\ParametrosMaestrosService;
use App\Services\RulesEngineService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// El servicio toca Eloquent y la caché, así que necesita base de datos.
uses(TestCase::class, RefreshDatabase::class);

it('devuelve el valor de fábrica de cada parámetro cuando la tabla está vacía', function () {
    $servicio = app(ParametrosMaestrosService::class);

    // Los valores de fábrica son las constantes de los servicios que los
    // consumen, no una copia: el catálogo las referencia (sección 4.27).
    expect($servicio->valor('recomendaciones_umbral_perdida_lenta_pct'))
        ->toBe(RulesEngineService::UMBRAL_PERDIDA_LENTA_PCT)
        ->and($servicio->valor('recomendaciones_semanas_estancamiento'))
        ->toBe(RulesEngineService::SEMANAS_ESTANCAMIENTO)
        ->and($servicio->valor('actividad_duracion_maxima_min'))
        ->toBe(ActivitySuggestionService::DURACION_MAXIMA_MIN)
        ->and($servicio->todos())->toHaveCount(count(ParametrosMaestrosService::CATALOGO));
});

it('guarda solo la clave que cambia y la lee ya convertida a su tipo', function () {
    $servicio = app(ParametrosMaestrosService::class);

    $servicio->guardar(['recomendaciones_semanas_estancamiento' => '4']);

    expect(ParametroMaestro::count())->toBe(1)
        ->and($servicio->valor('recomendaciones_semanas_estancamiento'))->toBe(4)
        // Las demás siguen en su valor de fábrica.
        ->and($servicio->valor('recomendaciones_ajuste_kcal'))->toBe(RulesEngineService::AJUSTE_KCAL_SUGERIDO);
});

it('invalida la caché al guardar, para que el cambio surta efecto de inmediato', function () {
    $servicio = app(ParametrosMaestrosService::class);

    // Primera lectura: llena la caché con el valor de fábrica.
    expect($servicio->valor('recomendaciones_ajuste_kcal'))->toBe(150.0);

    $servicio->guardar(['recomendaciones_ajuste_kcal' => 200]);

    expect($servicio->valor('recomendaciones_ajuste_kcal'))->toBe(200.0);
});

it('acepta decimales con coma, como los teclea un hispanohablante', function () {
    $servicio = app(ParametrosMaestrosService::class);

    $servicio->guardar(['actividad_proporcion_del_deficit' => '0,55']);

    expect($servicio->valor('actividad_proporcion_del_deficit'))->toBe(0.55);
});

it('rechaza un valor fuera del rango declarado, sin persistir nada', function () {
    $servicio = app(ParametrosMaestrosService::class);

    expect(fn () => $servicio->guardar(['recomendaciones_ajuste_kcal' => 5000]))
        ->toThrow(InvalidArgumentException::class, 'debe estar entre');

    expect(ParametroMaestro::count())->toBe(0);
});

it('rechaza una clave que no está en el catálogo', function () {
    $servicio = app(ParametrosMaestrosService::class);

    expect(fn () => $servicio->valor('inventada'))
        ->toThrow(InvalidArgumentException::class, 'desconocido');

    expect(fn () => $servicio->guardar(['inventada' => 1]))
        ->toThrow(InvalidArgumentException::class, 'desconocido');
});

it('restablecer devuelve todo a los valores de fábrica', function () {
    $servicio = app(ParametrosMaestrosService::class);

    $servicio->guardar(['recomendaciones_ajuste_kcal' => 200]);
    $servicio->restablecer();

    expect(ParametroMaestro::count())->toBe(0)
        ->and($servicio->valor('recomendaciones_ajuste_kcal'))->toBe(RulesEngineService::AJUSTE_KCAL_SUGERIDO);
});

it('el motor de reglas aplica el umbral ajustado, no la constante', function () {
    $motor = app(RulesEngineService::class);

    // Con el umbral de fábrica (0.5%), una pérdida del 0.6% está dentro del
    // rango esperado y no genera nada.
    expect($motor->evaluarTendenciaPeso(0.6))->toBeNull();

    app(ParametrosMaestrosService::class)->guardar(['recomendaciones_umbral_perdida_lenta_pct' => 0.8]);

    // Subido el umbral a 0.8%, esa misma pérdida pasa a ser "lenta".
    expect($motor->evaluarTendenciaPeso(0.6))->toBe('reducir');
});
