<?php

use Dedoc\Scramble\Http\Middleware\RestrictedDocsAccess;
use Dedoc\Scramble\SecurityDocumentation\MiddlewareAuthSecurityStrategy;

return [
    'api_path' => 'api/v1',
    'api_domain' => null,

    'info' => [
        'version' => '1.0.0',
        'description' => <<<'MARKDOWN'
# Tupay Ledger & Settlement API

Security-first NGN-to-CNY swap orchestration using immutable double-entry accounting.

All financial amounts are integer subunits: kobo for NGN and fen for CNY. Protected endpoints use a Sanctum bearer token. Financial writes additionally require an action-bound Elevated Action Token and an `Idempotency-Key` header.
MARKDOWN,
    ],

    'servers' => null,
    'enum_cases_names_strategy' => 'names',

    'middleware' => [
        'web',
        RestrictedDocsAccess::class,
    ],

    'security_strategy' => MiddlewareAuthSecurityStrategy::class,

    'cache' => [
        'key' => 'scramble.openapi.v1',
        'store' => 'file',
    ],

    'authorized_emails' => array_values(array_filter(array_map(
        static fn (string $email): string => trim($email),
        explode(',', (string) env('SCRAMBLE_DOCS_EMAILS', '')),
    ))),
];
