<?php

use App\Exceptions\MealDistributionUnavailableException;
use App\Services\AI\MealDistributionProviderInterface;
use App\Services\AI\OpenAiMealDistributionProvider;
use App\Services\AI\PremiumGatedMealDistributionProvider;
use App\Services\MealPlanGeneratorService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

// Sin base de datos: el proveedor solo habla HTTP. Necesita el contenedor para
// `config()` y el facade `Http`, de ahí el TestCase explícito.
uses(TestCase::class);

beforeEach(function () {
    config([
        'services.openai.key' => 'clave-de-prueba',
        'services.openai.model' => 'gpt-4.1',
        'services.openai.endpoint' => 'https://api.openai.com/v1',
        'services.openai.temperature' => 0.1,
        'services.openai.timeout' => 20,
        'services.openai.connect_timeout' => 5,
        'services.openai.tolerancia_macros' => 0.05,
        'services.openai.reintentos_macros' => 1,
        'services.openai.presupuesto_total' => 25,
    ]);
});

/**
 * Respuesta con la forma real de `chat/completions`: el JSON del esquema viaja
 * como texto dentro de `choices[0].message.content`.
 */
function respuestaDeOpenAi(array $cuerpo, string $finishReason = 'stop'): array
{
    return [
        'id' => 'chatcmpl-prueba',
        'object' => 'chat.completion',
        'model' => 'gpt-4.1',
        'choices' => [[
            'index' => 0,
            'message' => ['role' => 'assistant', 'content' => json_encode($cuerpo), 'refusal' => null],
            'finish_reason' => $finishReason,
        ]],
        'usage' => ['prompt_tokens' => 900, 'completion_tokens' => 300],
    ];
}

/**
 * Distribución que SÍ cuadra con el objetivo de la comida (528 kcal, 36 g de
 * proteína): 524 kcal y 36,0 g sumados en PHP, dentro del 5% de tolerancia.
 */
function comidasCuadradasDeOpenAi(array $tipos = ['desayuno']): array
{
    return ['comidas' => array_map(fn (string $tipo): array => [
        'tipo_comida' => $tipo,
        'descripcion' => "Pollo con arroz ({$tipo})",
        'preparacion' => 'Saltea el pollo y sírvelo sobre el arroz.',
        'notas' => '',
        'alimentos_reconocidos' => true,
        'ingredientes' => [
            ['nombre' => 'Pechuga de pollo', 'porcion' => '100 g', 'cantidad_g' => 100, 'calorias' => 165, 'proteina_g' => 31.0, 'grasa_g' => 3.6, 'carbohidratos_g' => 0],
            ['nombre' => 'Arroz blanco cocido', 'porcion' => '1 taza', 'cantidad_g' => 200, 'calorias' => 260, 'proteina_g' => 5.0, 'grasa_g' => 0.6, 'carbohidratos_g' => 57],
            ['nombre' => 'Aceite de oliva', 'porcion' => '1 cucharada', 'cantidad_g' => 11, 'calorias' => 99, 'proteina_g' => 0, 'grasa_g' => 11.0, 'carbohidratos_g' => 0],
        ],
    ], $tipos)];
}

/**
 * Distribución que se queda MUY corta frente al mismo objetivo: 255 kcal y
 * 14 g de proteína, un 61% por debajo. Es lo que dispara la corrección.
 */
function comidasDesviadasDeOpenAi(array $tipos = ['desayuno']): array
{
    return ['comidas' => array_map(fn (string $tipo): array => [
        'tipo_comida' => $tipo,
        'descripcion' => "Tostada de palta con huevos ({$tipo})",
        'preparacion' => 'Revuelve los huevos y sirve sobre el pan con la palta.',
        'notas' => '',
        'alimentos_reconocidos' => true,
        'ingredientes' => [
            ['nombre' => 'Huevo', 'porcion' => '2 unidades', 'cantidad_g' => 100, 'calorias' => 143, 'proteina_g' => 12.6, 'grasa_g' => 9.5, 'carbohidratos_g' => 0.7],
            ['nombre' => 'Palta', 'porcion' => 'media unidad', 'cantidad_g' => 70, 'calorias' => 112, 'proteina_g' => 1.4, 'grasa_g' => 10.3, 'carbohidratos_g' => 6.0],
        ],
    ], $tipos)];
}

function comidasAGenerarOpenAi(array $tipos = ['desayuno']): array
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

