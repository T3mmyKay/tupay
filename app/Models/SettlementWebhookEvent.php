<?php

namespace App\Models;

use App\Enums\SwapStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class SettlementWebhookEvent extends Model
{
    use HasUuids;

    /** @var list<string> */
    protected $fillable = [
        'provider_reference',
        'status',
        'idempotency_key',
        'payload',
        'processed_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => SwapStatus::class,
            'payload' => 'array',
            'processed_at' => 'immutable_datetime',
        ];
    }
}
