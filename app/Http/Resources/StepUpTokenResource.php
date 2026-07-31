<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class StepUpTokenResource extends JsonResource
{
    /** @return array{elevated_action_token: string, token_type: string, expires_in: int} */
    public function toArray(Request $request): array
    {
        /** @var array{token: string, expires_in: int} $data */
        $data = $this->resource;

        return [
            'elevated_action_token' => $data['token'],
            'token_type' => 'EAT',
            'expires_in' => $data['expires_in'],
        ];
    }
}
