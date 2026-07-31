<?php

namespace App\Http\Resources;

use App\Models\User;
use App\Models\Wallet;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class LoginResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var array{token: string, user: User, wallets: Collection<int, Wallet>} $data */
        $data = $this->resource;

        return [
            'token' => $data['token'],
            'token_type' => 'Bearer',
            'user' => new UserResource($data['user']),
            'wallets' => WalletResource::collection($data['wallets']),
        ];
    }
}
