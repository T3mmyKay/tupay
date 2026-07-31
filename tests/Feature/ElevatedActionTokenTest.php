<?php

namespace Tests\Feature;

use App\Domain\Security\ElevatedActionTokenService;
use App\Domain\Security\InvalidElevatedActionToken;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class ElevatedActionTokenTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        Redis::connection()->flushdb();
    }

    public function test_token_is_single_use_and_action_bound(): void
    {
        $user = User::factory()->create();
        $payload = [
            'action' => 'swap',
            'source_wallet_id' => '00000000-0000-4000-8000-000000000001',
            'destination_wallet_id' => '00000000-0000-4000-8000-000000000002',
            'amount_subunits' => 1000,
        ];

        $tokens = app(ElevatedActionTokenService::class);
        $token = $tokens->issue($user, $payload);
        $tokens->consume($user, $payload, $token);

        $this->expectException(InvalidElevatedActionToken::class);
        $tokens->consume($user, $payload, $token);
    }

    public function test_token_rejects_a_changed_amount_without_consuming_the_authorized_action(): void
    {
        $user = User::factory()->create();
        $payload = [
            'action' => 'swap',
            'source_wallet_id' => '00000000-0000-4000-8000-000000000001',
            'destination_wallet_id' => '00000000-0000-4000-8000-000000000002',
            'amount_subunits' => 1000,
        ];

        $tokens = app(ElevatedActionTokenService::class);
        $token = $tokens->issue($user, $payload);

        try {
            $tokens->consume($user, [...$payload, 'amount_subunits' => 1001], $token);
            self::fail('Changed action payload should be rejected.');
        } catch (InvalidElevatedActionToken) {
            $tokens->consume($user, $payload, $token);
            self::assertTrue(true);
        }
    }
}
