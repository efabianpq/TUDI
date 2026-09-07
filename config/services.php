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
        'timeout' => (int) env('GEMINI_TIMEOUT', 30),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
