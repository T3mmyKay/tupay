<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class AddDeprecationHeaders
{
    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $successor = '/api/v1/'.ltrim($request->path() === 'api' ? '' : preg_replace('#^api/#', '', $request->path()) ?? '', '/');

        $response->headers->set('Deprecation', 'true');
        $response->headers->set('Sunset', 'Thu, 31 Dec 2026 23:59:59 GMT');
        $response->headers->set('Link', '<'.$successor.'>; rel="successor-version"');

        return $response;
    }
}
