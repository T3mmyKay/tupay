<?php

namespace App\Http\Resources;

use App\Models\LedgerEntry;
use App\Models\Wallet;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class LedgerResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var array{wallet: Wallet, entries: list<LedgerEntry>, next_cursor: string|null, per_page: int} $data */
        $data = $this->resource;

        return [
            'wallet' => new WalletResource($data['wallet']),
            'entries' => LedgerEntryResource::collection(collect($data['entries'])),
            'pagination' => [
                'next_cursor' => $data['next_cursor'],
                'per_page' => $data['per_page'],
            ],
        ];
    }
}
