<?php

use App\Exceptions\MealDistributionUnavailableException;
use App\Services\AI\GeminiMealDistributionProvider;
use App\Services\MealPlanGeneratorService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

// Sin base de datos: el proveedor solo habla HTTP. Necesita el contenedor para
// `config()` y el facade `Http`, de ahí el TestCase explícito.
uses(TestCase::class);

beforeEach(function () {
    config([
        'services.gemini.key' => 'clave-de-prueba',
        'services.gemini.model' => 'gemini-2.5-flash',
        'services.gemini.endpoint' => 'https://generativelanguage.googleapis.com/v1beta/models',
    ]);
});

/**
 * Respuesta con la forma real de la Gemini API: el JSON del esquema viaja
 * dentro de `candidates[0].content.parts[0].text`.
 */
function respuestaDeGemini(array $cuerpo, string $finishReason = 'STOP'): array
{
    return [
        'candidates' => [[
            'content' => [
                'role' => 'model',
                'parts' => [['text' => json_encode($cuerpo)]],
            ],
            'finishReason' => $finishReason,
        ]],
        'usageMetadata' => ['promptTokenCount' => 500, 'candidatesTokenCount' => 200],
    ];
}

/**
 * El cuerpo del esquema: una entrada por comida resuelta.
 */
function comidasDeEjemploGemini(array $tipos = ['desayuno']): array
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

function comidasAGenerarGemini(array $tipos = ['desayuno']): array
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

function contextoDeEjemploGemini(array $fijas = [], array $reservadas = []): array
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

it('sigue construyéndose aunque ya no sea el proveedor vigente', function () {
    // Gemini dejó de estar bindeado (lo sustituyó OpenAI por precisión de las
    // estimaciones), pero se conserva entero y probado por si hiciera falta
    // volver atrás — CLAUDE.md sección 6. Quién está bindeado hoy lo fija
    // OpenAiMealDistributionProviderTest.
    expect(app(GeminiMealDistributionProvider::class))
        ->toBeInstanceOf(GeminiMealDistributionProvider::class);
});

it('llama a la Gemini API con Gemini 2.5 Flash y salida estructurada', function () {
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(respuestaDeGemini(comidasDeEjemploGemini()))]);

    (new GeminiMealDistributionProvider)->distribuirDia(comidasAGenerarGemini(), contextoDeEjemploGemini());

    Http::assertSent(function ($peticion) {
        $cuerpo = $peticion->data();

        return $peticion->url() === 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent'
            && $peticion->hasHeader('x-goog-api-key', 'clave-de-prueba')
            // Structured outputs: la API garantiza la forma de la respuesta.
            && $cuerpo['generationConfig']['responseMimeType'] === 'application/json'
            && $cuerpo['generationConfig']['responseSchema']['type'] === 'OBJECT'
            && in_array('alimentos_reconocidos', $cuerpo['generationConfig']['responseSchema']['properties']['comidas']['items']['required'], true)
            // Determinismo: temperatura mínima y sin razonamiento extendido.
            && $cuerpo['generationConfig']['temperature'] === 0.1
            && $cuerpo['generationConfig']['thinkingConfig']['thinkingBudget'] === 0
            // El texto del usuario y los objetivos de la comida viajan en el prompt.
            && str_contains($cuerpo['contents'][0]['parts'][0]['text'], 'dos huevos y media palta')
            && str_contains($cuerpo['contents'][0]['parts'][0]['text'], 'desayuno')
            && str_contains($cuerpo['systemInstruction']['parts'][0]['text'], 'nutricionista');
    });
});

it('trata "alimentos_reconocidos: false" como comida no reconocida aunque traiga ingredientes', function () {
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(respuestaDeGemini(['comidas' => [[
        'tipo_comida' => 'cena',
        'descripcion' => '',
        'preparacion' => '',
        'notas' => 'El texto no describe comida real.',
        'alimentos_reconocidos' => false,
        'ingredientes' => [
            ['nombre' => 'Ingrediente', 'porcion' => '', 'cantidad_g' => 0, 'calorias' => 0, 'proteina_g' => 0, 'grasa_g' => 0, 'carbohidratos_g' => 0],
        ],
    ]]]))]);

    expect(fn () => (new GeminiMealDistributionProvider)->distribuirDia(comidasAGenerarGemini(['cena']), contextoDeEjemploGemini()))
        ->toThrow(MealDistributionUnavailableException::class, 'El texto no describe comida real');
});

