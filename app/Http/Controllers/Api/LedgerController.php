<?php

namespace App\Http\Controllers\Api;

use App\Domain\Ledger\WalletBalanceService;
use App\Http\Controllers\Controller;
use App\Http\Requests\LedgerRequest;
use App\Http\Resources\LedgerResource;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Models\Wallet;
use Dedoc\Scramble\Attributes\Header;

class LedgerController extends Controller
{
    /**
     * Get wallet ledger history.
     *
     * Returns immutable entries using cursor pagination and a dynamically calculated balance.
     */
    #[Header('X-Request-ID', 'Request correlation identifier.', type: 'string', required: true)]
    public function __invoke(LedgerRequest $request, string $walletId, WalletBalanceService $balances): LedgerResource
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $wallet = Wallet::query()
            ->whereKey($walletId)
            ->where('user_id', $user->getKey())
            ->firstOrFail();

        $wallet->setAttribute('balance_subunits', $balances->balance($wallet));
        $perPage = $request->integer('per_page', 20);

        $entries = LedgerEntry::query()
            ->where('wallet_id', $wallet->getKey())
            ->with('transaction:id,type,status,metadata,created_at')
            ->orderByDesc('id')
            ->cursorPaginate(perPage: $perPage);

        return new LedgerResource([
            'wallet' => $wallet,
            'entries' => $entries->items(),
            'next_cursor' => $entries->nextCursor()?->encode(),
            'per_page' => $perPage,
        ]);
    }
}