function contextoDeEjemploOpenAi(array $fijas = [], array $reservadas = []): array
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

it('resuelve la interfaz al proveedor de OpenAI, envuelto en el control de plan', function () {
    $resuelto = app(MealDistributionProviderInterface::class);

    // El gate de plan es lo que garantiza que ninguna llamada al proveedor se
    // salte la comprobación por olvidarse un `if` en un controlador nuevo
    // (CLAUDE.md sección 5.18)...
    expect($resuelto)->toBeInstanceOf(PremiumGatedMealDistributionProvider::class);

    // ...y dentro de él tiene que estar OpenAI, no el proveedor anterior: es la
    // única línea que decide qué motor de IA usa toda la aplicación.
    $envuelto = (new ReflectionProperty(PremiumGatedMealDistributionProvider::class, 'siguiente'))
        ->getValue($resuelto);

    expect($envuelto)->toBeInstanceOf(OpenAiMealDistributionProvider::class);
});

it('llama a chat/completions con salida estructurada estricta', function () {
    Http::fake(['api.openai.com/*' => Http::response(respuestaDeOpenAi(comidasCuadradasDeOpenAi()))]);

    (new OpenAiMealDistributionProvider)->distribuirDia(comidasAGenerarOpenAi(), contextoDeEjemploOpenAi());

    Http::assertSent(function ($peticion) {
        $cuerpo = $peticion->data();
        $esquema = $cuerpo['response_format']['json_schema'];

        return $peticion->url() === 'https://api.openai.com/v1/chat/completions'
            && $peticion->hasHeader('authorization', 'Bearer clave-de-prueba')
            && $cuerpo['model'] === 'gpt-4.1'
            // Structured outputs en modo estricto: la API garantiza la forma.
            && $cuerpo['response_format']['type'] === 'json_schema'
            && $esquema['strict'] === true
            && $esquema['schema']['additionalProperties'] === false
            // El enum se arma con las comidas que de verdad se pidieron.
            && $esquema['schema']['properties']['comidas']['items']['properties']['tipo_comida']['enum'] === ['desayuno']
            // Determinismo y tope de salida.
            && $cuerpo['temperature'] === 0.1
            && $cuerpo['max_completion_tokens'] === 4096
            // El texto del usuario y los objetivos de la comida viajan en el prompt.
            && str_contains($cuerpo['messages'][1]['content'], 'dos huevos y media palta')
            && str_contains($cuerpo['messages'][1]['content'], '### desayuno')
            && str_contains($cuerpo['messages'][0]['content'], 'nutricionista');
    });
});

it('omite la temperatura cuando no está configurada, para los modelos que la rechazan', function () {
    config(['services.openai.temperature' => null]);
    Http::fake(['api.openai.com/*' => Http::response(respuestaDeOpenAi(comidasCuadradasDeOpenAi()))]);

    (new OpenAiMealDistributionProvider)->distribuirDia(comidasAGenerarOpenAi(), contextoDeEjemploOpenAi());

    Http::assertSent(fn ($peticion) => ! array_key_exists('temperature', $peticion->data()));
});

it('manda las tres comidas y el contexto del día en una sola llamada', function () {
    Http::fake(['api.openai.com/*' => Http::response(
        respuestaDeOpenAi(comidasCuadradasDeOpenAi(['desayuno', 'almuerzo']))
    )]);

    $resultado = (new OpenAiMealDistributionProvider)->distribuirDia(
        comidasAGenerarOpenAi(['desayuno', 'almuerzo']),
        contextoDeEjemploOpenAi(
            fijas: [],
            reservadas: ['cena' => ['calorias' => 739.2, 'proteina_g' => 50.4, 'grasa_g' => 22.4, 'carbohidratos_g' => 63.0]],
        ),
    );

    expect($resultado)->toHaveKeys(['desayuno', 'almuerzo']);

    Http::assertSentCount(1);

    Http::assertSent(function ($peticion) {
        $prompt = $peticion->data()['messages'][1]['content'];

        // La cena no se resuelve, pero el modelo tiene que saber que su
        // presupuesto está apartado (CLAUDE.md sección 4.12).
        return str_contains($prompt, 'TODAVÍA NO HA ESCRITO')
            && str_contains($prompt, 'cena')
            && str_contains($prompt, '### almuerzo');
    });
});

