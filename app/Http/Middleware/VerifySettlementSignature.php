<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifySettlementSignature
{
    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $secret = (string) config('services.settlement.webhook_secret');
        $provided = $request->header('X-Tupay-Signature');

        if ($secret === '' || ! is_string($provided)) {
            return new JsonResponse(['message' => 'Invalid webhook signature.'], 401);
        }

        $provided = str_starts_with($provided, 'sha256=') ? substr($provided, 7) : $provided;
        $expected = hash_hmac('sha256', $request->getContent(), $secret);

        if (! hash_equals($expected, $provided)) {
            return new JsonResponse(['message' => 'Invalid webhook signature.'], 401);
        }

        return $next($request);
    }
}
