<?php

namespace Tests\Feature;

use App\Domain\Ledger\WalletBalanceService;
use App\Enums\Currency;
use App\Models\User;
use App\Models\Wallet;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class SwapFlowTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        Redis::connection()->flushdb();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_challenge_then_swap_is_idempotent_and_posts_exact_ledger_entries(): void
    {
        $login = $this->postJson('/api/v1/login', [
            'email' => 'candidate@tupay.test',
            'password' => 'password',
        ])->assertOk();

        $bearer = (string) $login->json('data.token');
        $wallets = collect($login->json('data.wallets'));
        $sourceWalletId = (string) $wallets->firstWhere('currency', Currency::NGN->value)['id'];
        $destinationWalletId = (string) $wallets->firstWhere('currency', Currency::CNY->value)['id'];
        $amount = 100_000_000;

        $user = User::query()->where('email', 'candidate@tupay.test')->firstOrFail();
        $totp = (new Google2FA)->getCurrentOtp($user->totp_secret);
        $actionPayload = [
            'action' => 'swap',
            'source_wallet_id' => $sourceWalletId,
            'destination_wallet_id' => $destinationWalletId,
            'amount_subunits' => $amount,
        ];

        $challenge = $this->withToken($bearer)->postJson('/api/v1/2fa/challenge', [
            'totp_code' => $totp,
            'action_payload' => $actionPayload,
        ])->assertOk();

        $eat = (string) $challenge->json('data.elevated_action_token');
        $swapPayload = [
            'source_wallet_id' => $sourceWalletId,
            'destination_wallet_id' => $destinationWalletId,
            'amount_subunits' => $amount,
        ];
        $idempotencyKey = 'swap-test-00000001';

        $created = $this->withToken($bearer)
            ->withHeaders([
                'X-Elevated-Action-Token' => $eat,
                'Idempotency-Key' => $idempotencyKey,
                'X-Request-ID' => 'test-request-00000001',
            ])
            ->postJson('/api/v1/swap', $swapPayload)
            ->assertCreated()
            ->assertHeader('Idempotent-Replayed', 'false')
            ->assertHeader('X-Request-ID', 'test-request-00000001')
            ->assertJsonPath('data.status', 'PENDING');

        $swapId = (string) $created->json('data.id');

        $this->withToken($bearer)
            ->withHeaders([
                'X-Elevated-Action-Token' => $eat,
                'Idempotency-Key' => $idempotencyKey,
            ])
            ->postJson('/api/v1/swap', $swapPayload)
            ->assertOk()
            ->assertHeader('Idempotent-Replayed', 'true')
            ->assertJsonPath('data.id', $swapId);

        $this->withToken($bearer)
            ->withHeaders([
                'X-Elevated-Action-Token' => $eat,
                'Idempotency-Key' => 'swap-test-00000002',
            ])
            ->postJson('/api/v1/swap', $swapPayload)
            ->assertUnauthorized()
            ->assertJsonPath('code', 'ELEVATED_ACTION_TOKEN_INVALID');

        $source = Wallet::query()->findOrFail($sourceWalletId);
        self::assertSame(0, app(WalletBalanceService::class)->balance($source));
        self::assertSame(1, DB::table('swaps')->count());

        $groups = DB::table('ledger_entries')
            ->select('ledger_transaction_id', 'currency', DB::raw('SUM(amount_subunits) AS total'))
            ->groupBy('ledger_transaction_id', 'currency')
            ->get();

        self::assertNotEmpty($groups);
        foreach ($groups as $group) {
            self::assertSame(0, (int) $group->total);
        }
    }

    public function test_reusing_an_idempotency_key_with_different_payload_returns_conflict(): void
    {
        $login = $this->postJson('/api/v1/login', [
            'email' => 'candidate@tupay.test',
            'password' => 'password',
        ])->assertOk();

        $bearer = (string) $login->json('data.token');
        $wallets = collect($login->json('data.wallets'));
        $sourceWalletId = (string) $wallets->firstWhere('currency', Currency::NGN->value)['id'];
        $destinationWalletId = (string) $wallets->firstWhere('currency', Currency::CNY->value)['id'];
        $user = User::query()->where('email', 'candidate@tupay.test')->firstOrFail();
        $totp = (new Google2FA)->getCurrentOtp($user->totp_secret);

        $this->createSwap($bearer, $totp, $sourceWalletId, $destinationWalletId, 1_000_000, 'swap-conflict-key');

        $actionPayload = [
            'action' => 'swap',
            'source_wallet_id' => $sourceWalletId,
            'destination_wallet_id' => $destinationWalletId,
            'amount_subunits' => 2_000_000,
        ];
        $challenge = $this->withToken($bearer)->postJson('/api/v1/2fa/challenge', [
            'totp_code' => $totp,
            'action_payload' => $actionPayload,
        ])->assertOk();

        $this->withToken($bearer)
            ->withHeaders([
                'X-Elevated-Action-Token' => (string) $challenge->json('data.elevated_action_token'),
                'Idempotency-Key' => 'swap-conflict-key',
            ])
            ->postJson('/api/v1/swap', [
                'source_wallet_id' => $sourceWalletId,
                'destination_wallet_id' => $destinationWalletId,
                'amount_subunits' => 2_000_000,
            ])
            ->assertConflict()
            ->assertJsonPath('code', 'IDEMPOTENCY_CONFLICT');
    }

    private function createSwap(
        string $bearer,
        string $totp,
        string $sourceWalletId,
        string $destinationWalletId,
        int $amount,
        string $idempotencyKey,
    ): void {
        $actionPayload = [
            'action' => 'swap',
            'source_wallet_id' => $sourceWalletId,
            'destination_wallet_id' => $destinationWalletId,
            'amount_subunits' => $amount,
        ];
        $challenge = $this->withToken($bearer)->postJson('/api/v1/2fa/challenge', [
            'totp_code' => $totp,
            'action_payload' => $actionPayload,
        ])->assertOk();

        $this->withToken($bearer)
            ->withHeaders([
                'X-Elevated-Action-Token' => (string) $challenge->json('data.elevated_action_token'),
                'Idempotency-Key' => $idempotencyKey,
            ])
            ->postJson('/api/v1/swap', [
                'source_wallet_id' => $sourceWalletId,
                'destination_wallet_id' => $destinationWalletId,
                'amount_subunits' => $amount,
            ])
            ->assertCreated();
    }
}
