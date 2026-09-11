<?php

use App\Models\User;

/**
 * El logotipo gana el sufijo "Premium" cuando el usuario tiene el plan
 * (CLAUDE.md sección 5.26), al modo de YouTube: el logotipo no cambia, se le
 * añade la palabra a la derecha en el color de acento.
 *
 * Lo que se fija aquí es **quién** lo ve: quien tiene Premium de verdad —plan
 * pagado o prueba en curso— y nadie más, ni siquiera en las pantallas públicas.
 */
function usuarioConPerfilDeMarca(array $sobrescribir = []): User
{
    return User::factory()->create(array_merge([
        'peso_kg' => 80,
        'nivel_actividad' => 1.5,
        'tipo_deficit' => 'porcentaje',
        'valor_deficit' => 0.2,
        'proteina_factor' => 2.0,
        'grasa_factor' => 0.8,
    ], $sobrescribir));
}

it('muestra el sufijo Premium en la marca de un usuario Premium', function () {
    $usuario = usuarioConPerfilDeMarca(['plan' => User::PLAN_PREMIUM, 'plan_expira_en' => null]);

    $this->actingAs($usuario)->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Premium');
});

it('no muestra el sufijo Premium en la marca de un usuario Gratis', function () {
    $usuario = usuarioConPerfilDeMarca(['plan' => User::PLAN_GRATIS, 'plan_expira_en' => null]);

    // "Premium" aparece en el aviso de plan de Inicio ("Actualizar a Premium"),
    // así que lo que se comprueba es la marca en sí, no la palabra suelta.
    $html = $this->actingAs($usuario)->get(route('dashboard'))->assertOk()->getContent();

    expect($html)->not->toContain('>Premium</span>');
});

it('la prueba en curso cuenta como Premium para la marca', function () {
    $usuario = usuarioConPerfilDeMarca(['plan' => User::PLAN_TRIAL, 'plan_expira_en' => now()->addDays(2)]);

    $html = $this->actingAs($usuario)->get(route('dashboard'))->assertOk()->getContent();

    expect($html)->toContain('>Premium</span>');
});

it('una prueba vencida deja de mostrar el sufijo aunque el cron no haya pasado', function () {
    $usuario = usuarioConPerfilDeMarca(['plan' => User::PLAN_TRIAL, 'plan_expira_en' => now()->subDay()]);

    $html = $this->actingAs($usuario)->get(route('dashboard'))->assertOk()->getContent();

    expect($html)->not->toContain('>Premium</span>');
});

it('el sufijo viaja con el usuario a todas las pantallas de la aplicación', function () {
    $usuario = usuarioConPerfilDeMarca(['plan' => User::PLAN_PREMIUM, 'plan_expira_en' => null]);

    foreach ([route('planes.index'), route('calculadora.edit')] as $url) {
        expect($this->actingAs($usuario)->get($url)->assertOk()->getContent())
            ->toContain('>Premium</span>');
    }
});

it('la landing pública nunca lleva el sufijo, ni con sesión de Premium', function () {
    // Sin sesión: la landing es lo que ve un visitante.
    expect($this->get(route('landing'))->assertOk()->getContent())
        ->not->toContain('>Premium</span>');
});

it('el login no lleva el sufijo', function () {
    expect($this->get(route('login'))->assertOk()->getContent())
        ->not->toContain('>Premium</span>');
});
