<?php

namespace App\Models;

use App\Enums\SwapStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class Swap extends Model
{
    use HasUuids;

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'source_wallet_id',
        'destination_wallet_id',
        'source_amount_subunits',
        'destination_amount_subunits',
        'quoted_rate',
        'spread_basis_points',
        'provider_reference',
        'status',
        'debit_ledger_transaction_id',
        'settlement_ledger_transaction_id',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'source_amount_subunits' => 'integer',
            'destination_amount_subunits' => 'integer',
            'spread_basis_points' => 'integer',
            'status' => SwapStatus::class,
        ];
    }

    public function statusEnum(): SwapStatus
    {
        $status = $this->getAttribute('status');

        if ($status instanceof SwapStatus) {
            return $status;
        }

        if (is_string($status)) {
            return SwapStatus::from($status);
        }

        throw new LogicException('Swap status is invalid.');
    }

    public function destinationWalletId(): string
    {
        return (string) $this->getAttribute('destination_wallet_id');
    }

    public function destinationAmountSubunits(): int
    {
        return (int) $this->getAttribute('destination_amount_subunits');
    }

    public function providerReference(): string
    {
        return (string) $this->getAttribute('provider_reference');
    }

    public function settlementLedgerTransactionId(): ?string
    {
        $transactionId = $this->getAttribute('settlement_ledger_transaction_id');

        return is_string($transactionId) ? $transactionId : null;
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Wallet, $this> */
    public function sourceWallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class, 'source_wallet_id');
    }

    /** @return BelongsTo<Wallet, $this> */
    public function destinationWallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class, 'destination_wallet_id');
    }
}
