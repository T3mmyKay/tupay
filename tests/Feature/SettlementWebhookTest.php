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

        $swap = app(SwapService::class)->execute(
            $user,
            (string) $source->getKey(),
            (string) $destination->getKey(),
            1_000_000,
        );

        $this->sendSignedWebhook([
            'provider_reference' => $swap->provider_reference,
            'status' => SwapStatus::COMPLETED->value,
        ])->assertStatus(202);

        $expectedBalance = $swap->destination_amount_subunits;
        self::assertSame($expectedBalance, app(WalletBalanceService::class)->balance($destination));

        $this->sendSignedWebhook([
            'provider_reference' => $swap->provider_reference,
            'status' => SwapStatus::INITIATED->value,
        ])->assertStatus(202);

        $this->sendSignedWebhook([
            'provider_reference' => $swap->provider_reference,
            'status' => SwapStatus::COMPLETED->value,
        ])->assertStatus(202)->assertJsonPath('duplicate', true);

        $freshSwap = Swap::query()->findOrFail($swap->getKey());
        self::assertSame(SwapStatus::COMPLETED, $freshSwap->status);
        self::assertSame($expectedBalance, app(WalletBalanceService::class)->balance($destination));
        self::assertNotNull($freshSwap->settlement_ledger_transaction_id);
    }

    /** @param array<string, string> $payload */
    private function sendSignedWebhook(array $payload): \Illuminate\Testing\TestResponse
    {
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $signature = hash_hmac('sha256', $body, (string) config('services.settlement.webhook_secret'));

        return $this->call(
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
        );
    }
}
