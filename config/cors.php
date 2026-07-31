<?php

$allowedOrigins = array_values(array_filter(array_map(
    static fn (string $origin): string => trim($origin),
    explode(',', (string) env('CORS_ALLOWED_ORIGINS', 'http://localhost:3000,http://localhost:5173')),
)));

return [
    'paths' => ['api/*', 'docs/api.json'],
    'allowed_methods' => ['GET', 'POST', 'OPTIONS'],
    'allowed_origins' => $allowedOrigins,
    'allowed_origins_patterns' => [],
    'allowed_headers' => [
        'Accept',
        'Authorization',
        'Content-Type',
        'Idempotency-Key',
        'X-Elevated-Action-Token',
        'X-Request-ID',
        'X-Tupay-Signature',
        'X-Tupay-Timestamp',
    ],
    'exposed_headers' => [
        'Deprecation',
        'Idempotent-Replayed',
        'Link',
        'Retry-After',
        'Sunset',
        'X-Request-ID',
    ],
    'max_age' => 600,
    'supports_credentials' => false,
];
