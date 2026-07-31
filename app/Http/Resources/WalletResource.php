<?php

namespace App\Http\Resources;

use App\Models\Wallet;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Wallet */
final class WalletResource extends JsonResource
{
    /** @return array{id: string, currency: string, balance_subunits: int} */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->resource->getKey(),
            'currency' => $this->resource->currencyEnum()->value,
            'balance_subunits' => (int) $this->resource->getAttribute('balance_subunits'),
        ];
    }
}
