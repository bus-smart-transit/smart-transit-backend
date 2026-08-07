<?php

return [
    'paths' => ['api/*'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_filter(
        array_map('trim', explode(',', env('CORS_ALLOWED_ORIGINS', 'http://localhost:5173,http://127.0.0.1:5173')))
    ),

    'allowed_origins_patterns' => [
        // Vercel preview and production deployments
        '#^https://.*\.vercel\.app$#',
        // Custom domain if configured on Vercel
        // '#^https://your-custom-domain\.com$#',
    ],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,
];
