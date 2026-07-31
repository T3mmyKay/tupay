<?php

namespace Tests\Feature;

use App\Domain\Ledger\WalletBalanceService;
use App\Domain\Swap\SwapService;
use App\Enums\Currency;
use App\Enums\SwapStatus;
use App\Models\Swap;
use App\Models\User;
use App\Models\Wallet;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class SettlementWebhookTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        Redis::connection()->flushdb();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_completed_before_initiated_is_idempotent_and_does_not_regress_state(): void
    {
        $user = User::query()->where('email', 'candidate@tupay.test')->firstOrFail();
        $source = Wallet::query()->where('user_id', $user->getKey())->where('currency', Currency::NGN)->firstOrFail();
        $destination = Wallet::query()->where('user_id', $user->getKey())->where('currency', Currency::CNY)->firstOrFail();
        $requestHash = hash('sha256', 'settlement-test-swap');

        $swap = app(SwapService::class)->execute(
            $user,
            (string) $source->getKey(),
            (string) $destination->getKey(),
            1_000_000,
            'settlement-test-swap',
            $requestHash,
        );

        $this->sendSignedWebhook([
            'event_id' => (string) Str::uuid(),
            'provider_reference' => $swap->provider_reference,
            'status' => SwapStatus::COMPLETED->value,
            'occurred_at' => now()->toAtomString(),
        ])->assertStatus(202);

        $expectedBalance = $swap->destination_amount_subunits;
        self::assertSame($expectedBalance, app(WalletBalanceService::class)->balance($destination));

        $this->sendSignedWebhook([
            'event_id' => (string) Str::uuid(),
            'provider_reference' => $swap->provider_reference,
            'status' => SwapStatus::INITIATED->value,
            'occurred_at' => now()->toAtomString(),
        ])->assertStatus(202);

        $this->sendSignedWebhook([
            'event_id' => (string) Str::uuid(),
            'provider_reference' => $swap->provider_reference,
            'status' => SwapStatus::COMPLETED->value,
            'occurred_at' => now()->toAtomString(),
        ])->assertStatus(202)->assertJsonPath('data.duplicate', true);

        $freshSwap = Swap::query()->findOrFail($swap->getKey());
        self::assertSame(SwapStatus::COMPLETED, $freshSwap->status);
        self::assertSame($expectedBalance, app(WalletBalanceService::class)->balance($destination));
        self::assertNotNull($freshSwap->settlement_ledger_transaction_id);
    }

    public function test_webhook_outside_replay_window_is_rejected(): void
    {
        $this->sendSignedWebhook([
            'event_id' => (string) Str::uuid(),
            'provider_reference' => 'tupay_expired',
            'status' => SwapStatus::PROCESSING->value,
            'occurred_at' => now()->subMinutes(10)->toAtomString(),
        ], time() - 601)
            ->assertUnauthorized()
            ->assertJsonPath('code', 'WEBHOOK_REPLAY_WINDOW_EXCEEDED');
    }

    /** @param array<string, string> $payload */
    private function sendSignedWebhook(array $payload, ?int $timestamp = null): TestResponse
    {
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $timestamp ??= time();
        $signature = hash_hmac(
            'sha256',
            $timestamp.'.'.$body,
            (string) config('services.settlement.webhook_secret'),
        );

        return $this->call(
            'POST',
            '/api/v1/webhooks/settlement',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_X_TUPAY_SIGNATURE' => $signature,
                'HTTP_X_TUPAY_TIMESTAMP' => (string) $timestamp,
            ],
            $body,
        );
    }
}
