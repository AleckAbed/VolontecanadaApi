<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    // Production : seulement l'adresse du front (FRONTEND_URL) et CORS_EXTRA_ORIGINS
    // (liste séparée par des virgules). Les adresses de développement (localhost,
    // réseau local, tunnels) ne sont acceptées que hors production.
    'allowed_origins' => array_values(array_filter(array_merge(
        [rtrim((string) env('FRONTEND_URL', ''), '/')],
        array_map('trim', explode(',', (string) env('CORS_EXTRA_ORIGINS', ''))),
        env('APP_ENV') === 'production' ? [] : [
            'http://localhost:3001',
            'http://localhost:3012',
            'http://127.0.0.1:3001',
            'http://127.0.0.1:3012',
        ],
    ))),

    'allowed_origins_patterns' => env('APP_ENV') === 'production' ? [] : [
        '/^http:\/\/192\.168\.\d+\.\d+:3001$/',
        '/^http:\/\/10\.\d+\.\d+\.\d+:3001$/',
        '/^https:\/\/[a-z0-9-]+\.(lhr\.life|ngrok-free\.dev)$/', // tunnels de démonstration
    ],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,

];


