<?php

use App\Models\ComidaReal;
use App\Models\PlanComida;
use App\Models\RegistroDiario;
use App\Models\User;
use App\Services\CuotaIaService;
use App\Services\MealDistributionService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * Cierre y reapertura de cada comida por separado (CLAUDE.md sección 5.5).
 *
 * Mismo perfil que el resto de los tests del cierre:
 * objetivo = 80 * 22 * 1.5 * 0.8 = 2112 kcal, proteína objetivo = 160 g.
 */
function usuarioParaReporte(array $sobrescribir = []): User
{
    return User::factory()->create(array_merge([
        'peso_kg' => 80,
        'estatura_m' => 1.75,
        'edad' => 35,
        'sexo' => 'masculino',
        'nivel_actividad' => 1.5,
        'tipo_deficit' => 'porcentaje',
        'valor_deficit' => 0.2,
        'proteina_factor' => 2.0,
        'grasa_factor' => 0.8,
    ], $sobrescribir));
}

function diaConPlanDeDesayuno(User $usuario): RegistroDiario
{
    $registroDiario = RegistroDiario::factory()->for($usuario, 'usuario')->create([
        'fecha' => now()->toDateString(),
    ]);

    PlanComida::factory()->for($registroDiario, 'registroDiario')->create([
        'tipo_comida' => 'desayuno',
        'descripcion' => 'Huevos revueltos con arepa',
        'calorias_estimadas' => 528,
        'proteina_g' => 36,
        'grasa_g' => 16,
        'carbohidratos_g' => 45,
    ]);

    return $registroDiario;
}

/**
 * Respuesta de OpenAI con una estimación de consumo real para una comida.
 */
function respuestaDeConsumoReal(string $tipoComida, array $ingredientes): array
{
    return [
        'choices' => [[
            'index' => 0,
            'message' => [
                'role' => 'assistant',
                'refusal' => null,
                'content' => json_encode(['comidas' => [[
                    'tipo_comida' => $tipoComida,
                    'descripcion' => 'Lo que contó que comió',
                    'preparacion' => '',
                    'notas' => '',
                    'alimentos_reconocidos' => true,
                    'ingredientes' => $ingredientes,
                ]]]),
            ],
            'finish_reason' => 'stop',
        ]],
    ];
}

/**
 * Dos días anteriores con el mismo desayuno —que es lo que lo hace frecuente—
 * y el reporte más reciente de los dos, que es el que se copia.
 */
function comidaFrecuenteDeDesayuno(User $usuario): ComidaReal
{
    foreach ([3, 1] as $diasAtras) {
        $dia = RegistroDiario::factory()->for($usuario, 'usuario')->create([
            'fecha' => now()->subDays($diasAtras)->toDateString(),
        ]);

        $plan = PlanComida::factory()->for($dia, 'registroDiario')->create(['tipo_comida' => 'desayuno']);

        ComidaReal::factory()->for($plan, 'planComida')->create([
            'calorias_reales' => 430,
            'proteina_g' => 24,
            'grasa_g' => 14,
            'carbohidratos_g' => 45,
            'notas' => 'arepa con huevo',
            'consumido_en' => now()->subDays($diasAtras),
        ]);
    }

    return ComidaReal::latest('id')->first();
}
test('un invitado no puede cerrar ni reabrir una comida', function () {
    $registroDiario = RegistroDiario::factory()->create();

    $this->post(route('comidas.cerrar', [$registroDiario, 'desayuno']))->assertRedirect('/login');
    $this->post(route('comidas.reabrir', [$registroDiario, 'desayuno']))->assertRedirect('/login');
});

test('no se puede cerrar una comida del plan de otra persona', function () {
    $registroDiario = diaConPlanDeDesayuno(usuarioParaReporte());

    $this->actingAs(usuarioParaReporte())
        ->post(route('comidas.cerrar', [$registroDiario, 'desayuno']), ['cumplio' => '1'])
        ->assertForbidden();
});

