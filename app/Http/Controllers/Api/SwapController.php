<?php

namespace App\Http\Controllers\Api;

use App\Domain\Security\ElevatedActionTokenService;
use App\Domain\Swap\SwapIdempotencyService;
use App\Domain\Swap\SwapService;
use App\Http\Controllers\Controller;
use App\Http\Requests\SwapRequest;
use App\Http\Resources\SwapResource;
use App\Models\Swap;
use App\Models\User;
use Dedoc\Scramble\Attributes\Header;
use Dedoc\Scramble\Attributes\HeaderParameter;
use Illuminate\Http\JsonResponse;

class SwapController extends Controller
{
    /**
     * Initiate an NGN to CNY swap.
     *
     * The elevated action token is bound to the exact wallet IDs and amount. Reusing the same
     * idempotency key with an identical request safely returns the original swap.
     */
    #[HeaderParameter(
        'X-Elevated-Action-Token',
        description: 'Single-use action-bound token returned by the 2FA challenge.',
        required: true,
        type: 'string',
    )]
    #[HeaderParameter(
        'Idempotency-Key',
        description: 'Unique key for safely retrying this swap request.',
        required: true,
        type: 'string',
        example: 'swap-018f7f32-15af-7e4a-8f51-2ad8f7bf6c88',
    )]
    #[Header('Idempotent-Replayed', 'Whether the response was replayed from an existing swap.', type: 'bool', required: true)]
    #[Header('X-Request-ID', 'Request correlation identifier.', type: 'string', required: true)]
    public function __invoke(
        SwapRequest $request,
        ElevatedActionTokenService $tokens,
        SwapIdempotencyService $idempotency,
        SwapService $swaps,
    ): JsonResponse {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $idempotencyKey = $request->idempotencyKey();
        $requestHash = $request->requestHash();

        $result = $idempotency->execute(
            $user,
            $idempotencyKey,
            $requestHash,
            function () use ($request, $user, $tokens, $swaps, $idempotencyKey, $requestHash): Swap {
                $token = $request->header('X-Elevated-Action-Token');
                if (! is_string($token) || $token === '') {
                    abort(401, 'An elevated action token is required.');
                }

                $tokens->consume($user, $request->actionPayload(), $token);

                return $swaps->execute(
                    $user,
                    (string) $request->validated('source_wallet_id'),
                    (string) $request->validated('destination_wallet_id'),
                    (int) $request->validated('amount_subunits'),
                    $idempotencyKey,
                    $requestHash,
                );
            },
        );

        $response = (new SwapResource($result->swap))->response();
        $response->setStatusCode(200);
        $response->headers->set('Idempotent-Replayed', $result->replayed ? 'true' : 'false');

        return $response;
    }
}