it('manda las tres comidas y el contexto del día en una sola llamada', function () {
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(
        respuestaDeGemini(comidasDeEjemploGemini(['desayuno', 'almuerzo']))
    )]);

    $resultado = (new GeminiMealDistributionProvider)->distribuirDia(
        comidasAGenerarGemini(['desayuno', 'almuerzo']),
        contextoDeEjemploGemini(
            fijas: [],
            reservadas: ['cena' => ['calorias' => 739.2, 'proteina_g' => 50.4, 'grasa_g' => 22.4, 'carbohidratos_g' => 63.0]],
        ),
    );

    expect($resultado)->toHaveKeys(['desayuno', 'almuerzo']);

    Http::assertSentCount(1);

    Http::assertSent(function ($peticion) {
        $prompt = $peticion->data()['contents'][0]['parts'][0]['text'];

        // La cena no se resuelve, pero el modelo tiene que saber que su
        // presupuesto está apartado (CLAUDE.md sección 4.12).
        return str_contains($prompt, 'reservadas')
            && str_contains($prompt, 'cena')
            && str_contains($prompt, '### almuerzo');
    });
});

it('avisa al modelo de las comidas ya cerradas para que no gaste su presupuesto', function () {
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(respuestaDeGemini(comidasDeEjemploGemini(['cena'])))]);

    (new GeminiMealDistributionProvider)->distribuirDia(
        comidasAGenerarGemini(['cena']),
        contextoDeEjemploGemini(fijas: [
            'almuerzo' => ['descripcion' => 'Pollo con arroz', 'calorias' => 597.5, 'proteina_g' => 54.0, 'grasa_g' => 8.1, 'carbohidratos_g' => 72.0],
        ]),
    );

    Http::assertSent(function ($peticion) {
        $prompt = $peticion->data()['contents'][0]['parts'][0]['text'];

        return str_contains($prompt, 'están resueltas')
            && str_contains($prompt, 'Pollo con arroz');
    });
});

it('normaliza la distribución devuelta por el modelo', function () {
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(respuestaDeGemini(comidasDeEjemploGemini()))]);

    $resultado = (new GeminiMealDistributionProvider)->distribuirDia(comidasAGenerarGemini(), contextoDeEjemploGemini());

    expect($resultado['desayuno']['descripcion'])->toBe('Tostada de palta con huevos revueltos (desayuno)')
        ->and($resultado['desayuno']['ingredientes'])->toHaveCount(2)
        ->and($resultado['desayuno']['ingredientes'][0]['nombre'])->toBe('Huevo')
        ->and($resultado['desayuno']['ingredientes'][0]['porcion'])->toBe('2 unidades')
        ->and($resultado['desayuno']['ingredientes'][0]['calorias'])->toBe(143.0)
        ->and($resultado['desayuno']['ingredientes'][1]['grasa_g'])->toBe(10.3);
});

it('descarta una comida que no se pidió en vez de persistirla', function () {
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(
        respuestaDeGemini(comidasDeEjemploGemini(['desayuno', 'merienda']))
    )]);

    $resultado = (new GeminiMealDistributionProvider)->distribuirDia(comidasAGenerarGemini(), contextoDeEjemploGemini());

    expect($resultado)->toHaveKey('desayuno')
        ->and($resultado)->not->toHaveKey('merienda');
});

