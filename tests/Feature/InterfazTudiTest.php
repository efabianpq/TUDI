<?php

use App\Models\ActividadFisica;
use App\Models\ComidaReal;
use App\Models\PlanComida;
use App\Models\RegistroDiario;
use App\Models\User;

/**
 * Rediseño TUDI (CLAUDE.md sección 4.18): lo que la interfaz promete y que no
 * se puede comprobar leyendo solo el texto de las pantallas — el anillo con su
 * avance, el acordeón de una sola comida abierta, los controles táctiles de la
 * calculadora y los interruptores del cierre.
 *
 * Mismo perfil que DashboardTest: objetivo = 80 * 22 * 1.5 * 0.8 = 2112 kcal.
 */
function usuarioDelRediseno(array $sobrescribir = []): User
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

it('marca el destino activo de la navegación con aria-current', function () {
    $usuario = usuarioDelRediseno();

    $this->actingAs($usuario)->get(route('planes.index'))
        ->assertOk()
        // Barra inferior en móvil + barra lateral en escritorio, los dos
        // destinos activos marcados para lectores de pantalla.
        ->assertSee('tudi-tabbar', escape: false)
        ->assertSee('aria-current="page"', escape: false);
});

it('muestra el déficit del día como anillo de progreso, no como tabla de cifras', function () {
    $usuario = usuarioDelRediseno();

    $registroDiario = RegistroDiario::factory()->for($usuario, 'usuario')->create([
        'fecha' => now()->toDateString(),
    ]);

    $desayuno = PlanComida::factory()->for($registroDiario, 'registroDiario')->create([
        'tipo_comida' => 'desayuno',
        'calorias_estimadas' => 528,
    ]);

    ComidaReal::factory()->for($desayuno, 'planComida')->create([
        'calorias_reales' => 1056,
        'proteina_g' => 80,
    ]);

    ActividadFisica::factory()->for($registroDiario, 'registroDiario')->create([
        'calorias_dispositivo' => 400,
        'factor_correccion' => 0.85,
        'calorias_ajustadas' => 340,
    ]);

    // Presupuesto del día = 2112 objetivo + 340 de actividad = 2452;
    // consumidas 1056 → 43% de avance del anillo.
    $this->actingAs($usuario)->get(route('dashboard'))
        ->assertOk()
        ->assertSee('tudi-ring', escape: false)
        ->assertSee('--pct: 43', escape: false)
        ->assertSee('kcal por debajo')
        // La tabla de cuatro cifras que había antes ya no está.
        ->assertDontSee('Consumidas / objetivo');
});

it('abre una sola comida a la vez en el plan diario', function () {
    $usuario = usuarioDelRediseno();
    $registroDiario = RegistroDiario::factory()->for($usuario, 'usuario')->create([
        'fecha' => now()->toDateString(),
    ]);

    $contenido = $this->actingAs($usuario)->get(route('planes.show', $registroDiario))
        ->assertOk()
        ->getContent();

    expect(substr_count($contenido, '<details'))->toBe(3)
        ->and(substr_count($contenido, 'data-comida="desayuno" open>'))->toBe(1)
        // Ninguna otra abierta: el acordeón deja ver una comida a la vez.
        ->and(substr_count($contenido, ' open>'))->toBe(1);
});

it('abre la primera comida que queda por resolver, no una ya registrada', function () {
    $usuario = usuarioDelRediseno();
    $registroDiario = RegistroDiario::factory()->for($usuario, 'usuario')->create([
        'fecha' => now()->toDateString(),
    ]);

    foreach (['desayuno', 'almuerzo'] as $tipoComida) {
        $plan = PlanComida::factory()->for($registroDiario, 'registroDiario')->create([
            'tipo_comida' => $tipoComida,
        ]);

        ComidaReal::factory()->for($plan, 'planComida')->create();
    }

    $this->actingAs($usuario)->get(route('planes.show', $registroDiario))
        ->assertOk()
        ->assertSee('data-comida="cena" open>', escape: false);
});

it('el campo de ingredientes lleva la ayuda dentro, como placeholder', function () {
    $usuario = usuarioDelRediseno();
    $registroDiario = RegistroDiario::factory()->for($usuario, 'usuario')->create([
        'fecha' => now()->toDateString(),
    ]);

    $this->actingAs($usuario)->get(route('planes.show', $registroDiario))
        ->assertOk()
        ->assertSee('placeholder="huevos, queso chitagá y tinto"', escape: false)
        // La explicación larga vive detrás del enlace, no en la pantalla.
        ->assertSee('¿Cómo funciona?')
        ->assertDontSee('Cuéntanos en un párrafo qué tienes disponible');
});

it('pregunta el cumplimiento de cada comida con un interruptor', function () {
    $usuario = usuarioDelRediseno();
    $registroDiario = RegistroDiario::factory()->for($usuario, 'usuario')->create([
        'fecha' => now()->toDateString(),
    ]);

    PlanComida::factory()->for($registroDiario, 'registroDiario')->create([
        'tipo_comida' => 'cena',
        'calorias_estimadas' => 740,
    ]);

    $this->actingAs($usuario)->get(route('planes.show', $registroDiario))
        ->assertOk()
        ->assertSee('feedback[cena][cumplio]', escape: false)
        ->assertSee('tudi-switch', escape: false)
        ->assertSee('Cerrar mi día')
        ->assertSee('Al cerrar se congelan tus cifras del día.');
});

it('la calculadora manda los valores de sus controles táctiles', function () {
    $usuario = usuarioDelRediseno();

    $this->actingAs($usuario)->get(route('calculadora.edit'))
        ->assertOk()
        // Los segmentados y el slider escriben en campos ocultos con el valor
        // ya guardado, así que el formulario sigue siendo válido sin tocar nada.
        ->assertSee('name="sexo" value="masculino"', escape: false)
        ->assertSee('name="nivel_actividad" value="1.5"', escape: false)
        ->assertSee('name="tipo_deficit" value="porcentaje"', escape: false)
        ->assertSee('name="valor_deficit" value="0.20"', escape: false)
        ->assertSee('Guardar y recalcular');
});

it('la calculadora enseña el objetivo vigente arriba, antes que los controles', function () {
    $usuario = usuarioDelRediseno(['calorias_objetivo' => 1950]);

    $contenido = $this->actingAs($usuario)->get(route('calculadora.edit'))
        ->assertOk()
        ->getContent();

    expect(strpos($contenido, 'Tu objetivo diario'))->toBeLessThan(strpos($contenido, 'Nivel de actividad'))
        // El panel arranca con el objetivo vigente (que una recomendación
        // confirmada puede haber movido), no con el derivado de la fórmula.
        ->and($contenido)->toContain('\u0022vigente\u0022:1950');
});
