<?php

use App\Models\ComidaReal;
use App\Models\PlanComida;
use App\Models\RegistroDiario;
use App\Models\User;
use App\Services\ComidasFrecuentesService;
use App\Services\DailyClosureService;
use App\Services\MealDistributionService;
use App\Services\MealPlanGeneratorService;
use App\Services\ReporteComidaService;
use Illuminate\Support\Facades\Http;

/**
 * Extras del día (CLAUDE.md sección 5.27): lo que se consume fuera del
 * desayuno, el almuerzo y la cena.
 *
 * Mismo perfil que el resto de los tests del cierre:
 * objetivo = 80 * 22 * 1.5 * 0.8 = 2112 kcal, proteína objetivo = 160 g.
 */
function usuarioParaExtras(): User
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

function diaParaExtras(User $usuario): RegistroDiario
{
    return RegistroDiario::factory()->for($usuario, 'usuario')->create([
        'fecha' => now()->toDateString(),
    ]);
}

/**
 * Respuesta del proveedor para un extra: una entrada de tipo `snack`.
 */
function respuestaDeExtra(float $kcal, float $proteina = 1.0, float $grasa = 0.0, float $carbohidratos = 20.0): array
{
    return [
        'choices' => [[
            'index' => 0,
            'message' => [
                'role' => 'assistant',
                'refusal' => null,
                'content' => json_encode(['comidas' => [[
                    'tipo_comida' => 'snack',
                    'descripcion' => 'Lo que picó',
                    'preparacion' => '',
                    'notas' => '',
                    'alimentos_reconocidos' => true,
                    'ingredientes' => [[
                        'nombre' => 'Extra',
                        'porcion' => '1 unidad',
                        'cantidad_g' => 100,
                        'calorias' => $kcal,
                        'proteina_g' => $proteina,
                        'grasa_g' => $grasa,
                        'carbohidratos_g' => $carbohidratos,
                    ]],
                ]]]),
            ],
            'finish_reason' => 'stop',
        ]],
        'usage' => ['prompt_tokens' => 400, 'completion_tokens' => 120],
    ];
}

it('registra un extra como un snack sin plan previo', function () {
    Http::fake(['api.openai.com/*' => Http::response(respuestaDeExtra(150))]);

    $usuario = usuarioParaExtras();
    $registroDiario = diaParaExtras($usuario);

    $this->actingAs($usuario)
        ->post(route('extras.store', $registroDiario), ['texto' => 'una cerveza'])
        ->assertRedirect(route('planes.show', $registroDiario))
        ->assertSessionHas('status', 'extra-registrado');

    $extra = PlanComida::where('registro_diario_id', $registroDiario->id)->sole();

    // `snack` ya estaba en el enum desde la primera migración: no hay columna ni
    // valor nuevo. Y `origen = reporte` es lo que dice que nunca se planificó.
    expect($extra->tipo_comida)->toBe(ReporteComidaService::TIPO_EXTRA)
        ->and($extra->esReporteSinPlan())->toBeTrue()
        // Macros a cero: no se sugirió nada que cumplir.
        ->and((float) $extra->calorias_estimadas)->toBe(0.0)
        ->and((float) $extra->comidaReal->calorias_reales)->toBe(150.0);
});

it('un extra no recibe reparto: gasta el saldo del día', function () {
    Http::fake(['api.openai.com/*' => Http::response(respuestaDeExtra(150))]);

    $usuario = usuarioParaExtras();
    $registroDiario = diaParaExtras($usuario);
    $saldos = app(MealDistributionService::class);

    $antes = $saldos->saldoDelDia($registroDiario);

    $this->actingAs($usuario)->post(route('extras.store', $registroDiario), ['texto' => 'una cerveza']);

    $despues = $saldos->saldoDelDia($registroDiario->fresh());

    // El objetivo del día no se mueve —un extra no amplía el presupuesto— y lo
    // que baja es el saldo, que es lo que "Calcular mi plan" reparte entre las
    // comidas que faltan.
    expect($despues['objetivo']['calorias'])->toBe($antes['objetivo']['calorias'])
        ->and($despues['consumido']['calorias'])->toBe(150.0)
        ->and($despues['saldo']['calorias'])->toBe($antes['saldo']['calorias'] - 150.0)
        // Y no aparece como comida pendiente: un extra no se planifica, pasa.
        ->and($despues['comidas_pendientes'])->toBe(array_keys(MealPlanGeneratorService::DISTRIBUCION_COMIDAS));
});

