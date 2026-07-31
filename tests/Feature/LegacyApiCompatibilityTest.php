<?php

namespace Tests\Feature;

use App\Enums\Currency;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Redis;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

final class LegacyApiCompatibilityTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        Redis::connection()->flushdb();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_original_assessment_flow_works_without_new_v1_headers_or_fields(): void
    {
        $login = $this->postJson('/api/login', [
            'email' => 'candidate@tupay.test',
            'password' => 'password',
        ])
            ->assertOk()
            ->assertHeader('Deprecation', 'true')
            ->assertJsonStructure(['token', 'token_type', 'user', 'wallets']);

        $bearer = (string) $login->json('token');
        $wallets = collect($login->json('wallets'));
        $sourceWalletId = (string) $wallets->firstWhere('currency', Currency::NGN->value)['id'];
        $destinationWalletId = (string) $wallets->firstWhere('currency', Currency::CNY->value)['id'];
        $user = User::query()->where('email', 'candidate@tupay.test')->firstOrFail();
        $totp = (new Google2FA)->getCurrentOtp($user->totp_secret);
        $amount = 1_000_000;

        $challenge = $this->withToken($bearer)->postJson('/api/2fa/challenge', [
            'totp_code' => $totp,
            'action_payload' => [
                'action' => 'swap',
                'source_wallet_id' => $sourceWalletId,
                'destination_wallet_id' => $destinationWalletId,
                'amount_subunits' => $amount,
            ],
        ])
            ->assertOk()
            ->assertJsonStructure(['elevated_action_token', 'token_type', 'expires_in']);

        $swap = $this->withToken($bearer)
            ->withHeader('X-Elevated-Action-Token', (string) $challenge->json('elevated_action_token'))
            ->postJson('/api/swap', [
                'source_wallet_id' => $sourceWalletId,
                'destination_wallet_id' => $destinationWalletId,
                'amount_subunits' => $amount,
            ])
            ->assertOk()
            ->assertHeader('Idempotent-Replayed', 'false')
            ->assertJsonPath('data.status', 'PENDING');

        $body = json_encode([
            'provider_reference' => (string) $swap->json('data.provider_reference'),
            'status' => 'COMPLETED',
        ], JSON_THROW_ON_ERROR);
        $signature = hash_hmac(
            'sha256',
            $body,
            (string) config('services.settlement.webhook_secret'),
        );

        $this->call(
            'POST',
            '/api/webhooks/settlement',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_X_TUPAY_SIGNATURE' => $signature,
            ],
            $body,
        )
            ->assertStatus(202)
            ->assertJsonPath('accepted', true)
            ->assertJsonPath('duplicate', false);
    }
}
