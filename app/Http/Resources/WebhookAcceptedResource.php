<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class WebhookAcceptedResource extends JsonResource
{
    /** @return array{accepted: bool, duplicate: bool} */
    public function toArray(Request $request): array
    {
        /** @var array{duplicate: bool} $data */
        $data = $this->resource;

        return [
            'accepted' => true,
            'duplicate' => $data['duplicate'],
        ];
    }
}
