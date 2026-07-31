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

    public function test_challenge_then_swap_consumes_the_token_and_posts_exact_ledger_entries(): void
    {
        $login = $this->postJson('/api/login', [
            'email' => 'candidate@tupay.test',
            'password' => 'password',
        ])->assertOk();

        $bearer = (string) $login->json('token');
        $wallets = collect($login->json('wallets'));
        $sourceWalletId = (string) $wallets->firstWhere('currency', Currency::NGN->value)['id'];
        $destinationWalletId = (string) $wallets->firstWhere('currency', Currency::CNY->value)['id'];
        $amount = 100_000_000;

        $user = User::query()->where('email', 'candidate@tupay.test')->firstOrFail();
        $totp = (new Google2FA())->getCurrentOtp($user->totp_secret);
        $actionPayload = [
            'action' => 'swap',
            'source_wallet_id' => $sourceWalletId,
            'destination_wallet_id' => $destinationWalletId,
            'amount_subunits' => $amount,
        ];

        $challenge = $this->withToken($bearer)->postJson('/api/2fa/challenge', [
            'totp_code' => $totp,
            'action_payload' => $actionPayload,
        ])->assertOk();

        $eat = (string) $challenge->json('elevated_action_token');
        $swapPayload = [
            'source_wallet_id' => $sourceWalletId,
            'destination_wallet_id' => $destinationWalletId,
            'amount_subunits' => $amount,
        ];

        $this->withToken($bearer)
            ->withHeader('X-Elevated-Action-Token', $eat)
            ->postJson('/api/swap', $swapPayload)
            ->assertOk()
            ->assertJsonPath('data.status', 'PENDING');

        $this->withToken($bearer)
            ->withHeader('X-Elevated-Action-Token', $eat)
            ->postJson('/api/swap', $swapPayload)
            ->assertUnauthorized();

        $source = Wallet::query()->findOrFail($sourceWalletId);
        self::assertSame(0, app(WalletBalanceService::class)->balance($source));

        $groups = DB::table('ledger_entries')
            ->select('ledger_transaction_id', 'currency', DB::raw('SUM(amount_subunits) AS total'))
            ->groupBy('ledger_transaction_id', 'currency')
            ->get();

        self::assertNotEmpty($groups);
        foreach ($groups as $group) {
            self::assertSame(0, (int) $group->total);
        }
    }
}
