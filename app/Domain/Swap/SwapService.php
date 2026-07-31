<?php

namespace App\Domain\Swap;

use App\Domain\Ledger\LedgerService;
use App\Domain\Ledger\WalletBalanceService;
use App\Enums\Currency;
use App\Enums\LedgerTransactionType;
use App\Enums\SwapStatus;
use App\Enums\WalletType;
use App\Models\Swap;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class SwapService
{
    public function __construct(
        private readonly DistributedLockManager $locks,
        private readonly SwapQuoteService $quotes,
        private readonly WalletBalanceService $balances,
        private readonly LedgerService $ledger,
    ) {}

    public function execute(User $user, string $sourceWalletId, string $destinationWalletId, int $amountSubunits): Swap
    {
        $lockKeys = [
            'swap:user:'.$user->getKey(),
            'swap:wallet:'.$sourceWalletId,
            'swap:wallet:'.$destinationWalletId,
        ];
        $quoteService = $this->quotes;
        $balanceService = $this->balances;
        $ledgerService = $this->ledger;

        return $this->locks->withLocks($lockKeys, function () use ($user, $sourceWalletId, $destinationWalletId, $amountSubunits, $quoteService, $balanceService, $ledgerService): Swap {
            $quote = $quoteService->quoteNgnToCny($amountSubunits);

            return DB::transaction(function () use ($user, $sourceWalletId, $destinationWalletId, $amountSubunits, $quote, $balanceService, $ledgerService): Swap {
                DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');

                $clearingId = Wallet::query()
                    ->whereNull('user_id')
                    ->where('type', WalletType::CLEARING)
                    ->where('currency', Currency::NGN)
                    ->value('id');

                if (! is_string($clearingId)) {
                    throw new InvalidSwap('The NGN clearing wallet is not configured.');
                }

                $walletIds = [$sourceWalletId, $destinationWalletId, $clearingId];
                sort($walletIds, SORT_STRING);

                /** @var Collection<int, Wallet> $wallets */
                $wallets = Wallet::query()
                    ->whereIn('id', $walletIds)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

                /** @var Wallet|null $source */
                $source = $wallets->firstWhere('id', $sourceWalletId);
                /** @var Wallet|null $destination */
                $destination = $wallets->firstWhere('id', $destinationWalletId);
                /** @var Wallet|null $clearing */
                $clearing = $wallets->firstWhere('id', $clearingId);

                if ($source === null || $destination === null || $clearing === null) {
                    throw new InvalidSwap('One or more participating wallets do not exist.');
                }

                $userId = (string) $user->getKey();
                $sourceOwnerId = (string) $source->getAttribute('user_id');
                $destinationOwnerId = (string) $destination->getAttribute('user_id');

                if ($sourceOwnerId !== $userId || $destinationOwnerId !== $userId) {
                    throw new InvalidSwap('Both wallets must belong to the authenticated user.');
                }

                if ($source->currencyEnum() !== Currency::NGN || $destination->currencyEnum() !== Currency::CNY) {
                    throw new InvalidSwap('Only NGN to CNY swaps are supported.');
                }

                Wallet::query()->whereIn('id', $walletIds)->increment('lock_version');

                if ($balanceService->balance($source) < $amountSubunits) {
                    throw new InsufficientFunds('The source wallet has insufficient funds.');
                }

                $debitTransaction = $ledgerService->post(LedgerTransactionType::SWAP_DEBIT, [
                    ['wallet' => $source, 'amount_subunits' => -$amountSubunits],
                    ['wallet' => $clearing, 'amount_subunits' => $amountSubunits],
                ], [
                    'user_id' => $userId,
                    'source_wallet_id' => $sourceWalletId,
                    'destination_wallet_id' => $destinationWalletId,
                ]);

                $swapId = (string) Str::uuid();

                return Swap::query()->create([
                    'id' => $swapId,
                    'user_id' => $userId,
                    'source_wallet_id' => $sourceWalletId,
                    'destination_wallet_id' => $destinationWalletId,
                    'source_amount_subunits' => $amountSubunits,
                    'destination_amount_subunits' => $quote->destinationAmountSubunits,
                    'quoted_rate' => $quote->effectiveRate,
                    'spread_basis_points' => $quote->spreadBasisPoints,
                    'provider_reference' => 'tupay_'.str_replace('-', '', $swapId),
                    'status' => SwapStatus::PENDING,
                    'debit_ledger_transaction_id' => $debitTransaction->getKey(),
                ]);
            }, 3);
        });
    }
}