it('avisa al modelo de las comidas ya cerradas para que no gaste su presupuesto', function () {
    Http::fake(['api.openai.com/*' => Http::response(respuestaDeOpenAi(comidasCuadradasDeOpenAi(['cena'])))]);

    (new OpenAiMealDistributionProvider)->distribuirDia(
        comidasAGenerarOpenAi(['cena']),
        contextoDeEjemploOpenAi(fijas: [
            'almuerzo' => ['descripcion' => 'Pollo con arroz', 'calorias' => 597.5, 'proteina_g' => 54.0, 'grasa_g' => 8.1, 'carbohidratos_g' => 72.0],
        ]),
    );

    Http::assertSent(function ($peticion) {
        $prompt = $peticion->data()['messages'][1]['content'];

        return str_contains($prompt, 'COMIDAS YA RESUELTAS')
            && str_contains($prompt, 'Pollo con arroz');
    });
});

it('normaliza la distribución devuelta por el modelo', function () {
    Http::fake(['api.openai.com/*' => Http::response(respuestaDeOpenAi(comidasCuadradasDeOpenAi()))]);

    $resultado = (new OpenAiMealDistributionProvider)->distribuirDia(comidasAGenerarOpenAi(), contextoDeEjemploOpenAi());

    expect($resultado['desayuno']['descripcion'])->toBe('Pollo con arroz (desayuno)')
        ->and($resultado['desayuno']['ingredientes'])->toHaveCount(3)
        ->and($resultado['desayuno']['ingredientes'][0]['nombre'])->toBe('Pechuga de pollo')
        ->and($resultado['desayuno']['ingredientes'][0]['porcion'])->toBe('100 g')
        ->and($resultado['desayuno']['ingredientes'][0]['calorias'])->toBe(165.0)
        ->and($resultado['desayuno']['ingredientes'][1]['carbohidratos_g'])->toBe(57.0);
});

it('no vuelve a llamar cuando los totales ya entran en la tolerancia', function () {
    Http::fake(['api.openai.com/*' => Http::response(respuestaDeOpenAi(comidasCuadradasDeOpenAi()))]);

    (new OpenAiMealDistributionProvider)->distribuirDia(comidasAGenerarOpenAi(), contextoDeEjemploOpenAi());

    Http::assertSentCount(1);
});

it('le pide al modelo que corrija cuando los totales se salen de la tolerancia', function () {
    Http::fake(['api.openai.com/*' => Http::sequence()
        ->push(respuestaDeOpenAi(comidasDesviadasDeOpenAi()))
        ->push(respuestaDeOpenAi(comidasCuadradasDeOpenAi())),
    ]);

    $resultado = (new OpenAiMealDistributionProvider)->distribuirDia(comidasAGenerarOpenAi(), contextoDeEjemploOpenAi());

    Http::assertSentCount(2);

    // Se queda con la corregida, no con la primera.
    expect($resultado['desayuno']['ingredientes'][0]['nombre'])->toBe('Pechuga de pollo');

    Http::assertSent(function ($peticion) {
        $mensajes = $peticion->data()['messages'];

        if (count($mensajes) !== 4) {
            return false;
        }

        // Al modelo se le devuelve su propia respuesta y, con ella, las sumas
        // que hizo PHP: es el dato que él no tiene.
        return $mensajes[2]['role'] === 'assistant'
            && $mensajes[3]['role'] === 'user'
            && str_contains($mensajes[3]['content'], '255 kcal (objetivo 528)')
            && str_contains($mensajes[3]['content'], 'P 14 g (objetivo 36)')
            && str_contains($mensajes[3]['content'], 'No añadas alimentos que la persona no haya mencionado');
    });
});

it('devuelve el mejor intento en vez de fallar cuando ni corrigiendo se cuadra', function () {
    // Con lo que la persona tiene puede ser imposible llegar al objetivo. Eso no
    // es un error: es el caso que el campo "notas" tiene que explicar.
    Http::fake(['api.openai.com/*' => Http::response(respuestaDeOpenAi(comidasDesviadasDeOpenAi()))]);

    $resultado = (new OpenAiMealDistributionProvider)->distribuirDia(comidasAGenerarOpenAi(), contextoDeEjemploOpenAi());

    Http::assertSentCount(2);
    expect($resultado['desayuno']['ingredientes'][0]['nombre'])->toBe('Huevo');
});

it('no corrige nada cuando los reintentos están desactivados', function () {
    config(['services.openai.reintentos_macros' => 0]);
    Http::fake(['api.openai.com/*' => Http::response(respuestaDeOpenAi(comidasDesviadasDeOpenAi()))]);

    (new OpenAiMealDistributionProvider)->distribuirDia(comidasAGenerarOpenAi(), contextoDeEjemploOpenAi());

    Http::assertSentCount(1);
});

