<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin User */
final class UserResource extends JsonResource
{
    /** @return array{id: string, name: string, email: string} */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->resource->getKey(),
            'name' => (string) $this->resource->getAttribute('name'),
            'email' => (string) $this->resource->getAttribute('email'),
        ];
    }
}
