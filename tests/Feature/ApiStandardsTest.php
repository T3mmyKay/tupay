<?php

namespace Tests\Feature;

use Tests\TestCase;

class ApiStandardsTest extends TestCase
{
    public function test_canonical_api_returns_correlation_and_security_headers(): void
    {
        $this->withHeader('X-Request-ID', 'standards-request-0001')
            ->getJson('/api/v1/health')
            ->assertOk()
            ->assertHeader('X-Request-ID', 'standards-request-0001')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertHeaderMissing('Deprecation')
            ->assertJsonPath('data.status', 'ok');
    }

    public function test_legacy_api_aliases_are_marked_deprecated(): void
    {
        $this->getJson('/api/health')
            ->assertOk()
            ->assertHeader('Deprecation', 'true')
            ->assertHeader('Sunset', 'Thu, 31 Dec 2026 23:59:59 GMT')
            ->assertHeader('Link', '</api/v1/health>; rel="successor-version"');
    }

    public function test_errors_follow_problem_details_contract(): void
    {
        $this->getJson('/api/v1/ledger/00000000-0000-0000-0000-000000000000')
            ->assertUnauthorized()
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonStructure([
                'type',
                'title',
                'status',
                'detail',
                'code',
                'request_id',
            ])
            ->assertJsonPath('code', 'AUTHENTICATION_REQUIRED');
    }

    public function test_scramble_exports_only_the_versioned_contract(): void
    {
        $response = $this->getJson('/docs/api.json')->assertOk();

        self::assertSame('3.1.0', $response->json('openapi'));
        self::assertArrayHasKey('/login', $response->json('paths'));
        self::assertArrayHasKey('/2fa/challenge', $response->json('paths'));
        self::assertArrayHasKey('/swap', $response->json('paths'));
        self::assertArrayHasKey('/ledger/{walletId}', $response->json('paths'));
        self::assertArrayHasKey('/webhooks/settlement', $response->json('paths'));
        self::assertArrayNotHasKey('/v1/swap', $response->json('paths'));
    }
}
