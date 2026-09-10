<?php

use App\Models\User;

it('sirve la landing pública a un visitante sin sesión', function () {
    // Antes la raíz redirigía al login y no había ninguna página pública
    // (CLAUDE.md sección 5.19).
    $this->get('/')
        ->assertOk()
        ->assertSee('Entiende cómo comes. Decide qué hacer con eso.')
        ->assertSee(route('register'));
});

it('redirects an authenticated user at the root to the dashboard', function () {
    $response = $this->actingAs(User::factory()->create())->get('/');

    $response->assertRedirect(route('dashboard'));
});