test('un tipo de comida que no existe devuelve 404', function () {
    $usuario = usuarioParaReporte();
    $registroDiario = diaConPlanDeDesayuno($usuario);

    $this->actingAs($usuario)
        ->post(route('comidas.cerrar', [$registroDiario, 'merienda']), ['cumplio' => '1'])
        ->assertNotFound();
});

/*
|--------------------------------------------------------------------------
| Cerrar una comida
|--------------------------------------------------------------------------
*/

test('cerrar una comida con "cumplí lo sugerido" no cuesta ninguna llamada', function () {
    Http::fake();

    $usuario = usuarioParaReporte();
    $registroDiario = diaConPlanDeDesayuno($usuario);
    $desayuno = $registroDiario->planesComida()->first();

    $this->actingAs($usuario)
        ->post(route('comidas.cerrar', [$registroDiario, 'desayuno']), ['cumplio' => '1'])
        ->assertRedirect(route('planes.show', $registroDiario))
        ->assertSessionHas('status', 'comida-cerrada');

    $comidaReal = $desayuno->fresh()->comidaReal;

    expect($comidaReal)->not->toBeNull()
        ->and((float) $comidaReal->calorias_reales)->toBe(528.0)
        ->and((float) $comidaReal->proteina_g)->toBe(36.0)
        ->and((float) $registroDiario->fresh()->calorias_consumidas)->toBe(528.0);

    Http::assertNothingSent();
});

test('cerrar una comida contándola por escrito la estima con IA y la suma al día', function () {
    config([
        'services.openai.key' => 'clave-de-prueba',
        'services.openai.endpoint' => 'https://api.openai.com/v1',
    ]);

    Http::fake(['api.openai.com/*' => Http::response(respuestaDeConsumoReal('desayuno', [
        ['nombre' => 'Sándwich de pollo', 'porcion' => '220 g', 'cantidad_g' => 220, 'calorias' => 480, 'proteina_g' => 28, 'grasa_g' => 18, 'carbohidratos_g' => 50],
        ['nombre' => 'Gaseosa', 'porcion' => '350 ml', 'cantidad_g' => 350, 'calorias' => 140, 'proteina_g' => 0, 'grasa_g' => 0, 'carbohidratos_g' => 37],
    ]))]);

    $usuario = usuarioParaReporte();
    $registroDiario = diaConPlanDeDesayuno($usuario);
    $desayuno = $registroDiario->planesComida()->first();

    $this->actingAs($usuario)->post(route('comidas.cerrar', [$registroDiario, 'desayuno']), [
        'texto' => 'al final me comí un sándwich de pollo y una gaseosa',
    ])->assertSessionHas('status', 'comida-cerrada');

    $comidaReal = $desayuno->fresh()->comidaReal;

    // 480 + 140 = 620 kcal, sumados en PHP a partir de los ingredientes (regla 7).
    expect((float) $comidaReal->calorias_reales)->toBe(620.0)
        ->and((float) $comidaReal->proteina_g)->toBe(28.0)
        ->and($comidaReal->notas)->toContain('sándwich de pollo');
});

test('el texto manda sobre el interruptor de "cumplí lo sugerido"', function () {
    config(['services.openai.key' => 'clave-de-prueba', 'services.openai.endpoint' => 'https://api.openai.com/v1']);

    Http::fake(['api.openai.com/*' => Http::response(respuestaDeConsumoReal('desayuno', [
        ['nombre' => 'Empanada', 'porcion' => '120 g', 'cantidad_g' => 120, 'calorias' => 300, 'proteina_g' => 8, 'grasa_g' => 18, 'carbohidratos_g' => 28],
    ]))]);

    $usuario = usuarioParaReporte();
    $registroDiario = diaConPlanDeDesayuno($usuario);
    $desayuno = $registroDiario->planesComida()->first();

    $this->actingAs($usuario)->post(route('comidas.cerrar', [$registroDiario, 'desayuno']), [
        'cumplio' => '1',
        'texto' => 'en realidad me comí una empanada',
    ])->assertSessionHas('status', 'comida-cerrada');

    expect((float) $desayuno->fresh()->comidaReal->calorias_reales)->toBe(300.0);
});

