<?php

use App\Exceptions\MealDistributionUnavailableException;
use App\Services\AI\ClaudeMealDistributionProvider;
use App\Services\AI\MealDistributionProviderInterface;
use App\Services\MealPlanGeneratorService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

// Sin base de datos: el proveedor solo habla HTTP. Necesita el contenedor para
// `config()` y el facade `Http`, de ahí el TestCase explícito.
uses(TestCase::class);

beforeEach(function () {
    config([
        'services.anthropic.key' => 'clave-de-prueba',
        'services.anthropic.model' => 'claude-haiku-4-5',
        'services.anthropic.endpoint' => 'https://api.anthropic.com/v1/messages',
    ]);
});

/**
 * Respuesta con la forma real de la Messages API: el JSON del esquema viaja
 * dentro del primer bloque de contenido de tipo `text`.
 */
function respuestaDeClaude(array $cuerpo, string $stopReason = 'end_turn'): array
{
    return [
        'id' => 'msg_01',
        'type' => 'message',
        'role' => 'assistant',
        'model' => 'claude-haiku-4-5',
        'stop_reason' => $stopReason,
        'content' => [
            ['type' => 'text', 'text' => json_encode($cuerpo)],
        ],
        'usage' => ['input_tokens' => 500, 'output_tokens' => 200],
    ];
}

/**
 * El cuerpo del esquema: una entrada por comida resuelta.
 */
function comidasDeEjemplo(array $tipos = ['desayuno']): array
{
    return ['comidas' => array_map(fn (string $tipo): array => [
        'tipo_comida' => $tipo,
        'descripcion' => "Tostada de palta con huevos revueltos ({$tipo})",
        'preparacion' => 'Revuelve los huevos y sirve sobre el pan con la palta.',
        'notas' => '',
        'ingredientes' => [
            ['nombre' => 'Huevo', 'porcion' => '2 unidades', 'cantidad_g' => 100, 'calorias' => 143, 'proteina_g' => 12.6, 'grasa_g' => 9.5, 'carbohidratos_g' => 0.7],
            ['nombre' => 'Palta', 'porcion' => 'media unidad', 'cantidad_g' => 70, 'calorias' => 112, 'proteina_g' => 1.4, 'grasa_g' => 10.3, 'carbohidratos_g' => 6.0],
        ],
    ], $tipos)];
}

function comidasAGenerar(array $tipos = ['desayuno']): array
{
    $comidas = [];

    foreach ($tipos as $tipo) {
        $comidas[$tipo] = [
            'texto' => "dos huevos y media palta para el {$tipo}",
            'objetivos' => ['calorias' => 528.0, 'proteina_g' => 36.0, 'grasa_g' => 16.0, 'carbohidratos_g' => 45.0],
        ];
    }

    return $comidas;
}

function contextoDeEjemplo(array $fijas = [], array $reservadas = []): array
{
    return [
        'calorias_objetivo_dia' => 2112.0,
        'proteina_objetivo_dia_g' => 144.0,
        'grasa_objetivo_dia_g' => 64.0,
        'carbohidratos_objetivo_dia_g' => 180.0,
        'reparto' => MealPlanGeneratorService::DISTRIBUCION_COMIDAS,
        'comidas_fijas' => $fijas,
        'comidas_reservadas' => $reservadas,
    ];
}

it('resuelve la interfaz al proveedor de Claude vía el contenedor', function () {
    expect(app(MealDistributionProviderInterface::class))
        ->toBeInstanceOf(ClaudeMealDistributionProvider::class);
});

