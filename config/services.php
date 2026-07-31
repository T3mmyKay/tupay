<?php

return [
    'eat' => [
        'signing_key' => env('EAT_SIGNING_KEY'),
        'ttl_seconds' => (int) env('EAT_TTL_SECONDS', 60),
    ],
    'settlement' => [
        'webhook_secret' => env('SETTLEMENT_WEBHOOK_SECRET'),
        'webhook_tolerance_seconds' => (int) env('SETTLEMENT_WEBHOOK_TOLERANCE_SECONDS', 300),
    ],
    'fx' => [
        'url' => env('FX_RATE_URL'),
        'static_rate' => env('FX_RATE_STATIC'),
        'fresh_seconds' => (int) env('FX_FRESH_SECONDS', 30),
        'stale_seconds' => (int) env('FX_STALE_SECONDS', 300),
    ],
];