test('cerrar una comida sin decir nada no registra nada', function () {
    Http::fake();

    $usuario = usuarioParaReporte();
    $registroDiario = diaConPlanDeDesayuno($usuario);

    $this->actingAs($usuario)
        ->post(route('comidas.cerrar', [$registroDiario, 'desayuno']))
        ->assertSessionHas('error');

    expect($registroDiario->planesComida()->first()->comidaReal)->toBeNull();
    Http::assertNothingSent();
});

test('una foto sola no dice qué se comió y no cierra la comida', function () {
    Storage::fake('public');
    Http::fake();

    $usuario = usuarioParaReporte();
    $registroDiario = diaConPlanDeDesayuno($usuario);

    $this->actingAs($usuario)->post(route('comidas.cerrar', [$registroDiario, 'desayuno']), [
        'imagen' => UploadedFile::fake()->image('desayuno.jpg'),
    ])->assertSessionHas('error');

    expect($registroDiario->planesComida()->first()->comidaReal)->toBeNull();
});

test('la foto de evidencia se adjunta al cerrar la comida', function () {
    Storage::fake('public');
    Http::fake();

    $usuario = usuarioParaReporte();
    $registroDiario = diaConPlanDeDesayuno($usuario);

    $this->actingAs($usuario)->post(route('comidas.cerrar', [$registroDiario, 'desayuno']), [
        'cumplio' => '1',
        'imagen' => UploadedFile::fake()->image('desayuno.jpg'),
    ])->assertSessionHas('status', 'comida-cerrada');

    $comidaReal = $registroDiario->planesComida()->first()->comidaReal;

    expect($comidaReal->imagen_evidencia)->not->toBeNull()
        ->and($comidaReal->imagenUrl())->toContain('/storage/comidas-reales/');

    Storage::disk('public')->assertExists($comidaReal->imagen_evidencia);
});

test('rechaza una evidencia que no es una imagen', function () {
    $usuario = usuarioParaReporte();
    $registroDiario = diaConPlanDeDesayuno($usuario);

    $this->actingAs($usuario)->post(route('comidas.cerrar', [$registroDiario, 'desayuno']), [
        'cumplio' => '1',
        'imagen' => UploadedFile::fake()->create('recibo.pdf', 20, 'application/pdf'),
    ])->assertSessionHasErrors('imagen');

    expect($registroDiario->planesComida()->first()->comidaReal)->toBeNull();
});

test('una comida ya cerrada no se sobrescribe con un segundo envío', function () {
    Http::fake();

    $usuario = usuarioParaReporte();
    $registroDiario = diaConPlanDeDesayuno($usuario);

    $this->actingAs($usuario)->post(route('comidas.cerrar', [$registroDiario, 'desayuno']), ['cumplio' => '1']);

    $this->actingAs($usuario)
        ->post(route('comidas.cerrar', [$registroDiario, 'desayuno']), ['cumplio' => '1'])
        ->assertSessionHas('error');

    expect(ComidaReal::count())->toBe(1);
});

