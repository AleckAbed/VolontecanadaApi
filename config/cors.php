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

    'allowed_origins' => [
        'http://localhost:3001',
        'http://localhost:3012',
        'http://127.0.0.1:3001',
        'http://127.0.0.1:3012',
        'http://192.168.100.197:3001',
        'http://192.168.100.197:3012',
        'http://192.168.2.105:3001',
        'https://app.volontecanada.ca',
        'https://volontecanadaplateforme.vercel.app',
            // Tunnels
        'https://74b3721d5eaa05.lhr.life',        // localhost.run front
        'https://XXXXXXXXXXXXXXX.lhr.life',        // localhost.run API (remplace par ta vraie URL)
        'https://paramount-avoid-radio.ngrok-free.dev', // ngrok
    ],

    // Patterns : réseau local + en prod vous pouvez ajouter votre domaine HTTPS
    'allowed_origins_patterns' => [
        '/^http:\/\/192\.168\.\d+\.\d+:3001$/',
        '/^http:\/\/10\.\d+\.\d+\.\d+:3001$/',
        '/^https:\/\/(.*\.)?vercel\.app$/',  // Frontend Vercel
    ],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,

];


