<?php

namespace App\Models;

use App\Enums\Currency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LedgerEntry extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'ledger_transaction_id',
        'wallet_id',
        'currency',
        'amount_subunits',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'currency' => Currency::class,
            'amount_subunits' => 'integer',
        ];
    }

    /** @return BelongsTo<LedgerTransaction, $this> */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(LedgerTransaction::class, 'ledger_transaction_id');
    }

    /** @return BelongsTo<Wallet, $this> */
    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }
}
