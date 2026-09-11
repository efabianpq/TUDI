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
        // El día sigue abierto: "te quedan", nunca un sustantivo de resultado
        // como "déficit" (CLAUDE.md sección 5.8).
        ->assertSee('Te quedan')
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

    // Una sola lista: cada tarjeta lleva dentro todo lo de esa comida —los
    // ingredientes, lo planificado y su cierre (sección 5.5)—, así que tres
    // acordeones y uno solo abierto. La sección de actividad física es otro
    // <details> y no entra en la cuenta.
    expect(substr_count($contenido, 'data-comida="'))->toBe(3)
        ->and(substr_count($contenido, 'data-comida="desayuno" open>'))->toBe(1)
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

it('pregunta el cumplimiento de cada comida con un interruptor, en su propio cierre', function () {
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
        // Cada comida se cierra por separado (sección 5.5): la pregunta vive
        // en su propia tarjeta, no en una batería al final del día.
        ->assertSee(route('comidas.cerrar', [$registroDiario, 'cena']))
        ->assertSee('Cumplí lo sugerido')
        ->assertSee('tudi-switch', escape: false)
        ->assertSee('Cerrar cena')
        // Y cerrar el día ya no pregunta nada: solo valida que haya algo que
        // consolidar (sección 5.5).
        ->assertSee('Cierra al menos una comida antes de cerrar el día.');
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

    expect(strpos($contenido, 'Tu objetivo diario'))->toBeLessThan(strpos($contenido, '¿Qué tan activo eres?'))
        // El panel arranca con el objetivo vigente (que una recomendación
        // confirmada puede haber movido), no con el derivado de la fórmula.
        ->and($contenido)->toContain('\u0022vigente\u0022:1950');
});

/*
|--------------------------------------------------------------------------
| Poda del plan diario (CLAUDE.md sección 4.23)
|--------------------------------------------------------------------------
*/

it('ofrece un solo "Calcular mi plan" para las tres comidas, no uno por comida', function () {
    $usuario = usuarioDelRediseno();
    $registroDiario = RegistroDiario::factory()->for($usuario, 'usuario')->create([
        'fecha' => now()->toDateString(),
    ]);

    $contenido = $this->actingAs($usuario)->get(route('planes.show', $registroDiario))
        ->assertOk()
        ->assertSee('Reparte lo que te queda del día entre las comidas que faltan.')
        ->getContent();

    // Un único botón, y los tres textareas dentro del mismo formulario para que
    // viajen juntos en una sola petición al proveedor.
    expect(substr_count($contenido, 'Calcular mi plan'))->toBe(1)
        ->and(substr_count($contenido, 'name="ingredientes['))->toBe(3);
});

it('esconde el botón de distribución cuando ya no queda ninguna comida por resolver', function () {
    $usuario = usuarioDelRediseno();
    $registroDiario = RegistroDiario::factory()->for($usuario, 'usuario')->create([
        'fecha' => now()->toDateString(),
    ]);

    foreach (['desayuno', 'almuerzo', 'cena'] as $tipoComida) {
        $plan = PlanComida::factory()->for($registroDiario, 'registroDiario')->create([
            'tipo_comida' => $tipoComida,
        ]);

        ComidaReal::factory()->for($plan, 'planComida')->create();
    }

    $this->actingAs($usuario)->get(route('planes.show', $registroDiario))
        ->assertOk()
        ->assertDontSee('Generar distribución');
});

