<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

final class RestoreLegacyApiEnvelope
{
    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $response instanceof JsonResponse || $response->getStatusCode() >= 400) {
            return $response;
        }

        $shouldUnwrap = $request->is(
            'api/health',
            'api/login',
            'api/2fa/challenge',
            'api/webhooks/settlement',
            'api/ledger/*',
        );

        if (! $shouldUnwrap) {
            return $response;
        }

        $payload = json_decode((string) $response->getContent(), true);
        if (! is_array($payload) || ! array_key_exists('data', $payload) || ! is_array($payload['data'])) {
            return $response;
        }

        $response->setData($payload['data']);

        return $response;
    }
}
