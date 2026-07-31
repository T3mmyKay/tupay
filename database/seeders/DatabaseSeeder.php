<?php

namespace Database\Seeders;

use App\Domain\Ledger\LedgerService;
use App\Enums\Currency;
use App\Enums\LedgerTransactionType;
use App\Enums\WalletType;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            $user = User::query()->firstOrCreate(
                ['email' => 'candidate@tupay.test'],
                [
                    'name' => 'Tupay Candidate',
                    'password' => 'password',
                    'totp_secret' => 'JBSWY3DPEHPK3PXP',
                ],
            );

            $userNgn = Wallet::query()->firstOrCreate([
                'user_id' => $user->getKey(),
                'currency' => Currency::NGN,
            ], [
                'type' => WalletType::USER,
            ]);

            Wallet::query()->firstOrCreate([
                'user_id' => $user->getKey(),
                'currency' => Currency::CNY,
            ], [
                'type' => WalletType::USER,
            ]);

            $ngnTreasury = Wallet::query()->firstOrCreate([
                'user_id' => null,
                'type' => WalletType::TREASURY,
                'currency' => Currency::NGN,
            ]);

            Wallet::query()->firstOrCreate([
                'user_id' => null,
                'type' => WalletType::CLEARING,
                'currency' => Currency::NGN,
            ]);

            $cnyTreasury = Wallet::query()->firstOrCreate([
                'user_id' => null,
                'type' => WalletType::TREASURY,
                'currency' => Currency::CNY,
            ]);

            $cnyLiquidity = Wallet::query()->firstOrCreate([
                'user_id' => null,
                'type' => WalletType::LIQUIDITY,
                'currency' => Currency::CNY,
            ]);

            $ledger = app(LedgerService::class);

            if ($userNgn->entries()->doesntExist()) {
                $ledger->post(LedgerTransactionType::FUNDING, [
                    ['wallet' => $ngnTreasury, 'amount_subunits' => -100_000_000],
                    ['wallet' => $userNgn, 'amount_subunits' => 100_000_000],
                ], ['reason' => 'assessment seed balance']);
            }

            if ($cnyLiquidity->entries()->doesntExist()) {
                $ledger->post(LedgerTransactionType::FUNDING, [
                    ['wallet' => $cnyTreasury, 'amount_subunits' => -1_000_000_000],
                    ['wallet' => $cnyLiquidity, 'amount_subunits' => 1_000_000_000],
                ], ['reason' => 'platform settlement liquidity']);
            }
        });
    }
}