it('llama a la Messages API con Haiku 4.5 y salida estructurada', function () {
    Http::fake(['api.anthropic.com/*' => Http::response(respuestaDeClaude(comidasDeEjemplo()))]);

    (new ClaudeMealDistributionProvider)->distribuirDia(comidasAGenerar(), contextoDeEjemplo());

    Http::assertSent(function ($peticion) {
        $cuerpo = $peticion->data();

        return $peticion->url() === 'https://api.anthropic.com/v1/messages'
            && $peticion->hasHeader('x-api-key', 'clave-de-prueba')
            && $peticion->hasHeader('anthropic-version', '2023-06-01')
            && $cuerpo['model'] === 'claude-haiku-4-5'
            // Structured outputs: la API garantiza la forma de la respuesta.
            && $cuerpo['output_config']['format']['type'] === 'json_schema'
            && $cuerpo['output_config']['format']['schema']['additionalProperties'] === false
            // Haiku 4.5 no acepta `effort` y no queremos razonamiento extendido:
            // omitir `thinking` en un modelo anterior a la familia 4.6 lo desactiva.
            && ! array_key_exists('thinking', $cuerpo)
            && ! array_key_exists('effort', $cuerpo['output_config'])
            // El texto del usuario y los objetivos de la comida viajan en el prompt.
            && str_contains($cuerpo['messages'][0]['content'], 'dos huevos y media palta')
            && str_contains($cuerpo['messages'][0]['content'], 'desayuno');
    });
});

it('manda las tres comidas y el contexto del día en una sola llamada', function () {
    Http::fake(['api.anthropic.com/*' => Http::response(
        respuestaDeClaude(comidasDeEjemplo(['desayuno', 'almuerzo']))
    )]);

    $resultado = (new ClaudeMealDistributionProvider)->distribuirDia(
        comidasAGenerar(['desayuno', 'almuerzo']),
        contextoDeEjemplo(
            fijas: [],
            reservadas: ['cena' => ['calorias' => 739.2, 'proteina_g' => 50.4, 'grasa_g' => 22.4, 'carbohidratos_g' => 63.0]],
        ),
    );

    expect($resultado)->toHaveKeys(['desayuno', 'almuerzo']);

    Http::assertSentCount(1);

    Http::assertSent(function ($peticion) {
        $prompt = $peticion->data()['messages'][0]['content'];

        // La cena no se resuelve, pero el modelo tiene que saber que su
        // presupuesto está apartado (CLAUDE.md sección 4.12).
        return str_contains($prompt, 'reservadas')
            && str_contains($prompt, 'cena')
            && str_contains($prompt, '### almuerzo');
    });
});

it('avisa al modelo de las comidas ya cerradas para que no gaste su presupuesto', function () {
    Http::fake(['api.anthropic.com/*' => Http::response(respuestaDeClaude(comidasDeEjemplo(['cena'])))]);

    (new ClaudeMealDistributionProvider)->distribuirDia(
        comidasAGenerar(['cena']),
        contextoDeEjemplo(fijas: [
            'almuerzo' => ['descripcion' => 'Pollo con arroz', 'calorias' => 597.5, 'proteina_g' => 54.0, 'grasa_g' => 8.1, 'carbohidratos_g' => 72.0],
        ]),
    );

    Http::assertSent(function ($peticion) {
        $prompt = $peticion->data()['messages'][0]['content'];

        return str_contains($prompt, 'están resueltas')
            && str_contains($prompt, 'Pollo con arroz');
    });
});

it('normaliza la distribución devuelta por el modelo', function () {
    Http::fake(['api.anthropic.com/*' => Http::response(respuestaDeClaude(comidasDeEjemplo()))]);

    $resultado = (new ClaudeMealDistributionProvider)->distribuirDia(comidasAGenerar(), contextoDeEjemplo());

    expect($resultado['desayuno']['descripcion'])->toBe('Tostada de palta con huevos revueltos (desayuno)')
        ->and($resultado['desayuno']['ingredientes'])->toHaveCount(2)
        ->and($resultado['desayuno']['ingredientes'][0]['nombre'])->toBe('Huevo')
        ->and($resultado['desayuno']['ingredientes'][0]['porcion'])->toBe('2 unidades')
        ->and($resultado['desayuno']['ingredientes'][0]['calorias'])->toBe(143.0)
        ->and($resultado['desayuno']['ingredientes'][1]['grasa_g'])->toBe(10.3);
});

it('descarta una comida que no se pidió en vez de persistirla', function () {
    Http::fake(['api.anthropic.com/*' => Http::response(
        respuestaDeClaude(comidasDeEjemplo(['desayuno', 'merienda']))
    )]);

    $resultado = (new ClaudeMealDistributionProvider)->distribuirDia(comidasAGenerar(), contextoDeEjemplo());

    expect($resultado)->toHaveKey('desayuno')
        ->and($resultado)->not->toHaveKey('merienda');
});

