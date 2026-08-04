<?php

use App\Domain\Security\InvalidElevatedActionToken;
use App\Domain\Swap\FxRateUnavailable;
use App\Domain\Swap\IdempotencyConflict;
use App\Domain\Swap\InsufficientFunds;
use App\Domain\Swap\InvalidSwap;
use App\Domain\Swap\ResourceBusy;
use App\Http\Middleware\AddApiSecurityHeaders;
use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\VerifySettlementSignature;
use App\Http\Support\ProblemDetails;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->appendToGroup('api', [
            AssignRequestId::class,
            AddApiSecurityHeaders::class,
        ]);

        $middleware->alias([
            'settlement.signature' => VerifySettlementSignature::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            static fn (Request $request, Throwable $exception): bool => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->render(function (Throwable $exception, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return match (true) {
                $exception instanceof ValidationException => ProblemDetails::response(
                    $request,
                    422,
                    'VALIDATION_FAILED',
                    'Validation failed',
                    'One or more request fields are invalid.',
                    $exception->errors(),
                ),
                $exception instanceof AuthenticationException => ProblemDetails::response(
                    $request,
                    401,
                    'AUTHENTICATION_REQUIRED',
                    'Authentication required',
                    'A valid bearer token is required to access this resource.',
                ),
                $exception instanceof InvalidElevatedActionToken => ProblemDetails::response(
                    $request,
                    401,
                    'ELEVATED_ACTION_TOKEN_INVALID',
                    'Invalid elevated action token',
                    $exception->getMessage(),
                ),
                $exception instanceof AuthorizationException => ProblemDetails::response(
                    $request,
                    403,
                    'FORBIDDEN',
                    'Forbidden',
                    'The authenticated user is not allowed to perform this action.',
                ),
                $exception instanceof ModelNotFoundException,
                $exception instanceof NotFoundHttpException => ProblemDetails::response(
                    $request,
                    404,
                    'RESOURCE_NOT_FOUND',
                    'Resource not found',
                    'The requested API resource could not be found.',
                ),
                $exception instanceof IdempotencyConflict => ProblemDetails::response(
                    $request,
                    409,
                    'IDEMPOTENCY_CONFLICT',
                    'Idempotency conflict',
                    $exception->getMessage(),
                ),
                $exception instanceof ResourceBusy => ProblemDetails::response(
                    $request,
                    409,
                    'RESOURCE_BUSY',
                    'Resource busy',
                    $exception->getMessage(),
                ),
                $exception instanceof InsufficientFunds => ProblemDetails::response(
                    $request,
                    422,
                    'INSUFFICIENT_FUNDS',
                    'Insufficient funds',
                    $exception->getMessage(),
                ),
                $exception instanceof InvalidSwap => ProblemDetails::response(
                    $request,
                    422,
                    'INVALID_SWAP',
                    'Invalid swap',
                    $exception->getMessage(),
                ),
                $exception instanceof FxRateUnavailable => ProblemDetails::response(
                    $request,
                    503,
                    'FX_RATE_UNAVAILABLE',
                    'FX rate unavailable',
                    $exception->getMessage(),
                ),
                $exception instanceof ThrottleRequestsException => ProblemDetails::response(
                    $request,
                    429,
                    'RATE_LIMIT_EXCEEDED',
                    'Too many requests',
                    'The request rate limit has been exceeded. Retry after the indicated interval.',
                    [],
                    $exception->getHeaders(),
                ),
                $exception instanceof HttpExceptionInterface => ProblemDetails::response(
                    $request,
                    $exception->getStatusCode(),
                    'HTTP_ERROR',
                    'HTTP request failed',
                    $exception->getMessage() !== '' ? $exception->getMessage() : 'The request could not be completed.',
                ),
                default => (function () use ($exception, $request) {
                    report($exception);

                    return ProblemDetails::response(
                        $request,
                        500,
                        'INTERNAL_SERVER_ERROR',
                        'Internal server error',
                        'An unexpected error occurred while processing the request.',
                    );
                })(),
            };
        });
    })
    ->create();
