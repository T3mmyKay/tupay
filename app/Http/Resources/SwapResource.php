<?php

namespace App\Http\Resources;

use App\Models\Swap;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Swap */
final class SwapResource extends JsonResource
{
    /**
     * @return array{
     *   id: string,
     *   provider_reference: string,
     *   status: string,
     *   source_wallet_id: string,
     *   destination_wallet_id: string,
     *   source_amount_subunits: int,
     *   destination_amount_subunits: int,
     *   quoted_rate: string,
     *   spread_basis_points: int,
     *   created_at: string|null
     * }
     */
    public function toArray(Request $request): array
    {
        $createdAt = $this->resource->getAttribute('created_at');

        return [
            'id' => (string) $this->resource->getKey(),
            'provider_reference' => $this->resource->providerReference(),
            'status' => $this->resource->statusEnum()->value,
            'source_wallet_id' => (string) $this->resource->getAttribute('source_wallet_id'),
            'destination_wallet_id' => $this->resource->destinationWalletId(),
            'source_amount_subunits' => (int) $this->resource->getAttribute('source_amount_subunits'),
            'destination_amount_subunits' => $this->resource->destinationAmountSubunits(),
            'quoted_rate' => (string) $this->resource->getAttribute('quoted_rate'),
            'spread_basis_points' => (int) $this->resource->getAttribute('spread_basis_points'),
            'created_at' => $createdAt instanceof \DateTimeInterface ? $createdAt->format(DATE_ATOM) : null,
        ];
    }
}