it('estima el consumo real con el plan como referencia', function () {
    Http::fake(['api.anthropic.com/*' => Http::response(respuestaDeClaude(comidasDeEjemplo(['almuerzo'])))]);

    $resultado = (new ClaudeMealDistributionProvider)->estimarConsumoReal([
        'almuerzo' => [
            'texto' => 'al final me comí un sándwich de pollo',
            'plan' => ['descripcion' => 'Pollo con arroz', 'calorias' => 597.5, 'proteina_g' => 54.0, 'grasa_g' => 8.1, 'carbohidratos_g' => 72.0],
        ],
    ], ['calorias_objetivo_dia' => 2112.0]);

    expect($resultado['almuerzo']['ingredientes'])->toHaveCount(2);

    Http::assertSent(function ($peticion) {
        $cuerpo = $peticion->data();

        return str_contains($cuerpo['system'], 'es estimar lo que la persona dice haber comido')
            && str_contains($cuerpo['messages'][0]['content'], 'sándwich de pollo')
            && str_contains($cuerpo['messages'][0]['content'], 'Pollo con arroz');
    });
});

it('falla de forma controlada y sin llamar a la API cuando no hay clave configurada', function () {
    config(['services.anthropic.key' => null]);
    Http::fake();

    expect(fn () => (new ClaudeMealDistributionProvider)->distribuirDia(comidasAGenerar(), contextoDeEjemplo()))
        ->toThrow(MealDistributionUnavailableException::class, 'ANTHROPIC_API_KEY');

    Http::assertNothingSent();
});

it('traduce un error del proveedor a una excepción de dominio', function () {
    Http::fake(['api.anthropic.com/*' => Http::response(['error' => ['type' => 'overloaded_error']], 529)]);

    expect(fn () => (new ClaudeMealDistributionProvider)->distribuirDia(comidasAGenerar(), contextoDeEjemplo()))
        ->toThrow(MealDistributionUnavailableException::class, '529');
});

it('rechaza una respuesta cortada por longitud en vez de persistir un JSON incompleto', function () {
    Http::fake([
        'api.anthropic.com/*' => Http::response(respuestaDeClaude(comidasDeEjemplo(), 'max_tokens')),
    ]);

    expect(fn () => (new ClaudeMealDistributionProvider)->distribuirDia(comidasAGenerar(['almuerzo']), contextoDeEjemplo()))
        ->toThrow(MealDistributionUnavailableException::class, 'se cortó por longitud');
});

it('devuelve el aviso del modelo cuando no reconoce ningún alimento', function () {
    Http::fake(['api.anthropic.com/*' => Http::response(respuestaDeClaude(['comidas' => [[
        'tipo_comida' => 'cena',
        'descripcion' => '',
        'preparacion' => '',
        'notas' => 'No encontré ningún alimento en tu texto.',
        'ingredientes' => [],
    ]]]))]);

    expect(fn () => (new ClaudeMealDistributionProvider)->distribuirDia(comidasAGenerar(['cena']), contextoDeEjemplo()))
        ->toThrow(MealDistributionUnavailableException::class, 'No encontré ningún alimento');
});

it('rechaza un cuerpo que no se puede interpretar', function () {
    Http::fake(['api.anthropic.com/*' => Http::response([
        'stop_reason' => 'end_turn',
        'content' => [['type' => 'text', 'text' => 'esto no es JSON']],
    ])]);

    expect(fn () => (new ClaudeMealDistributionProvider)->distribuirDia(comidasAGenerar(), contextoDeEjemplo()))
        ->toThrow(MealDistributionUnavailableException::class, 'formato inesperado');
});

it('rechaza pedir una distribución sin comidas', function () {
    Http::fake();

    expect(fn () => (new ClaudeMealDistributionProvider)->distribuirDia([], contextoDeEjemplo()))
        ->toThrow(MealDistributionUnavailableException::class, 'nada nuevo que distribuir');

    Http::assertNothingSent();
});
