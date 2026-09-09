<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Claude (Anthropic) — motor de distribución de comidas
    |--------------------------------------------------------------------------
    |
    | CLAUDE.md sección 4.12. La clave nunca se hardcodea ni se commitea: vive
    | solo en .env (sección 10). Sin clave configurada, la aplicación sigue
    | funcionando y el botón "Generar distribución" devuelve un error de
    | dominio controlado en vez de un 500.
    |
    */

    'anthropic' => [
        'key' => env('ANTHROPIC_API_KEY'),
        'model' => env('ANTHROPIC_MODEL', 'claude-haiku-4-5'),
        'endpoint' => env('ANTHROPIC_ENDPOINT', 'https://api.anthropic.com/v1/messages'),
        'timeout' => (int) env('ANTHROPIC_TIMEOUT', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | Gemini (Google) — motor de distribución de comidas
    |--------------------------------------------------------------------------
    |
    | CLAUDE.md sección 4.12. Reemplaza a Anthropic como proveedor vigente de
    | MealDistributionProviderInterface (binding en AppServiceProvider). La
    | clave nunca se hardcodea ni se commitea: vive solo en .env (sección 10).
    | Sin clave configurada, la aplicación sigue funcionando y el botón
    | "Generar distribución" devuelve un error de dominio controlado en vez de
    | un 500.
    |
    */

    'gemini' => [
        'key' => env('GEMINI_API_KEY'),
        'model' => env('GEMINI_MODEL', 'gemini-flash-latest'),
        'endpoint' => env('GEMINI_ENDPOINT', 'https://generativelanguage.googleapis.com/v1beta/models'),

        /*
         * El timeout NO es solo "cuánto espera el usuario": mientras dura la
         * llamada, el proceso de PHP-FPM que la atiende está ocupado y no puede
         * servir a nadie más. Con el pool pequeño de un hosting compartido,
         * unas pocas llamadas simultáneas agotan los workers y el resto de las
         * peticiones caen en 504 aunque no toquen la IA (CLAUDE.md sección
         * 4.22). 20 s deja margen a una respuesta normal (2-6 s) y queda por
         * debajo del `fastcgi_read_timeout` habitual de 30-60 s, de modo que
         * quien corta es la aplicación —con un mensaje— y no el gateway.
         */
        'timeout' => (int) env('GEMINI_TIMEOUT', 20),

        // Un DNS o un firewall de salida mal configurado no debe consumir el
        // timeout entero antes de rendirse.
        'connect_timeout' => (int) env('GEMINI_CONNECT_TIMEOUT', 5),
    ],

    /*
    |--------------------------------------------------------------------------
    | OpenAI (ChatGPT) — motor vigente de distribución de comidas y transcripción
    |--------------------------------------------------------------------------
    |
    | CLAUDE.md sección 4.12. Reemplaza a Gemini como proveedor vigente de
    | MealDistributionProviderInterface y de TranscripcionAudioProviderInterface
    | (bindings en AppServiceProvider). La clave nunca se hardcodea ni se
    | commitea: vive solo en .env (sección 10). Sin clave configurada, la
    | aplicación sigue funcionando y el botón "Generar distribución" devuelve un
    | error de dominio controlado en vez de un 500.
    |
    */

    'openai' => [
        'key' => env('OPENAI_API_KEY'),

        /*
         * El modelo se elige por .env, sin tocar código. `gpt-4.1` es el
         * predeterminado porque el motivo del cambio de proveedor fue la
         * precisión de las estimaciones nutricionales, no el coste; `gpt-4.1-mini`
         * es la opción barata si el piloto crece.
         */
        'model' => env('OPENAI_MODEL', 'gpt-4.1'),

        // Transcribir es una tarea aparte y con su propio endpoint (audio), así
        // que también con su propio modelo: el de texto no sirve aquí.
        'model_transcripcion' => env('OPENAI_MODEL_TRANSCRIPCION', 'gpt-4o-mini-transcribe'),

        'endpoint' => env('OPENAI_ENDPOINT', 'https://api.openai.com/v1'),

        /*
         * Estimación nutricional, no escritura creativa: temperatura al mínimo
         * para que el mismo texto dé los mismos macros entre llamadas.
         *
         * `OPENAI_TEMPERATURE=null` la omite del cuerpo de la petición. Hace
         * falta con las familias de razonamiento (gpt-5, o3, o4), que rechazan
         * cualquier temperatura distinta de 1 con un 400.
         */
        'temperature' => env('OPENAI_TEMPERATURE', 0.1) === null
            ? null
            : (float) env('OPENAI_TEMPERATURE', 0.1),

        /*
         * El timeout NO es solo "cuánto espera el usuario": mientras dura la
         * llamada, el proceso de PHP-FPM que la atiende está ocupado y no puede
         * servir a nadie más. Con el pool pequeño de un hosting compartido,
         * unas pocas llamadas simultáneas agotan los workers y el resto de las
         * peticiones caen en 504 aunque no toquen la IA (CLAUDE.md sección
         * 5.13). 20 s deja margen a una respuesta normal (2-6 s) y queda por
         * debajo del `fastcgi_read_timeout` habitual de 30-60 s, de modo que
         * quien corta es la aplicación —con un mensaje— y no el gateway.
         */
        'timeout' => (int) env('OPENAI_TIMEOUT', 20),

        // Un DNS o un firewall de salida mal configurado no debe consumir el
        // timeout entero antes de rendirse.
        'connect_timeout' => (int) env('OPENAI_CONNECT_TIMEOUT', 5),

        /*
         * Desviación máxima admitida entre los totales de la distribución y los
         * objetivos del día antes de pedirle al modelo que se corrija
         * (OpenAiMealDistributionProvider). Los totales los suma PHP a partir de
         * los ingredientes, nunca el modelo (regla 7, sección 13).
         */
        'tolerancia_macros' => (float) env('OPENAI_TOLERANCIA_MACROS', 0.05),

        /*
         * Cuántas veces se le pide al modelo que ajuste una distribución fuera
         * de tolerancia. 1 = una corrección como mucho: cada reintento es otra
         * llamada facturable y otro worker ocupado. 0 lo desactiva.
         */
        'reintentos_macros' => (int) env('OPENAI_REINTENTOS_MACROS', 1),

        /*
         * Techo de tiempo para el conjunto de intentos de una misma petición: la
         * corrección hereda el tiempo que sobra en vez de estrenar otro timeout
         * entero. Sin esto, dos llamadas de 20 s seguidas superarían el
         * `fastcgi_read_timeout` del servidor y el 504 lo daría el gateway, que
         * es justo lo que el timeout por llamada evita (regla 10). Debe quedar
         * por debajo de ese timeout, igual que `timeout`.
         */
        'presupuesto_total' => (int) env('OPENAI_PRESUPUESTO_TOTAL', 25),
    ],

    /*
    |--------------------------------------------------------------------------
    | Dictado por voz
    |--------------------------------------------------------------------------
    |
    | CLAUDE.md sección 5.9. El camino normal es la Web Speech API del propio
    | navegador: el reconocimiento lo hace el sistema operativo (Windows,
    | Android, macOS e iOS lo traen), el audio no sale del dispositivo y no
    | cuesta nada.
    |
    | `fallback_servidor` enciende el plan B, que graba el audio y lo transcribe
    | con OpenAI. Está APAGADO por defecto porque cada dictado sería una llamada
    | facturable al proveedor y, mientras dura, ocupa un worker de PHP-FPM
    | (sección 5.13). Solo tiene sentido encenderlo para un navegador concreto
    | que no soporte reconocimiento nativo, y sabiendo lo que cuesta.
    |
    */

    'transcripcion' => [
        'fallback_servidor' => (bool) env('TRANSCRIPCION_FALLBACK_SERVIDOR', false),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
