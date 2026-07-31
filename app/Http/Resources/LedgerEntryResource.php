<?php

namespace App\Http\Resources;

use App\Enums\Currency;
use App\Models\LedgerEntry;
use App\Models\LedgerTransaction;
use BackedEnum;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin LedgerEntry */
final class LedgerEntryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $currency = $this->resource->getAttribute('currency');
        $transaction = $this->resource->relationLoaded('transaction')
            ? $this->resource->getRelation('transaction')
            : null;
        $createdAt = $this->resource->getAttribute('created_at');

        $transactionData = null;
        if ($transaction instanceof LedgerTransaction) {
            $type = $transaction->getAttribute('type');
            $status = $transaction->getAttribute('status');
            $metadata = $transaction->getAttribute('metadata');

            $transactionData = [
                'id' => (string) $transaction->getKey(),
                'type' => $type instanceof BackedEnum ? (string) $type->value : (string) $type,
                'status' => $status instanceof BackedEnum ? (string) $status->value : (string) $status,
                'metadata' => is_array($metadata) ? $metadata : [],
            ];
        }

        return [
            'id' => (int) $this->resource->getKey(),
            'ledger_transaction_id' => (string) $this->resource->getAttribute('ledger_transaction_id'),
            'currency' => $currency instanceof Currency ? $currency->value : (string) $currency,
            'amount_subunits' => (int) $this->resource->getAttribute('amount_subunits'),
            'transaction' => $transactionData,
            'created_at' => $createdAt instanceof \DateTimeInterface ? $createdAt->format(DATE_ATOM) : null,
        ];
    }
}
