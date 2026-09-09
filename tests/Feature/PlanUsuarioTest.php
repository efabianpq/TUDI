<?php

use App\Models\ActividadFisica;
use App\Models\PlanComida;
use App\Models\RegistroDiario;
use App\Models\User;
use App\Services\PlanService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;

/**
 * Plan del usuario: gratis / trial / premium (CLAUDE.md sección 5.18).
 *
 * Lo que se fija aquí es el contrato que anuncia la landing: registrarse
 * estrena Premium sin pedirlo, vencer la prueba no le quita a nadie la
 * aplicación ni sus datos, y las funciones que llaman al proveedor de IA
 * respetan el plan vigente en tiempo real.
 */
beforeEach(function () {
    config([
        'services.gemini.key' => 'clave-de-prueba',
        'services.gemini.model' => 'gemini-2.5-flash',
        'services.gemini.endpoint' => 'https://generativelanguage.googleapis.com/v1beta/models',
    ]);
});

function usuarioConPerfil(array $sobrescribir = []): User
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
        'calorias_objetivo' => 2112,
    ], $sobrescribir));
}

/**
 * Día de hoy con el almuerzo planificado y sin registrar: es lo que necesita el
 * cierre para tener algo que preguntar.
 */
function diaConAlmuerzoPlanificado(User $usuario): RegistroDiario
{
    $registroDiario = RegistroDiario::factory()->for($usuario, 'usuario')->create([
        'fecha' => now()->toDateString(),
    ]);

    PlanComida::factory()->for($registroDiario, 'registroDiario')->create([
        'tipo_comida' => 'almuerzo',
        'calorias_estimadas' => 845,
        'proteina_g' => 60,
        'grasa_g' => 25,
        'carbohidratos_g' => 70,
    ]);

    ActividadFisica::factory()->for($registroDiario, 'registroDiario')->create([
        'tipo' => 'caminata',
        'calorias_dispositivo' => 400,
        'factor_correccion' => 0.85,
        'calorias_ajustadas' => 340,
    ]);

    return $registroDiario;
}

/** Un WAV mínimo pero válido: la validación mira el contenido, no la extensión. */
function audioParaPlan(): UploadedFile
{
    $muestras = str_repeat("\x00\x00", 800);
    $datos = 'data'.pack('V', strlen($muestras)).$muestras;
    $formato = 'fmt '.pack('VvvVVvv', 16, 1, 1, 8000, 16000, 2, 16);
    $cuerpo = 'WAVE'.$formato.$datos;

    $ruta = tempnam(sys_get_temp_dir(), 'tudi').'.wav';
    file_put_contents($ruta, 'RIFF'.pack('V', strlen($cuerpo)).$cuerpo);

    return new UploadedFile($ruta, 'dictado.wav', 'audio/wav', null, true);
}

// ── Vencimiento automático ───────────────────────────────────────────────────

it('pasa a Gratis las pruebas vencidas y deja intactas las que siguen vivas', function () {
    $vencida = User::factory()->pruebaVencida()->create();
    $viva = User::factory()->enPrueba(3)->create();
    $premium = User::factory()->create(['plan' => User::PLAN_PREMIUM]);

    $this->artisan('app:expirar-pruebas')
        ->expectsOutputToContain('Pruebas de Premium vencidas y pasadas a Gratis: 1.')
        ->assertSuccessful();

    expect($vencida->fresh()->plan)->toBe(User::PLAN_GRATIS)
        ->and($vencida->fresh()->plan_expira_en)->toBeNull()
        ->and($viva->fresh()->plan)->toBe(User::PLAN_TRIAL)
        ->and($premium->fresh()->plan)->toBe(User::PLAN_PREMIUM);
});

it('cuenta como Gratis una prueba vencida aunque el cron todavía no haya pasado', function () {
    // El comando ordena la tabla; quien decide es el reloj (sección 5.18).
    $usuario = User::factory()->pruebaVencida()->create();

    expect($usuario->plan)->toBe(User::PLAN_TRIAL)
        ->and($usuario->tienePremium())->toBeFalse()
        ->and($usuario->pruebaTerminada())->toBeTrue()
        ->and($usuario->diasDePruebaRestantes())->toBeNull();
});

it('vencer la prueba no borra ni un dato del historial', function () {
    $usuario = usuarioConPerfil()->refresh();
    $usuario->forceFill(['plan' => User::PLAN_TRIAL, 'plan_expira_en' => now()->subDay()])->save();

    $registroDiario = diaConAlmuerzoPlanificado($usuario);

    app(PlanService::class)->expirarVencidos();

    expect($usuario->fresh()->plan)->toBe(User::PLAN_GRATIS)
        ->and(RegistroDiario::find($registroDiario->id))->not->toBeNull()
        ->and($registroDiario->planesComida()->count())->toBe(1)
        ->and($registroDiario->actividadesFisicas()->count())->toBe(1);
});

