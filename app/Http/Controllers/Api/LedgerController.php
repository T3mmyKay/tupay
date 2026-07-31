<?php

namespace App\Http\Controllers\Api;

use App\Domain\Ledger\WalletBalanceService;
use App\Http\Controllers\Controller;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LedgerController extends Controller
{
    public function __invoke(Request $request, string $walletId, WalletBalanceService $balances): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $wallet = Wallet::query()
            ->whereKey($walletId)
            ->where('user_id', $user->getKey())
            ->firstOrFail();

        $entries = LedgerEntry::query()
            ->where('wallet_id', $wallet->getKey())
            ->with('transaction:id,type,status,metadata,created_at')
            ->orderByDesc('id')
            ->paginate(perPage: min(100, max(1, $request->integer('per_page', 20))));

        return response()->json([
            'wallet' => [
                'id' => (string) $wallet->getKey(),
                'currency' => $wallet->currency->value,
                'balance_subunits' => $balances->balance($wallet),
            ],
            'entries' => $entries,
        ]);
    }
}
