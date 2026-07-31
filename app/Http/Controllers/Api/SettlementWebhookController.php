<?php

namespace App\Http\Controllers\Api;

use App\Domain\Swap\IdempotencyConflict;
use App\Http\Controllers\Controller;
use App\Http\Requests\SettlementWebhookRequest;
use App\Http\Resources\WebhookAcceptedResource;
use App\Jobs\ProcessSettlementWebhook;
use App\Models\SettlementWebhookEvent;
use Dedoc\Scramble\Attributes\Header;
use Dedoc\Scramble\Attributes\HeaderParameter;
use Illuminate\Http\JsonResponse;

class SettlementWebhookController extends Controller
{
    /**
     * Receive a settlement provider event.
     *
     * The signature is calculated as HMAC-SHA256 over `X-Tupay-Timestamp + "." + raw body`.
     * Events outside the configured clock-skew window are rejected before processing.
     *
     * @unauthenticated
     */
    #[HeaderParameter('X-Tupay-Timestamp', description: 'Unix timestamp used in the signature.', required: true, type: 'int')]
    #[HeaderParameter('X-Tupay-Signature', description: 'HMAC-SHA256 signature, optionally prefixed with sha256=.', required: true, type: 'string')]
    #[Header('X-Request-ID', 'Request correlation identifier.', type: 'string', required: true)]
    public function __invoke(SettlementWebhookRequest $request): JsonResponse
    {
        /** @var array{event_id: string, provider_reference: string, status: string, occurred_at: string} $validated */
        $validated = $request->validated();

        $existingEvent = SettlementWebhookEvent::query()
            ->where('event_id', $validated['event_id'])
            ->first();

        if ($existingEvent !== null) {
            $samePayload = hash_equals((string) $existingEvent->getAttribute('provider_reference'), $validated['provider_reference'])
                && hash_equals((string) $existingEvent->getAttribute('status'), $validated['status']);

            if (! $samePayload) {
                throw new IdempotencyConflict('The webhook event ID was already used with a different payload.');
            }

            return (new WebhookAcceptedResource(['duplicate' => true]))
                ->response()
                ->setStatusCode(202);
        }

        $idempotencyKey = hash(
            'sha256',
            $validated['provider_reference'].'|'.$validated['status'],
        );

        $event = SettlementWebhookEvent::query()->firstOrCreate(
            ['idempotency_key' => $idempotencyKey],
            [
                'event_id' => $validated['event_id'],
                'provider_reference' => $validated['provider_reference'],
                'status' => $validated['status'],
                'payload' => $request->all(),
            ],
        );

        if ($event->wasRecentlyCreated) {
            ProcessSettlementWebhook::dispatch((string) $event->getKey());
        }

        return (new WebhookAcceptedResource(['duplicate' => ! $event->wasRecentlyCreated]))
            ->response()
            ->setStatusCode(202);
    }
}