it('deja entrar a toda la aplicación con el plan Gratis', function () {
    // Requisito de la sección 5.18: ningún plan deja a nadie fuera.
    $usuario = usuarioConPerfil(['plan' => User::PLAN_GRATIS]);

    foreach (['dashboard', 'calculadora.edit', 'planes.index'] as $ruta) {
        $this->actingAs($usuario)->get(route($ruta))->assertOk();
    }
});

// ── Control de acceso: distribución de comidas ───────────────────────────────

it('no llama al proveedor para distribuir comidas en el plan Gratis', function () {
    Http::fake();

    $usuario = usuarioConPerfil(['plan' => User::PLAN_GRATIS]);
    $registroDiario = RegistroDiario::factory()->for($usuario, 'usuario')->create([
        'fecha' => now()->toDateString(),
    ]);

    $this->actingAs($usuario)->post(route('planes.distribucion', $registroDiario), [
        'ingredientes' => ['desayuno' => 'dos huevos y media palta'],
    ])->assertSessionHas('error');

    expect(session('error'))->toContain('Premium');

    // Ni una llamada facturable, y ningún PlanComida a medias.
    Http::assertNothingSent();
    expect($registroDiario->planesComida()->count())->toBe(0);
});

it('guarda igualmente lo que el usuario escribió aunque su plan no lo distribuya', function () {
    Http::fake();

    $usuario = usuarioConPerfil(['plan' => User::PLAN_GRATIS]);
    $registroDiario = RegistroDiario::factory()->for($usuario, 'usuario')->create([
        'fecha' => now()->toDateString(),
    ]);

    $this->actingAs($usuario)->post(route('planes.distribucion', $registroDiario), [
        'ingredientes' => ['desayuno' => 'dos huevos y media palta'],
    ]);

    expect($registroDiario->fresh()->ingredientes_desayuno)->toBe('dos huevos y media palta');
});

it('distribuye con normalidad durante la prueba', function () {
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
        'candidates' => [[
            'content' => ['parts' => [['text' => json_encode(['comidas' => [[
                'tipo_comida' => 'desayuno',
                'descripcion' => 'Huevos revueltos con palta',
                'preparacion' => 'Revuelve los huevos a fuego bajo.',
                'notas' => '',
                'ingredientes' => [
                    ['nombre' => 'Huevo', 'porcion' => '2 unidades', 'cantidad_g' => 100, 'calorias' => 143, 'proteina_g' => 12.6, 'grasa_g' => 9.5, 'carbohidratos_g' => 0.7],
                ],
            ]]])]]],
            'finishReason' => 'STOP',
        ]],
    ])]);

    $usuario = usuarioConPerfil();
    $usuario->forceFill(['plan' => User::PLAN_TRIAL, 'plan_expira_en' => now()->addDays(3)])->save();

    $registroDiario = RegistroDiario::factory()->for($usuario, 'usuario')->create([
        'fecha' => now()->toDateString(),
    ]);

    $this->actingAs($usuario)->post(route('planes.distribucion', $registroDiario), [
        'ingredientes' => ['desayuno' => 'dos huevos y media palta'],
    ])->assertSessionHas('status', 'distribucion-generada');

    expect($registroDiario->planesComida()->count())->toBe(1);
});

// ── Control de acceso: estimación de consumo real en el cierre ───────────────

it('no estima con IA lo que se comió en el plan Gratis, y deja el día sin cerrar', function () {
    Http::fake();

    $usuario = usuarioConPerfil(['plan' => User::PLAN_GRATIS]);
    $registroDiario = diaConAlmuerzoPlanificado($usuario);

    $this->actingAs($usuario)->post(route('cierre.cerrar', $registroDiario), [
        'feedback' => ['almuerzo' => ['texto' => 'al final me comí un sándwich']],
    ])->assertSessionHas('error');

    expect(session('error'))->toContain('Premium')
        ->and($registroDiario->fresh()->cerrado)->toBeFalse();

    Http::assertNothingSent();
});

it('cierra el día en el plan Gratis por el camino que no cuesta una llamada', function () {
    Http::fake();

    $usuario = usuarioConPerfil(['plan' => User::PLAN_GRATIS]);
    $registroDiario = diaConAlmuerzoPlanificado($usuario);

    // "Sí, lo cumplí" no pasa por el proveedor: el cierre diario es gratis.
    $this->actingAs($usuario)->post(route('cierre.cerrar', $registroDiario), [
        'feedback' => ['almuerzo' => ['cumplio' => '1']],
    ])->assertSessionHas('status', 'dia-cerrado');

    expect($registroDiario->fresh()->cerrado)->toBeTrue()
        ->and((float) $registroDiario->fresh()->calorias_consumidas)->toBe(845.0);

    Http::assertNothingSent();
});

// ── Control de acceso: plan B del dictado por voz ────────────────────────────

