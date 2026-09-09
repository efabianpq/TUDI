<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    /**
     * Ninguna petición de la suite sale a internet.
     *
     * Por defecto, Laravel **ejecuta de verdad** las peticiones que no casan con
     * ningún stub de `Http::fake()`. Con un proveedor de IA de por medio eso no
     * es solo un test lento: es una llamada facturable a OpenAI cada vez que
     * alguien corre los tests, y un fake que dejó de casar (porque cambió el
     * proveedor, el endpoint o el modelo) pasa desapercibido en verde.
     *
     * Con esto, una petición sin stub falla en el acto y dice qué URL era.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }
}