it('ya no ofrece "Registrar" por comida: lo que se comió se cuenta al cerrar el día', function () {
    $usuario = usuarioDelRediseno();
    $registroDiario = RegistroDiario::factory()->for($usuario, 'usuario')->create([
        'fecha' => now()->toDateString(),
    ]);

    $plan = PlanComida::factory()->for($registroDiario, 'registroDiario')->create([
        'tipo_comida' => 'almuerzo',
    ]);

    $this->actingAs($usuario)->get(route('planes.show', $registroDiario))
        ->assertOk()
        // El enlace a la pantalla de "Registrar con detalle" desapareció...
        ->assertDontSee(route('comida-real.create', $plan))
        // ...y "Rehacer" sigue estando, que es lo que sí pertenece a esta sección.
        ->assertSee('Rehacer solo el almuerzo')
        // La foto de evidencia se adjunta ahora al cerrar esa comida.
        ->assertSee(route('comidas.cerrar', [$registroDiario, 'almuerzo']))
        ->assertSee('name="imagen"', escape: false)
        ->assertSee('Adjuntar foto (opcional)');
});

it('agrupa la actividad física en una sola sección desplegable', function () {
    $usuario = usuarioDelRediseno();
    $registroDiario = RegistroDiario::factory()->for($usuario, 'usuario')->create([
        'fecha' => now()->toDateString(),
    ]);

    ActividadFisica::factory()->for($registroDiario, 'registroDiario')->create([
        'tipo' => 'caminata',
        'duracion_min' => 45,
        'calorias_dispositivo' => 400,
        'factor_correccion' => 0.85,
        'calorias_ajustadas' => 340,
    ]);

    $contenido = $this->actingAs($usuario)->get(route('planes.show', $registroDiario))
        ->assertOk()
        // La cabecera resume lo hecho contra el objetivo sin necesidad de abrirla.
        ->assertSee('1 actividad')
        ->assertSee('Para llegar a tu objetivo de hoy')
        ->getContent();

    // Sugerencia y registro comparten una sola tarjeta, no dos.
    expect(substr_count($contenido, 'id="seccion-actividad"'))->toBe(1)
        ->and(substr_count($contenido, 'Guardar actividad'))->toBe(1);
});

it('avisa al usuario mientras la IA responde', function () {
    $usuario = usuarioDelRediseno();
    $registroDiario = RegistroDiario::factory()->for($usuario, 'usuario')->create([
        'fecha' => now()->toDateString(),
    ]);

    $this->actingAs($usuario)->get(route('planes.show', $registroDiario))
        ->assertOk()
        ->assertSee('id="tudi-cargando"', escape: false)
        ->assertSee('data-cargando="Ajustando tu plan…"', escape: false)
        ->assertSee('data-cargando="Cerrando tu día…"', escape: false)
        // Cerrar una comida puede pasar por la IA: también avisa (regla 11).
        ->assertSee('data-cargando="Cerrando tu desayuno…"', escape: false);
});

it('acepta decimales tecleados con coma en el peso del día', function () {
    $usuario = usuarioDelRediseno();
    $registroDiario = RegistroDiario::factory()->for($usuario, 'usuario')->create([
        'fecha' => now()->toDateString(),
    ]);

    // El campo es inputmode="decimal", no type="number": lo que llega es texto
    // y puede traer coma decimal (CLAUDE.md sección 4.24).
    $this->actingAs($usuario)
        ->post(route('planes.peso', $registroDiario), ['peso_kg' => '80,4'])
        ->assertRedirect();

    expect((float) $registroDiario->fresh()->peso_kg)->toBe(80.4);
});

/*
|--------------------------------------------------------------------------
| Legibilidad de los macros y safe area (CLAUDE.md sección 5.12)
|--------------------------------------------------------------------------
*/

it('descuenta el safe area superior para que la barra no quede bajo la del sistema', function () {
    // Instalada como app en iOS, la barra de estado es translúcida y la página
    // empieza debajo del reloj: sin este padding el menú de la cuenta quedaba
    // solapado y no se podía pulsar.
    $this->actingAs(usuarioDelRediseno())->get(route('dashboard'))
        ->assertOk()
        ->assertSee('pt-[env(safe-area-inset-top)]', escape: false)
        ->assertSee('pt-[calc(env(safe-area-inset-top)+3.75rem)]', escape: false);
});

