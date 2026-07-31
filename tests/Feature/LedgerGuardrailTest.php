<?php

namespace Tests\Feature;

use App\Enums\Currency;
use App\Enums\LedgerTransactionStatus;
use App\Enums\LedgerTransactionType;
use App\Enums\WalletType;
use App\Models\LedgerTransaction;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LedgerGuardrailTest extends TestCase
{
    use DatabaseMigrations;

    public function test_database_rejects_an_unbalanced_completed_transaction(): void
    {
        $treasury = Wallet::query()->create([
            'currency' => Currency::NGN,
            'type' => WalletType::TREASURY,
        ]);

        try {
            DB::transaction(function () use ($treasury): void {
                $transaction = LedgerTransaction::query()->create([
                    'type' => LedgerTransactionType::FUNDING,
                    'status' => LedgerTransactionStatus::COMPLETED,
                    'metadata' => [],
                ]);

                $transaction->entries()->create([
                    'wallet_id' => $treasury->getKey(),
                    'currency' => Currency::NGN,
                    'amount_subunits' => 100,
                ]);

                DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            });

            self::fail('The database accepted an unbalanced transaction.');
        } catch (QueryException $exception) {
            self::assertStringContainsString('requires at least two entries', $exception->getMessage());
        }
    }

    public function test_database_rejects_a_negative_user_wallet_even_when_transaction_is_balanced(): void
    {
        $user = User::factory()->create();
        $userWallet = Wallet::query()->create([
            'user_id' => $user->getKey(),
            'currency' => Currency::NGN,
            'type' => WalletType::USER,
        ]);
        $treasury = Wallet::query()->create([
            'currency' => Currency::NGN,
            'type' => WalletType::TREASURY,
        ]);

        try {
            DB::transaction(function () use ($userWallet, $treasury): void {
                $transaction = LedgerTransaction::query()->create([
                    'type' => LedgerTransactionType::FUNDING,
                    'status' => LedgerTransactionStatus::COMPLETED,
                    'metadata' => [],
                ]);

                $transaction->entries()->createMany([
                    [
                        'wallet_id' => $userWallet->getKey(),
                        'currency' => Currency::NGN,
                        'amount_subunits' => -1,
                    ],
                    [
                        'wallet_id' => $treasury->getKey(),
                        'currency' => Currency::NGN,
                        'amount_subunits' => 1,
                    ],
                ]);

                DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            });

            self::fail('The database accepted a negative user-wallet balance.');
        } catch (QueryException $exception) {
            self::assertStringContainsString('cannot have a negative balance', $exception->getMessage());
        }
    }
}
