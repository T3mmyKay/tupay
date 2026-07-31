<?php

namespace App\Domain\Ledger;

use App\Enums\LedgerTransactionStatus;
use App\Enums\LedgerTransactionType;
use App\Models\LedgerTransaction;
use App\Models\Wallet;
use Illuminate\Support\Facades\DB;
use LogicException;

final class LedgerService
{
    /**
     * @param  list<array{wallet: Wallet, amount_subunits: int}>  $postings
     * @param  array<string, mixed>  $metadata
     */
    public function post(
        LedgerTransactionType $type,
        array $postings,
        array $metadata = [],
    ): LedgerTransaction {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Ledger postings must execute inside a database transaction.');
        }

        if (count($postings) < 2) {
            throw new LogicException('A ledger transaction requires at least two entries.');
        }

        /** @var array<string, int> $totals */
        $totals = [];

        foreach ($postings as $posting) {
            if ($posting['amount_subunits'] === 0) {
                throw new LogicException('Zero-value ledger entries are not permitted.');
            }

            $currency = $posting['wallet']->currencyEnum();
            $totals[$currency->value] = ($totals[$currency->value] ?? 0) + $posting['amount_subunits'];
        }

        foreach ($totals as $total) {
            if ($total !== 0) {
                throw new LogicException('Ledger postings are not balanced per currency.');
            }
        }

        $transaction = LedgerTransaction::query()->create([
            'type' => $type,
            'status' => LedgerTransactionStatus::PENDING,
            'metadata' => $metadata,
        ]);

        foreach ($postings as $posting) {
            $transaction->entries()->create([
                'wallet_id' => $posting['wallet']->getKey(),
                'currency' => $posting['wallet']->currencyEnum(),
                'amount_subunits' => $posting['amount_subunits'],
            ]);
        }

        $transaction->update(['status' => LedgerTransactionStatus::COMPLETED]);

        return $transaction->refresh();
    }
}