it('no empieza una corrección que no quepa en el presupuesto de tiempo', function () {
    // Encadenar llamadas hasta pasarse del timeout del gateway se llevaría por
    // delante el worker de PHP-FPM (regla 10, sección 13). Con un presupuesto
    // por debajo del mínimo útil (connect + 5 s), la corrección ni se intenta.
    config(['services.openai.presupuesto_total' => 5]);
    Http::fake(['api.openai.com/*' => Http::response(respuestaDeOpenAi(comidasDesviadasDeOpenAi()))]);

    (new OpenAiMealDistributionProvider)->distribuirDia(comidasAGenerarOpenAi(), contextoDeEjemploOpenAi());

    Http::assertSentCount(1);
});

it('le da a la corrección el tiempo que sobra del presupuesto, no un timeout nuevo', function () {
    // 25 s de presupuesto y una primera llamada instantánea: la corrección se
    // queda con el timeout normal de 20 s, que sí cabe. Lo que nunca ocurre es
    // que las dos sumen más que el presupuesto.
    Http::fake(['api.openai.com/*' => Http::sequence()
        ->push(respuestaDeOpenAi(comidasDesviadasDeOpenAi()))
        ->push(respuestaDeOpenAi(comidasCuadradasDeOpenAi())),
    ]);

    config(['services.openai.presupuesto_total' => 12]);

    (new OpenAiMealDistributionProvider)->distribuirDia(comidasAGenerarOpenAi(), contextoDeEjemploOpenAi());

    // 12 s de presupuesto siguen dando para una corrección (más de connect + 5),
    // aunque sean menos que el timeout de 20 s de una llamada suelta.
    Http::assertSentCount(2);
});

it('estima el consumo real con el plan como referencia y sin corregir nada', function () {
    Http::fake(['api.openai.com/*' => Http::response(respuestaDeOpenAi(comidasDesviadasDeOpenAi(['almuerzo'])))]);

    $resultado = (new OpenAiMealDistributionProvider)->estimarConsumoReal([
        'almuerzo' => [
            'texto' => 'al final me comí un sándwich de pollo',
            'plan' => ['descripcion' => 'Pollo con arroz', 'calorias' => 597.5, 'proteina_g' => 54.0, 'grasa_g' => 8.1, 'carbohidratos_g' => 72.0],
        ],
    ], ['calorias_objetivo_dia' => 2112.0]);

    expect($resultado['almuerzo']['ingredientes'])->toHaveCount(2);

    // Lo comido, comido está: empujar estas cifras hacia un objetivo sería el
    // error contrario al que resuelve la corrección de `distribuirDia()`.
    Http::assertSentCount(1);

    Http::assertSent(function ($peticion) {
        $mensajes = $peticion->data()['messages'];

        return str_contains($mensajes[0]['content'], 'estimar lo que la persona dice haber comido')
            && str_contains($mensajes[1]['content'], 'sándwich de pollo')
            && str_contains($mensajes[1]['content'], 'Pollo con arroz');
    });
});

it('trata "alimentos_reconocidos: false" como comida no reconocida aunque traiga ingredientes', function () {
    Http::fake(['api.openai.com/*' => Http::response(respuestaDeOpenAi(['comidas' => [[
        'tipo_comida' => 'cena',
        'descripcion' => '',
        'preparacion' => '',
        'notas' => 'El texto no describe comida real.',
        'alimentos_reconocidos' => false,
        'ingredientes' => [
            ['nombre' => 'Ingrediente', 'porcion' => '', 'cantidad_g' => 0, 'calorias' => 0, 'proteina_g' => 0, 'grasa_g' => 0, 'carbohidratos_g' => 0],
        ],
    ]]]))]);

    expect(fn () => (new OpenAiMealDistributionProvider)->distribuirDia(comidasAGenerarOpenAi(['cena']), contextoDeEjemploOpenAi()))
        ->toThrow(MealDistributionUnavailableException::class, 'El texto no describe comida real');
});

it('descarta una comida que no se pidió en vez de persistirla', function () {
    Http::fake(['api.openai.com/*' => Http::response(
        respuestaDeOpenAi(comidasCuadradasDeOpenAi(['desayuno', 'merienda']))
    )]);

    $resultado = (new OpenAiMealDistributionProvider)->distribuirDia(comidasAGenerarOpenAi(), contextoDeEjemploOpenAi());

    expect($resultado)->toHaveKey('desayuno')
        ->and($resultado)->not->toHaveKey('merienda');
});

