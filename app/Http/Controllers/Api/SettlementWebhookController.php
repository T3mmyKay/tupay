<?php

namespace App\Http\Controllers\Api;

use App\Enums\SwapStatus;
use App\Http\Controllers\Controller;
use App\Jobs\ProcessSettlementWebhook;
use App\Models\SettlementWebhookEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SettlementWebhookController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        /** @var array{provider_reference: string, status: string} $validated */
        $validated = $request->validate([
            'provider_reference' => ['required', 'string', 'max:100'],
            'status' => [
                'required',
                'string',
                Rule::in([
                    SwapStatus::INITIATED->value,
                    SwapStatus::PROCESSING->value,
                    SwapStatus::COMPLETED->value,
                    SwapStatus::FAILED->value,
                ]),
            ],
        ]);

        $idempotencyKey = hash(
            'sha256',
            $validated['provider_reference'].'|'.$validated['status'],
        );

        $event = SettlementWebhookEvent::query()->firstOrCreate(
            ['idempotency_key' => $idempotencyKey],
            [
                'provider_reference' => $validated['provider_reference'],
                'status' => $validated['status'],
                'payload' => $request->all(),
            ],
        );

        if ($event->wasRecentlyCreated) {
            ProcessSettlementWebhook::dispatch((string) $event->getKey());
        }

        return response()->json([
            'accepted' => true,
            'duplicate' => ! $event->wasRecentlyCreated,
        ], 202);
    }
}
