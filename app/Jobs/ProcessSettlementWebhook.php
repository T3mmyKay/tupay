<?php

namespace App\Jobs;

use App\Domain\Ledger\LedgerService;
use App\Enums\Currency;
use App\Enums\LedgerTransactionType;
use App\Enums\SwapStatus;
use App\Enums\WalletType;
use App\Models\SettlementWebhookEvent;
use App\Models\Swap;
use App\Models\Wallet;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ProcessSettlementWebhook implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [1, 5, 15, 30];

    public function __construct(public readonly string $eventId)
    {
        $this->onQueue('settlements');
    }

    public function handle(LedgerService $ledger): void
    {
        DB::transaction(function () use ($ledger): void {
            DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');

            $event = SettlementWebhookEvent::query()->lockForUpdate()->find($this->eventId);
            if ($event === null) {
                return;
            }

            if ($event->processed_at !== null) {
                return;
            }

            $swap = Swap::query()
                ->where('provider_reference', $event->provider_reference)
                ->lockForUpdate()
                ->first();

            if ($swap === null) {
                throw new RuntimeException('Settlement references an unknown swap.');
            }

            $incomingStatus = $event->status;
            $currentStatus = $swap->status;

            if (! $incomingStatus instanceof SwapStatus || ! $currentStatus instanceof SwapStatus) {
                throw new RuntimeException('Settlement status is invalid.');
            }

            if ($currentStatus->isTerminal() || $incomingStatus->rank() <= $currentStatus->rank()) {
                $event->update(['processed_at' => now()]);

                return;
            }

            if ($incomingStatus === SwapStatus::COMPLETED) {
                $this->completeSettlement($swap, $ledger);
            } else {
                $swap->update(['status' => $incomingStatus]);
            }

            $event->update(['processed_at' => now()]);
        }, 3);
    }

    private function completeSettlement(Swap $swap, LedgerService $ledger): void
    {
        if ($swap->settlement_ledger_transaction_id !== null) {
            $swap->update(['status' => SwapStatus::COMPLETED]);

            return;
        }

        $liquidityId = Wallet::query()
            ->whereNull('user_id')
            ->where('type', WalletType::LIQUIDITY)
            ->where('currency', Currency::CNY)
            ->value('id');

        if (! is_string($liquidityId)) {
            throw new RuntimeException('The CNY liquidity wallet is not configured.');
        }

        $walletIds = [$swap->destination_wallet_id, $liquidityId];
        sort($walletIds, SORT_STRING);

        /** @var Collection<int, Wallet> $wallets */
        $wallets = Wallet::query()
            ->whereIn('id', $walletIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        /** @var Wallet|null $destination */
        $destination = $wallets->firstWhere('id', $swap->destination_wallet_id);
        /** @var Wallet|null $liquidity */
        $liquidity = $wallets->firstWhere('id', $liquidityId);

        if ($destination === null || $liquidity === null || $destination->currency !== Currency::CNY) {
            throw new RuntimeException('Settlement wallets are invalid.');
        }

        Wallet::query()->whereIn('id', $walletIds)->increment('lock_version');

        $settlementTransaction = $ledger->post(LedgerTransactionType::SETTLEMENT_CREDIT, [
            ['wallet' => $liquidity, 'amount_subunits' => -$swap->destination_amount_subunits],
            ['wallet' => $destination, 'amount_subunits' => $swap->destination_amount_subunits],
        ], [
            'swap_id' => (string) $swap->getKey(),
            'provider_reference' => $swap->provider_reference,
        ]);

        $swap->update([
            'status' => SwapStatus::COMPLETED,
            'settlement_ledger_transaction_id' => $settlementTransaction->getKey(),
        ]);
    }
}
