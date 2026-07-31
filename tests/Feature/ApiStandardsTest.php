<?php

namespace Tests\Feature;

use Tests\TestCase;

class ApiStandardsTest extends TestCase
{
    public function test_versioned_api_returns_correlation_and_security_headers(): void
    {
        $this->withHeader('X-Request-ID', 'standards-request-0001')
            ->getJson('/api/v1/health')
            ->assertOk()
            ->assertHeader('X-Request-ID', 'standards-request-0001')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertJsonPath('data.status', 'ok');
    }

    public function test_unversioned_api_routes_do_not_exist(): void
    {
        $this->getJson('/api/health')
            ->assertNotFound()
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('code', 'RESOURCE_NOT_FOUND');
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

    public function test_scramble_exports_the_versioned_contract(): void
    {
        $response = $this->getJson('/docs/api.json')->assertOk();

        self::assertSame('3.1.0', $response->json('openapi'));
        self::assertArrayHasKey('/login', $response->json('paths'));
        self::assertArrayHasKey('/2fa/challenge', $response->json('paths'));
        self::assertArrayHasKey('/swap', $response->json('paths'));
        self::assertArrayHasKey('/ledger/{walletId}', $response->json('paths'));
        self::assertArrayHasKey('/webhooks/settlement', $response->json('paths'));
    }
}
