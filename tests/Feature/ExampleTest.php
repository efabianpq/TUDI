<?php

use App\Models\User;

it('redirects a guest at the root to the login page', function () {
    $response = $this->get('/');

    $response->assertRedirect(route('login'));
});

it('redirects an authenticated user at the root to the dashboard', function () {
    $response = $this->actingAs(User::factory()->create())->get('/');

    $response->assertRedirect(route('dashboard'));
});
