<?php

namespace App\Models;

use App\Enums\LedgerTransactionStatus;
use App\Enums\LedgerTransactionType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LedgerTransaction extends Model
{
    use HasUuids;

    /** @var list<string> */
    protected $fillable = [
        'type',
        'status',
        'metadata',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => LedgerTransactionType::class,
            'status' => LedgerTransactionStatus::class,
            'metadata' => 'array',
        ];
    }

    /** @return HasMany<LedgerEntry, $this> */
    public function entries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class);
    }
}