it('cuenta TODOS los extras del día, no solo el último', function () {
    $usuario = usuarioParaExtras();
    $registroDiario = diaParaExtras($usuario);

    // Regresión directa del bug: `saldoDelDia()` recorría DISTRIBUCION_COMIDAS
    // sobre un `keyBy('tipo_comida')`, así que varios snacks colapsaban en uno.
    //
    // Secuencia y no tres `Http::fake()` seguidos: los stubs se acumulan y gana
    // el primero que case, así que las tres llamadas devolverían lo mismo y el
    // test pasaría en verde sin probar nada.
    Http::fake(['api.openai.com/*' => Http::sequence()
        ->push(respuestaDeExtra(120))
        ->push(respuestaDeExtra(90))
        ->push(respuestaDeExtra(210)),
    ]);

    foreach ([120, 90, 210] as $kcal) {
        $this->actingAs($usuario)->post(route('extras.store', $registroDiario), ['texto' => "algo de {$kcal}"]);
    }

    $saldo = app(MealDistributionService::class)->saldoDelDia($registroDiario->fresh());

    expect($saldo['consumido']['calorias'])->toBe(420.0);
});

it('el saldo en vivo y el cierre del día cuentan lo mismo', function () {
    Http::fake(['api.openai.com/*' => Http::response(respuestaDeExtra(150))]);

    $usuario = usuarioParaExtras();
    $registroDiario = diaParaExtras($usuario);

    $this->actingAs($usuario)->post(route('extras.store', $registroDiario), ['texto' => 'una cerveza']);
    $registroDiario->refresh();

    // El cierre siempre sumó todas las ComidaReal sin mirar el tipo; el saldo en
    // vivo no. Ese desacuerdo sobre el mismo día es lo que se arregló.
    $saldo = app(MealDistributionService::class)->saldoDelDia($registroDiario);
    $resumen = app(DailyClosureService::class)->resumen($registroDiario);

    expect($saldo['consumido']['calorias'])->toBe((float) $resumen['calorias_consumidas']);
});

it('borrar un extra se lleva también su PlanComida y devuelve el saldo', function () {
    Http::fake(['api.openai.com/*' => Http::response(respuestaDeExtra(150))]);

    $usuario = usuarioParaExtras();
    $registroDiario = diaParaExtras($usuario);

    $this->actingAs($usuario)->post(route('extras.store', $registroDiario), ['texto' => 'una cerveza']);
    $extra = PlanComida::where('registro_diario_id', $registroDiario->id)->sole();

    $this->actingAs($usuario)
        ->delete(route('extras.destroy', [$registroDiario, $extra]))
        ->assertSessionHas('status', 'extra-borrado');

    // Sin su ComidaReal no queda nada dentro del PlanComida, así que se va entero.
    expect(PlanComida::where('registro_diario_id', $registroDiario->id)->count())->toBe(0)
        ->and(ComidaReal::count())->toBe(0)
        ->and(app(MealDistributionService::class)->saldoDelDia($registroDiario->fresh())['consumido']['calorias'])->toBe(0.0);
});

it('no deja borrar un extra de otro día ni una comida normal', function () {
    $usuario = usuarioParaExtras();
    $registroDiario = diaParaExtras($usuario);

    // Un desayuno de verdad no es un extra, aunque se pida por esta ruta.
    $desayuno = PlanComida::factory()->for($registroDiario, 'registroDiario')->create([
        'tipo_comida' => 'desayuno',
        'origen' => PlanComida::ORIGEN_PLAN,
    ]);

    $this->actingAs($usuario)
        ->delete(route('extras.destroy', [$registroDiario, $desayuno]))
        ->assertSessionHas('status', 'extra-no-encontrado');

    expect(PlanComida::whereKey($desayuno->id)->exists())->toBeTrue();
});

it('el día de otra persona da 403', function () {
    $registroDiario = diaParaExtras(usuarioParaExtras());

    $this->actingAs(usuarioParaExtras())
        ->post(route('extras.store', $registroDiario), ['texto' => 'una cerveza'])
        ->assertForbidden();
});

