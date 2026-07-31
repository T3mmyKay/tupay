<?php

namespace App\Models;

use App\Enums\Currency;
use App\Enums\WalletType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class Wallet extends Model
{
    use HasUuids;

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'currency',
        'type',
        'lock_version',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'currency' => Currency::class,
            'type' => WalletType::class,
            'lock_version' => 'integer',
        ];
    }

    public function currencyEnum(): Currency
    {
        $currency = $this->getAttribute('currency');

        if ($currency instanceof Currency) {
            return $currency;
        }

        if (is_string($currency)) {
            return Currency::from($currency);
        }

        throw new LogicException('Wallet currency is invalid.');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<LedgerEntry, $this> */
    public function entries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class);
    }
}
