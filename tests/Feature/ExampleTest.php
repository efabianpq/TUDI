<?php

test('la ruta raiz responde correctamente', function () {
    $response = $this->get('/');

    $response->assertStatus(200);
});