it('pone la misma barra superior en todas las pantallas, no solo en Inicio', function () {
    $usuario = usuarioDelRediseno();

    $registroDiario = RegistroDiario::factory()->for($usuario, 'usuario')->create([
        'fecha' => now()->toDateString(),
    ]);

    // La barra vive en el layout, así que marca, fecha y menú de la cuenta
    // están en todas partes (CLAUDE.md sección 5.12). Antes cada vista montaba
    // su cabecera y solo algunas incluían el menú.
    foreach ([
        route('dashboard'),
        route('calculadora.edit'),
        route('planes.index'),
        route('planes.show', $registroDiario),
        route('profile.edit'),
    ] as $url) {
        $this->actingAs($usuario)->get($url)
            ->assertOk()
            ->assertSee('tudi-topbar', escape: false)
            ->assertSee('Cerrar sesión')
            ->assertSee('Mi cuenta');
    }
});

it('no repite el menú de la cuenta dentro de las pantallas', function () {
    // Una sola instancia por página: la del layout. Dos menús abiertos a la vez
    // con el mismo x-data era el síntoma de que cada vista traía el suyo.
    $html = $this->actingAs(usuarioDelRediseno())->get(route('dashboard'))->getContent();

    expect(substr_count($html, 'aria-haspopup="true"'))->toBe(1);
});

it('nombra los macros con la palabra completa donde cabe, no con la inicial', function () {
    $usuario = usuarioDelRediseno();

    $registroDiario = RegistroDiario::factory()->for($usuario, 'usuario')->create([
        'fecha' => now()->toDateString(),
    ]);

    $this->actingAs($usuario)->get(route('planes.show', $registroDiario))
        ->assertOk()
        ->assertSee('Proteína')
        ->assertSee('Grasas')
        ->assertSee('Carbohidratos');

    $this->actingAs($usuario)->get(route('calculadora.edit'))
        ->assertOk()
        ->assertSee('Carbohidratos');
});

it('etiqueta los macros de cada comida con su icono ilustrado', function () {
    $usuario = usuarioDelRediseno();

    $registroDiario = RegistroDiario::factory()->for($usuario, 'usuario')->create([
        'fecha' => now()->toDateString(),
    ]);

    PlanComida::factory()->for($registroDiario, 'registroDiario')->create([
        'tipo_comida' => 'desayuno',
        'calorias_estimadas' => 500,
        'proteina_g' => 30,
        'grasa_g' => 15,
        'carbohidratos_g' => 55,
    ]);

    $this->actingAs($usuario)->get(route('planes.show', $registroDiario))
        ->assertOk()
        ->assertSee('icons/macros/proteina.png', escape: false)
        ->assertSee('icons/macros/grasa.png', escape: false)
        ->assertSee('icons/macros/carbohidratos.png', escape: false);
});

it('muestra en el cierre el resultado real frente al objetivo, macro a macro', function () {
    $usuario = usuarioDelRediseno();

    $registroDiario = RegistroDiario::factory()->for($usuario, 'usuario')->create([
        'fecha' => now()->toDateString(),
    ]);

    $plan = PlanComida::factory()->for($registroDiario, 'registroDiario')->create([
        'tipo_comida' => 'almuerzo',
        'calorias_estimadas' => 800,
    ]);

    ComidaReal::factory()->for($plan, 'planComida')->create([
        'calorias_reales' => 800,
        'proteina_g' => 50,
        'grasa_g' => 25,
        'carbohidratos_g' => 90,
    ]);

    $this->actingAs($usuario)->get(route('planes.show', $registroDiario))
        ->assertOk()
        // Con el día abierto es una vista previa de lo que llevas comido…
        ->assertSee('Lo que llevas comido')
        ->assertSee('25,0');

    $this->actingAs($usuario)->post(route('cierre.cerrar', $registroDiario), [
        'confirmar_sin_reportar' => '1',
    ]);

    // …y una vez cerrado, el resultado congelado del día.
    $this->actingAs($usuario)->get(route('planes.show', $registroDiario))
        ->assertOk()
        ->assertSee('Resultado real del día')
        ->assertSee('25,0');
});