it('no transcribe audio en el servidor con el plan Gratis', function () {
    config(['services.transcripcion.fallback_servidor' => true]);
    Http::fake();

    $usuario = User::factory()->gratis()->create();

    $this->actingAs($usuario)
        ->postJson(route('transcribir'), ['audio' => audioParaPlan()])
        ->assertStatus(422)
        ->assertJsonPath('error', fn (string $error): bool => str_contains($error, 'Premium'));

    Http::assertNothingSent();
});

it('transcribe audio en el servidor durante la prueba', function () {
    config(['services.transcripcion.fallback_servidor' => true]);

    Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
        'candidates' => [[
            'content' => ['role' => 'model', 'parts' => [['text' => 'dos huevos y media palta']]],
            'finishReason' => 'STOP',
        ]],
    ])]);

    $usuario = User::factory()->enPrueba(2)->create();

    $this->actingAs($usuario)
        ->postJson(route('transcribir'), ['audio' => audioParaPlan()])
        ->assertOk()
        ->assertExactJson(['texto' => 'dos huevos y media palta']);
});

// ── Motor de recomendaciones e historial ─────────────────────────────────────

it('el motor de recomendaciones solo corre con Premium', function (string $plan, int $esperadas) {
    Http::fake();

    $usuario = usuarioConPerfil(['plan' => $plan]);

    /*
     * Historial con el que el motor SÍ tiene algo que decir: dos ventanas de 7
     * días cerradas y con peso, y una pérdida de 100 g en la última semana —
     * muy por debajo del umbral lento del 0,5 %, así que corresponde sugerir
     * reducir el objetivo. El mismo fixture con los dos planes es lo que hace
     * que el caso "Gratis → 0" signifique algo: sin la fila de Premium sería un
     * cero que podría venir de un historial insuficiente.
     */
    foreach (range(1, 14) as $atras) {
        RegistroDiario::factory()->for($usuario, 'usuario')->cerrado()->create([
            'fecha' => now()->subDays($atras)->toDateString(),
            'peso_kg' => $atras <= 7 ? 79.9 : 80.0,
        ]);
    }

    $registroDiario = diaConAlmuerzoPlanificado($usuario);

    $this->actingAs($usuario)->post(route('cierre.cerrar', $registroDiario), [
        'feedback' => ['almuerzo' => ['cumplio' => '1']],
    ])->assertSessionHas('status', 'dia-cerrado');

    expect($registroDiario->fresh()->recomendacionesSistema()->count())->toBe($esperadas);
})->with([
    'plan Gratis' => [User::PLAN_GRATIS, 0],
    'plan Premium' => [User::PLAN_PREMIUM, 1],
]);

it('recorta el seguimiento en el plan Gratis y lo devuelve entero con Premium', function () {
    $gratis = usuarioConPerfil(['plan' => User::PLAN_GRATIS]);

    $this->actingAs($gratis)->get(route('dashboard'))
        ->assertOk()
        ->assertViewHas('premium', false)
        ->assertViewHas('semanas', fn (array $semanas): bool => count($semanas) === 1)
        ->assertSee('El motor de ajustes es parte de Premium. Tus cifras se siguen guardando.');

    $premium = usuarioConPerfil();

    $this->actingAs($premium)->get(route('dashboard'))
        ->assertOk()
        ->assertViewHas('premium', true)
        ->assertViewHas('semanas', fn (array $semanas): bool => count($semanas) === 6);
});

it('avisa en Inicio de los días de prueba que quedan, sin bloquear nada', function () {
    $usuario = usuarioConPerfil();
    $usuario->forceFill(['plan' => User::PLAN_TRIAL, 'plan_expira_en' => now()->addDays(3)])->save();

    $this->actingAs($usuario)->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Te quedan 3 días de Premium.');
});

// ── Landing pública ──────────────────────────────────────────────────────────

it('enseña los precios de config/planes.php y manda los dos botones al registro', function () {
    $respuesta = $this->get('/')->assertOk();

    $respuesta->assertSee('COP $14.900')
        ->assertSee('COP $119.000')
        // El descuento se deriva de los dos importes, no se escribe a mano.
        ->assertSee('33% menos')
        ->assertSee('Crear cuenta gratis')
        ->assertSee('Probar '.config('planes.prueba_dias').' días gratis')
        ->assertSee('No se pide tarjeta para registrarte. Cuando abramos el cobro será con tarjeta, PSE o Nequi.');
});

it('deriva el precio y el descuento de la configuración, no de la vista', function () {
    config(['planes.precio.mensual' => 20000, 'planes.precio.anual' => 180000]);

    $precios = app(PlanService::class)->precios();

    expect($precios['mensual_formateado'])->toBe('COP $20.000')
        ->and($precios['anual_formateado'])->toBe('COP $180.000')
        // 180.000 frente a 240.000 son un 25 % menos.
        ->and($precios['ahorro_anual_pct'])->toBe(25);
});
