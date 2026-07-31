<?php

namespace App\Domain\Ledger;

use App\Models\Wallet;
use Illuminate\Support\Facades\DB;

final class WalletBalanceService
{
    public function balance(Wallet|string $wallet): int
    {
        $walletId = $wallet instanceof Wallet ? $wallet->getKey() : $wallet;

        $balance = DB::table('ledger_entries')
            ->where('wallet_id', $walletId)
            ->sum('amount_subunits');

        return (int) $balance;
    }
}