test('con el día cerrado no se puede cerrar una comida más', function () {
    Http::fake();

    $usuario = usuarioParaReporte();
    $registroDiario = diaConPlanDeDesayuno($usuario);

    $this->actingAs($usuario)->post(route('comidas.cerrar', [$registroDiario, 'desayuno']), ['cumplio' => '1']);
    $this->actingAs($usuario)->post(route('cierre.cerrar', $registroDiario), ['confirmar_sin_reportar' => '1']);

    PlanComida::factory()->for($registroDiario, 'registroDiario')->create(['tipo_comida' => 'almuerzo']);

    $this->actingAs($usuario)
        ->post(route('comidas.cerrar', [$registroDiario, 'almuerzo']), ['cumplio' => '1'])
        ->assertSessionHas('error');

    expect(ComidaReal::count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Reportar una comida que nunca se planificó
|--------------------------------------------------------------------------
*/

test('se puede reportar una comida sin plan previo, y queda marcada como tal', function () {
    config(['services.openai.key' => 'clave-de-prueba', 'services.openai.endpoint' => 'https://api.openai.com/v1']);

    Http::fake(['api.openai.com/*' => Http::response(respuestaDeConsumoReal('cena', [
        ['nombre' => 'Sopa de verduras', 'porcion' => '350 g', 'cantidad_g' => 350, 'calorias' => 180, 'proteina_g' => 7, 'grasa_g' => 4, 'carbohidratos_g' => 28],
    ]))]);

    $usuario = usuarioParaReporte();
    $registroDiario = diaConPlanDeDesayuno($usuario);

    $this->actingAs($usuario)->post(route('comidas.cerrar', [$registroDiario, 'cena']), [
        'texto' => 'me tomé una sopa de verduras',
    ])->assertSessionHas('status', 'comida-cerrada');

    $cena = $registroDiario->planesComida()->where('tipo_comida', 'cena')->first();

    // Nada se planificó: el plan existe solo para colgar de él lo que se comió.
    expect($cena->origen)->toBe(PlanComida::ORIGEN_REPORTE)
        ->and((float) $cena->calorias_estimadas)->toBe(0.0)
        ->and((float) $cena->comidaReal->calorias_reales)->toBe(180.0);
});

test('"cumplí lo sugerido" sobre una comida sin plan se rechaza', function () {
    Http::fake();

    $usuario = usuarioParaReporte();
    $registroDiario = diaConPlanDeDesayuno($usuario);

    $this->actingAs($usuario)
        ->post(route('comidas.cerrar', [$registroDiario, 'cena']), ['cumplio' => '1'])
        ->assertSessionHas('error');

    expect($registroDiario->planesComida()->where('tipo_comida', 'cena')->count())->toBe(0);
    Http::assertNothingSent();
});

/*
|--------------------------------------------------------------------------
| Reabrir una comida
|--------------------------------------------------------------------------
*/

test('reabrir una comida borra lo reportado y devuelve la pregunta', function () {
    Http::fake();

    $usuario = usuarioParaReporte();
    $registroDiario = diaConPlanDeDesayuno($usuario);
    $desayuno = $registroDiario->planesComida()->first();

    $this->actingAs($usuario)->post(route('comidas.cerrar', [$registroDiario, 'desayuno']), ['cumplio' => '1']);

    $this->actingAs($usuario)
        ->post(route('comidas.reabrir', [$registroDiario, 'desayuno']))
        ->assertSessionHas('status', 'comida-reabierta');

    // El plan sobrevive: vuelve a estar "planificada", que es lo que permite
    // volver a reportarla.
    expect($desayuno->fresh())->not->toBeNull()
        ->and($desayuno->fresh()->comidaReal)->toBeNull()
        ->and((float) $registroDiario->fresh()->calorias_consumidas)->toBe(0.0);
});

test('reabrir un reporte sin plan previo se lleva también su plan fantasma', function () {
    config(['services.openai.key' => 'clave-de-prueba', 'services.openai.endpoint' => 'https://api.openai.com/v1']);

    Http::fake(['api.openai.com/*' => Http::response(respuestaDeConsumoReal('cena', [
        ['nombre' => 'Sopa', 'porcion' => '350 g', 'cantidad_g' => 350, 'calorias' => 180, 'proteina_g' => 7, 'grasa_g' => 4, 'carbohidratos_g' => 28],
    ]))]);

    $usuario = usuarioParaReporte();
    $registroDiario = diaConPlanDeDesayuno($usuario);

    $this->actingAs($usuario)->post(route('comidas.cerrar', [$registroDiario, 'cena']), ['texto' => 'una sopa']);
    $this->actingAs($usuario)->post(route('comidas.reabrir', [$registroDiario, 'cena']));

    expect($registroDiario->planesComida()->where('tipo_comida', 'cena')->count())->toBe(0)
        ->and(ComidaReal::count())->toBe(0);
});

test('reabrir una comida que no tenía nada lo dice sin romperse', function () {
    $usuario = usuarioParaReporte();
    $registroDiario = diaConPlanDeDesayuno($usuario);

    $this->actingAs($usuario)
        ->post(route('comidas.reabrir', [$registroDiario, 'desayuno']))
        ->assertSessionHas('status', 'comida-sin-reporte');

    $this->actingAs($usuario)
        ->post(route('comidas.reabrir', [$registroDiario, 'cena']))
        ->assertSessionHas('status', 'comida-sin-reporte');
});

/*
|--------------------------------------------------------------------------
| Efecto sobre el saldo del día (CLAUDE.md sección 5.21)
|--------------------------------------------------------------------------
*/

test('cerrar el desayuno descuenta su consumo del saldo que ve el resto del día', function () {
    Http::fake();

    $usuario = usuarioParaReporte();
    $registroDiario = diaConPlanDeDesayuno($usuario);

    $antes = app(MealDistributionService::class)->saldoDelDia($registroDiario);

    $this->actingAs($usuario)->post(route('comidas.cerrar', [$registroDiario, 'desayuno']), ['cumplio' => '1']);

    $despues = app(MealDistributionService::class)->saldoDelDia($registroDiario->fresh());

    expect($antes['saldo']['calorias'])->toEqualWithDelta(2112.0, 0.01)
        ->and($despues['saldo']['calorias'])->toEqualWithDelta(2112.0 - 528.0, 0.01)
        ->and($despues['saldo']['proteina_g'])->toEqualWithDelta(160.0 - 36.0, 0.01)
        ->and($despues['comidas_pendientes'])->toBe(['almuerzo', 'cena']);
});

test('el plan diario enseña el saldo por macro y para qué comidas queda', function () {
    Http::fake();

    $usuario = usuarioParaReporte();
    $registroDiario = diaConPlanDeDesayuno($usuario);

    $this->actingAs($usuario)->post(route('comidas.cerrar', [$registroDiario, 'desayuno']), ['cumplio' => '1']);

    $this->actingAs($usuario)->get(route('planes.show', $registroDiario))
        ->assertOk()
        ->assertSee('Te quedan')
        ->assertSee('para almuerzo y cena')
        // 160 − 36 = 124 g de proteína pendientes.
        ->assertSee('quedan 124,0 g');
});

/*
|--------------------------------------------------------------------------
| Cuota diaria de IA (CLAUDE.md sección 5.20)
|--------------------------------------------------------------------------
*/

test('los reportes con IA gastan cuota diaria y los demás caminos no', function () {
    config(['services.openai.key' => 'clave-de-prueba', 'services.openai.endpoint' => 'https://api.openai.com/v1']);

    Http::fake(['api.openai.com/*' => Http::response(respuestaDeConsumoReal('desayuno', [
        ['nombre' => 'Arepa', 'porcion' => '120 g', 'cantidad_g' => 120, 'calorias' => 300, 'proteina_g' => 8, 'grasa_g' => 6, 'carbohidratos_g' => 52],
    ]))]);

    $usuario = usuarioParaReporte();
    $registroDiario = diaConPlanDeDesayuno($usuario);
    $cuotas = app(CuotaIaService::class);

    $limite = $cuotas->limite(CuotaIaService::CONCEPTO_REPORTE);

    $this->actingAs($usuario)->post(route('comidas.cerrar', [$registroDiario, 'desayuno']), [
        'texto' => 'una arepa',
    ]);

    expect($cuotas->restantes($usuario, CuotaIaService::CONCEPTO_REPORTE))->toBe($limite - 1);

    // Reabrir y volver a cerrar sin IA no devuelve ni gasta cuota: si la
    // devolviera, abrir y cerrar sería una llamada gratis infinita.
    $this->actingAs($usuario)->post(route('comidas.reabrir', [$registroDiario, 'desayuno']));
    $this->actingAs($usuario)->post(route('comidas.cerrar', [$registroDiario, 'desayuno']), ['cumplio' => '1']);

    expect($cuotas->restantes($usuario, CuotaIaService::CONCEPTO_REPORTE))->toBe($limite - 1);
});

test('agotada la cuota, contar por escrito se rechaza pero cerrar sin IA sigue funcionando', function () {
    Http::fake();

    $usuario = usuarioParaReporte();
    $registroDiario = diaConPlanDeDesayuno($usuario);
    $cuotas = app(CuotaIaService::class);

    foreach (range(1, $cuotas->limite(CuotaIaService::CONCEPTO_REPORTE)) as $ignorado) {
        $cuotas->consumir($usuario, CuotaIaService::CONCEPTO_REPORTE);
    }

    $this->actingAs($usuario)->post(route('comidas.cerrar', [$registroDiario, 'desayuno']), [
        'texto' => 'una arepa',
    ])->assertSessionHas('error', fn (string $error) => str_contains($error, 'reportes con IA de hoy'));

    expect($registroDiario->planesComida()->first()->comidaReal)->toBeNull();
    Http::assertNothingSent();

    // El camino que no cuesta una llamada sigue abierto.
    $this->actingAs($usuario)
        ->post(route('comidas.cerrar', [$registroDiario, 'desayuno']), ['cumplio' => '1'])
        ->assertSessionHas('status', 'comida-cerrada');
});

test('la cuota es de cada usuario y de cada día', function () {
    $uno = usuarioParaReporte();
    $otro = usuarioParaReporte();
    $cuotas = app(CuotaIaService::class);

    $cuotas->consumir($uno, CuotaIaService::CONCEPTO_DISTRIBUCION);

    expect($cuotas->consumidas($uno, CuotaIaService::CONCEPTO_DISTRIBUCION))->toBe(1)
        ->and($cuotas->consumidas($otro, CuotaIaService::CONCEPTO_DISTRIBUCION))->toBe(0);

    $this->travel(1)->days();

    expect($cuotas->consumidas($uno, CuotaIaService::CONCEPTO_DISTRIBUCION))->toBe(0);
});

test('las comidas frecuentes se ofrecen en el plan diario y se repiten sin llamar a la IA', function () {
    Http::fake();

    $usuario = usuarioParaReporte();

    // Dos días anteriores con el mismo desayuno: es lo que lo hace "frecuente".
    foreach ([3, 1] as $diasAtras) {
        $dia = RegistroDiario::factory()->for($usuario, 'usuario')->create([
            'fecha' => now()->subDays($diasAtras)->toDateString(),
        ]);

        $plan = PlanComida::factory()->for($dia, 'registroDiario')->create(['tipo_comida' => 'desayuno']);

        ComidaReal::factory()->for($plan, 'planComida')->create([
            'calorias_reales' => 430,
            'proteina_g' => 24,
            'grasa_g' => 14,
            'carbohidratos_g' => 45,
            'notas' => 'arepa con huevo',
            'consumido_en' => now()->subDays($diasAtras),
        ]);
    }

    $anterior = ComidaReal::latest('id')->first();
    $registroDiario = diaConPlanDeDesayuno($usuario);

    $this->actingAs($usuario)->get(route('planes.show', $registroDiario))
        ->assertOk()
        ->assertSee('Lo que sueles comer')
        ->assertSee('arepa con huevo');

    $this->actingAs($usuario)->post(route('comidas.cerrar', [$registroDiario, 'desayuno']), [
        'repetir' => $anterior->id,
    ])->assertSessionHas('status', 'comida-cerrada');

    $comidaReal = $registroDiario->planesComida()->first()->comidaReal;

    expect((float) $comidaReal->calorias_reales)->toBe(430.0)
        ->and((float) $comidaReal->proteina_g)->toBe(24.0)
        ->and($comidaReal->notas)->toBe('arepa con huevo');

    // Repetir no gasta cuota ni llama al proveedor: esos macros ya se
    // calcularon el día que se reportaron (sección 5.22).
    Http::assertNothingSent();

    expect(app(CuotaIaService::class)->consumidas($usuario, CuotaIaService::CONCEPTO_REPORTE))->toBe(0);
});

/**
 * "Lo que sueles comer" es un atajo de escritura: el botón copia ese texto en
 * el campo del reporte y no envía nada. Lo que llega al servidor es el texto
 * más el id de la comida copiada, y mientras el texto siga siendo el mismo se
 * reutilizan los macros de aquel día en vez de volver a estimarlos.
 */
test('enviar lo que sueles comer sin tocar el texto no llama a la IA', function () {
    Http::fake();

    $usuario = usuarioParaReporte();
    $anterior = comidaFrecuenteDeDesayuno($usuario);
    $registroDiario = diaConPlanDeDesayuno($usuario);

    $this->actingAs($usuario)->post(route('comidas.cerrar', [$registroDiario, 'desayuno']), [
        'repetir' => $anterior->id,
        'texto' => 'arepa con huevo',
    ])->assertSessionHas('status', 'comida-cerrada');

    $comidaReal = $registroDiario->planesComida()->first()->comidaReal;

    expect((float) $comidaReal->calorias_reales)->toBe(430.0)
        ->and((float) $comidaReal->proteina_g)->toBe(24.0);

    Http::assertNothingSent();

    expect(app(CuotaIaService::class)->consumidas($usuario, CuotaIaService::CONCEPTO_REPORTE))->toBe(0);
});

test('editar el texto copiado deja de ser la misma comida y sí pasa por la IA', function () {
    Http::fake([
        '*' => Http::response(respuestaDeConsumoReal('desayuno', [
            ['nombre' => 'arepa con huevo doble', 'porcion' => '1 arepa y 2 huevos', 'calorias' => 610, 'proteina_g' => 34, 'grasa_g' => 22, 'carbohidratos_g' => 52],
        ])),
    ]);

    $usuario = usuarioParaReporte();
    $anterior = comidaFrecuenteDeDesayuno($usuario);
    $registroDiario = diaConPlanDeDesayuno($usuario);

    $this->actingAs($usuario)->post(route('comidas.cerrar', [$registroDiario, 'desayuno']), [
        'repetir' => $anterior->id,
        'texto' => 'arepa con huevo pero con dos huevos',
    ])->assertSessionHas('status', 'comida-cerrada');

    expect((float) $registroDiario->planesComida()->first()->comidaReal->calorias_reales)->toBe(610.0);

    expect(app(CuotaIaService::class)->consumidas($usuario, CuotaIaService::CONCEPTO_REPORTE))->toBe(1);
});
test('no se puede repetir la comida frecuente de otra persona', function () {
    Http::fake();

    $ajeno = usuarioParaReporte();
    $diaAjeno = diaConPlanDeDesayuno($ajeno);
    $suComida = ComidaReal::factory()->for($diaAjeno->planesComida()->first(), 'planComida')->create();

    $usuario = usuarioParaReporte();
    $registroDiario = diaConPlanDeDesayuno($usuario);

    $this->actingAs($usuario)->post(route('comidas.cerrar', [$registroDiario, 'desayuno']), [
        'repetir' => $suComida->id,
    ])->assertSessionHas('error');

    expect($registroDiario->planesComida()->first()->comidaReal)->toBeNull();
});
