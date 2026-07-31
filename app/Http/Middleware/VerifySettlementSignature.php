<?php

namespace App\Http\Middleware;

use App\Http\Support\ProblemDetails;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class VerifySettlementSignature
{
    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $secret = (string) config('services.settlement.webhook_secret');
        $provided = $request->header('X-Tupay-Signature');
        $timestamp = $request->header('X-Tupay-Timestamp');

        if ($secret === '' || ! is_string($provided) || ! is_string($timestamp)) {
            return ProblemDetails::response(
                $request,
                401,
                'WEBHOOK_SIGNATURE_INVALID',
                'Invalid webhook signature',
                'The webhook signature and timestamp headers are required.',
            );
        }

        if (preg_match('/^\d{10}$/', $timestamp) !== 1) {
            return ProblemDetails::response(
                $request,
                401,
                'WEBHOOK_TIMESTAMP_INVALID',
                'Invalid webhook timestamp',
                'The webhook timestamp must be a Unix timestamp.',
            );
        }

        $tolerance = (int) config('services.settlement.webhook_tolerance_seconds', 300);
        if (abs(time() - (int) $timestamp) > $tolerance) {
            return ProblemDetails::response(
                $request,
                401,
                'WEBHOOK_REPLAY_WINDOW_EXCEEDED',
                'Webhook timestamp expired',
                'The webhook timestamp falls outside the accepted replay-protection window.',
            );
        }

        $provided = str_starts_with($provided, 'sha256=') ? substr($provided, 7) : $provided;
        $signedPayload = $timestamp.'.'.$request->getContent();
        $expected = hash_hmac('sha256', $signedPayload, $secret);

        if (! hash_equals($expected, $provided)) {
            return ProblemDetails::response(
                $request,
                401,
                'WEBHOOK_SIGNATURE_INVALID',
                'Invalid webhook signature',
                'The webhook signature could not be verified.',
            );
        }

        return $next($request);
    }
}