it('falla de forma controlada y sin llamar a la API cuando no hay clave configurada', function () {
    config(['services.openai.key' => null]);
    Http::fake();

    expect(fn () => (new OpenAiMealDistributionProvider)->distribuirDia(comidasAGenerarOpenAi(), contextoDeEjemploOpenAi()))
        ->toThrow(MealDistributionUnavailableException::class, 'OPENAI_API_KEY');

    Http::assertNothingSent();
});

it('traduce un error del proveedor a una excepción de dominio', function () {
    Http::fake(['api.openai.com/*' => Http::response(['error' => ['message' => 'rate limit', 'type' => 'rate_limit_error']], 429)]);

    expect(fn () => (new OpenAiMealDistributionProvider)->distribuirDia(comidasAGenerarOpenAi(), contextoDeEjemploOpenAi()))
        ->toThrow(MealDistributionUnavailableException::class, '429');
});

it('rechaza una respuesta cortada por longitud en vez de persistir un JSON incompleto', function () {
    Http::fake(['api.openai.com/*' => Http::response(respuestaDeOpenAi(comidasCuadradasDeOpenAi(), 'length'))]);

    expect(fn () => (new OpenAiMealDistributionProvider)->distribuirDia(comidasAGenerarOpenAi(['almuerzo']), contextoDeEjemploOpenAi()))
        ->toThrow(MealDistributionUnavailableException::class, 'se cortó por longitud');
});

it('traduce un bloqueo del filtro de seguridad a una excepción de dominio', function () {
    Http::fake(['api.openai.com/*' => Http::response(respuestaDeOpenAi(comidasCuadradasDeOpenAi(), 'content_filter'))]);

    expect(fn () => (new OpenAiMealDistributionProvider)->distribuirDia(comidasAGenerarOpenAi(), contextoDeEjemploOpenAi()))
        ->toThrow(MealDistributionUnavailableException::class, 'filtro de seguridad');
});

it('traduce una negativa explícita del modelo a una excepción de dominio', function () {
    Http::fake(['api.openai.com/*' => Http::response([
        'choices' => [[
            'index' => 0,
            'message' => ['role' => 'assistant', 'content' => null, 'refusal' => 'No puedo ayudar con eso.'],
            'finish_reason' => 'stop',
        ]],
    ])]);

    expect(fn () => (new OpenAiMealDistributionProvider)->distribuirDia(comidasAGenerarOpenAi(), contextoDeEjemploOpenAi()))
        ->toThrow(MealDistributionUnavailableException::class, 'rechazó la solicitud');
});

it('devuelve el aviso del modelo cuando no reconoce ningún alimento', function () {
    Http::fake(['api.openai.com/*' => Http::response(respuestaDeOpenAi(['comidas' => [[
        'tipo_comida' => 'cena',
        'descripcion' => '',
        'preparacion' => '',
        'notas' => 'No encontré ningún alimento en tu texto.',
        'alimentos_reconocidos' => false,
        'ingredientes' => [],
    ]]]))]);

    expect(fn () => (new OpenAiMealDistributionProvider)->distribuirDia(comidasAGenerarOpenAi(['cena']), contextoDeEjemploOpenAi()))
        ->toThrow(MealDistributionUnavailableException::class, 'No encontré ningún alimento');
});

it('rechaza un cuerpo que no se puede interpretar', function () {
    Http::fake(['api.openai.com/*' => Http::response([
        'choices' => [[
            'index' => 0,
            'message' => ['role' => 'assistant', 'content' => 'esto no es JSON'],
            'finish_reason' => 'stop',
        ]],
    ])]);

    expect(fn () => (new OpenAiMealDistributionProvider)->distribuirDia(comidasAGenerarOpenAi(), contextoDeEjemploOpenAi()))
        ->toThrow(MealDistributionUnavailableException::class, 'formato inesperado');
});

it('rechaza pedir una distribución sin comidas', function () {
    Http::fake();

    expect(fn () => (new OpenAiMealDistributionProvider)->distribuirDia([], contextoDeEjemploOpenAi()))
        ->toThrow(MealDistributionUnavailableException::class, 'nada nuevo que distribuir');

    Http::assertNothingSent();
});
