<?php

$allowedOrigins = array_values(array_filter(array_map(
    static fn (string $origin): string => trim($origin),
    explode(',', (string) env(
        'CORS_ALLOWED_ORIGINS',
        'http://localhost:5173',
    )),
)));

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing
    |--------------------------------------------------------------------------
    |
    | Bearer Token API requests are accepted only from the configured browser
    | origins. Supply multiple origins as a comma-separated environment value.
    |
    */

    'paths' => ['api/*'],

    'allowed_methods' => ['*'],

    'allowed_origins' => $allowedOrigins,

    'allowed_origins_patterns' => [],

    'allowed_headers' => [
        'Accept',
        'Authorization',
        'Content-Type',
        'Origin',
        'X-Requested-With',
        'Idempotency-Key',
    ],

    'exposed_headers' => ['Retry-After', 'Idempotency-Replayed', 'X-Request-ID', 'Content-Disposition'],

    'max_age' => 600,

    'supports_credentials' => false,

];
