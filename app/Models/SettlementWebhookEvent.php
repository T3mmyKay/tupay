<?php

namespace App\Models;

use App\Enums\SwapStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use LogicException;

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

    public function statusEnum(): SwapStatus
    {
        $status = $this->getAttribute('status');

        if ($status instanceof SwapStatus) {
            return $status;
        }

        if (is_string($status)) {
            return SwapStatus::from($status);
        }

        throw new LogicException('Settlement event status is invalid.');
    }

    public function providerReference(): string
    {
        return (string) $this->getAttribute('provider_reference');
    }
}