it('estima el consumo real con el plan como referencia', function () {
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(respuestaDeGemini(comidasDeEjemploGemini(['almuerzo'])))]);

    $resultado = (new GeminiMealDistributionProvider)->estimarConsumoReal([
        'almuerzo' => [
            'texto' => 'al final me comí un sándwich de pollo',
            'plan' => ['descripcion' => 'Pollo con arroz', 'calorias' => 597.5, 'proteina_g' => 54.0, 'grasa_g' => 8.1, 'carbohidratos_g' => 72.0],
        ],
    ], ['calorias_objetivo_dia' => 2112.0]);

    expect($resultado['almuerzo']['ingredientes'])->toHaveCount(2);

    Http::assertSent(function ($peticion) {
        $cuerpo = $peticion->data();

        return str_contains($cuerpo['systemInstruction']['parts'][0]['text'], 'es estimar lo que la persona dice haber comido')
            && str_contains($cuerpo['contents'][0]['parts'][0]['text'], 'sándwich de pollo')
            && str_contains($cuerpo['contents'][0]['parts'][0]['text'], 'Pollo con arroz');
    });
});

it('falla de forma controlada y sin llamar a la API cuando no hay clave configurada', function () {
    config(['services.gemini.key' => null]);
    Http::fake();

    expect(fn () => (new GeminiMealDistributionProvider)->distribuirDia(comidasAGenerarGemini(), contextoDeEjemploGemini()))
        ->toThrow(MealDistributionUnavailableException::class, 'GEMINI_API_KEY');

    Http::assertNothingSent();
});

it('traduce un error del proveedor a una excepción de dominio', function () {
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['error' => ['message' => 'quota exceeded']], 429)]);

    expect(fn () => (new GeminiMealDistributionProvider)->distribuirDia(comidasAGenerarGemini(), contextoDeEjemploGemini()))
        ->toThrow(MealDistributionUnavailableException::class, '429');
});

it('rechaza una respuesta cortada por longitud en vez de persistir un JSON incompleto', function () {
    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::response(respuestaDeGemini(comidasDeEjemploGemini(), 'MAX_TOKENS')),
    ]);

    expect(fn () => (new GeminiMealDistributionProvider)->distribuirDia(comidasAGenerarGemini(['almuerzo']), contextoDeEjemploGemini()))
        ->toThrow(MealDistributionUnavailableException::class, 'se cortó por longitud');
});

it('traduce un bloqueo del filtro de seguridad a una excepción de dominio', function () {
    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::response(respuestaDeGemini(comidasDeEjemploGemini(), 'SAFETY')),
    ]);

    expect(fn () => (new GeminiMealDistributionProvider)->distribuirDia(comidasAGenerarGemini(), contextoDeEjemploGemini()))
        ->toThrow(MealDistributionUnavailableException::class, 'SAFETY');
});

it('traduce un bloqueo por promptFeedback a una excepción de dominio', function () {
    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::response([
            'promptFeedback' => ['blockReason' => 'SAFETY'],
        ]),
    ]);

    expect(fn () => (new GeminiMealDistributionProvider)->distribuirDia(comidasAGenerarGemini(), contextoDeEjemploGemini()))
        ->toThrow(MealDistributionUnavailableException::class, 'filtro de seguridad');
});

it('devuelve el aviso del modelo cuando no reconoce ningún alimento', function () {
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(respuestaDeGemini(['comidas' => [[
        'tipo_comida' => 'cena',
        'descripcion' => '',
        'preparacion' => '',
        'notas' => 'No encontré ningún alimento en tu texto.',
        'ingredientes' => [],
    ]]]))]);

    expect(fn () => (new GeminiMealDistributionProvider)->distribuirDia(comidasAGenerarGemini(['cena']), contextoDeEjemploGemini()))
        ->toThrow(MealDistributionUnavailableException::class, 'No encontré ningún alimento');
});

it('rechaza un cuerpo que no se puede interpretar', function () {
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
        'candidates' => [[
            'content' => ['parts' => [['text' => 'esto no es JSON']]],
            'finishReason' => 'STOP',
        ]],
    ])]);

    expect(fn () => (new GeminiMealDistributionProvider)->distribuirDia(comidasAGenerarGemini(), contextoDeEjemploGemini()))
        ->toThrow(MealDistributionUnavailableException::class, 'formato inesperado');
});

it('rechaza pedir una distribución sin comidas', function () {
    Http::fake();

    expect(fn () => (new GeminiMealDistributionProvider)->distribuirDia([], contextoDeEjemploGemini()))
        ->toThrow(MealDistributionUnavailableException::class, 'nada nuevo que distribuir');

    Http::assertNothingSent();
});