it('sin decir qué se consumió, lo explica en vez de reventar', function () {
    $usuario = usuarioParaExtras();
    $registroDiario = diaParaExtras($usuario);

    $this->actingAs($usuario)
        ->post(route('extras.store', $registroDiario), ['texto' => ''])
        ->assertRedirect(route('planes.show', $registroDiario))
        ->assertSessionHas('error');

    expect(PlanComida::count())->toBe(0);
});

it('repetir un extra frecuente no llama al proveedor', function () {
    Http::fake(['api.openai.com/*' => Http::sequence()
        ->push(respuestaDeExtra(150))
        ->push(respuestaDeExtra(150)),
    ]);

    $usuario = usuarioParaExtras();

    // Dos días con la misma gaseosa: a partir de REPETICIONES_MINIMAS se ofrece.
    foreach ([1, 2] as $diasAtras) {
        $dia = RegistroDiario::factory()->for($usuario, 'usuario')->create([
            'fecha' => now()->subDays($diasAtras)->toDateString(),
        ]);
        $this->actingAs($usuario)->post(route('extras.store', $dia), ['texto' => 'una gaseosa']);
    }

    Http::assertSentCount(2);

    $frecuentes = app(ComidasFrecuentesService::class)
        ->paraComida($usuario, ReporteComidaService::TIPO_EXTRA);

    expect($frecuentes)->not->toBeEmpty();

    $hoy = diaParaExtras($usuario);

    $this->actingAs($usuario)->post(route('extras.store', $hoy), [
        'texto' => $frecuentes->first()['etiqueta'],
        'repetir' => $frecuentes->first()['comida_real_id'],
    ]);

    // Ni una llamada más: esos macros ya se calcularon el día que se reportó.
    Http::assertSentCount(2);

    expect(app(MealDistributionService::class)->saldoDelDia($hoy->fresh())['consumido']['calorias'])->toBe(150.0);
});

it('al estimar un extra no le manda al modelo ningún plan de referencia', function () {
    Http::fake(['api.openai.com/*' => Http::response(respuestaDeExtra(150))]);

    $usuario = usuarioParaExtras();
    $registroDiario = diaParaExtras($usuario);

    $this->actingAs($usuario)->post(route('extras.store', $registroDiario), ['texto' => 'una cerveza']);

    Http::assertSent(function ($peticion) {
        $prompt = collect($peticion->data()['messages'])->pluck('content')->implode("\n");

        // Un plan de ceros no dice "no había plan": dice "se le sugirió no comer
        // nada", que es una referencia falsa y además tira de la estimación
        // hacia abajo.
        return ! str_contains($prompt, 'Plan que se le había sugerido')
            && str_contains($prompt, 'Es un EXTRA');
    });
});

it('la tarjeta de Extras aparece en el plan y resume su total en la cabecera', function () {
    Http::fake(['api.openai.com/*' => Http::response(respuestaDeExtra(150))]);

    $usuario = usuarioParaExtras();
    $registroDiario = diaParaExtras($usuario);

    // Discreta pero visible: está siempre, y vacía lo dice sin ocupar pantalla.
    $this->actingAs($usuario)->get(route('planes.show', $registroDiario))
        ->assertOk()
        ->assertSee('Extras')
        ->assertSee('nada aún');

    $this->actingAs($usuario)->post(route('extras.store', $registroDiario), ['texto' => 'una cerveza']);

    $this->actingAs($usuario)->get(route('planes.show', $registroDiario))
        ->assertOk()
        ->assertSee('150 kcal')
        ->assertSee('una cerveza');
});

it('un extra no impide cerrar el día ni cuenta como comida sin reportar', function () {
    Http::fake(['api.openai.com/*' => Http::response(respuestaDeExtra(150))]);

    $usuario = usuarioParaExtras();
    $registroDiario = diaParaExtras($usuario);

    $this->actingAs($usuario)->post(route('extras.store', $registroDiario), ['texto' => 'una cerveza']);
    $registroDiario->refresh();

    // Las tres comidas siguen sin reportar: un extra no es una de ellas.
    expect(app(DailyClosureService::class)->comidasSinReportar($registroDiario))
        ->toBe(array_keys(MealPlanGeneratorService::DISTRIBUCION_COMIDAS));

    // Y sus calorías sí entran en el cierre, que es lo que importa del déficit.
    $this->actingAs($usuario)->post(route('cierre.cerrar', $registroDiario), ['confirmar_sin_reportar' => '1']);

    expect((float) $registroDiario->fresh()->calorias_consumidas)->toBe(150.0);
});
