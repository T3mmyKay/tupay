<?php

namespace App\Http\Controllers\Api;

use App\Domain\Security\ElevatedActionTokenService;
use App\Domain\Security\InvalidElevatedActionToken;
use App\Domain\Swap\FxRateUnavailable;
use App\Domain\Swap\InvalidSwap;
use App\Domain\Swap\ResourceBusy;
use App\Domain\Swap\SwapService;
use App\Http\Controllers\Controller;
use App\Http\Requests\SwapRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class SwapController extends Controller
{
    public function __invoke(
        SwapRequest $request,
        ElevatedActionTokenService $tokens,
        SwapService $swaps,
    ): JsonResponse {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $token = $request->header('X-Elevated-Action-Token');
        if (! is_string($token) || $token === '') {
            return response()->json(['message' => 'An elevated action token is required.'], 401);
        }

        try {
            $tokens->consume($user, $request->actionPayload(), $token);
            $swap = $swaps->execute(
                $user,
                (string) $request->validated('source_wallet_id'),
                (string) $request->validated('destination_wallet_id'),
                (int) $request->validated('amount_subunits'),
            );
        } catch (InvalidElevatedActionToken $exception) {
            return response()->json(['message' => $exception->getMessage()], 401);
        } catch (ResourceBusy $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        } catch (FxRateUnavailable $exception) {
            return response()->json(['message' => $exception->getMessage()], 503);
        } catch (InvalidSwap $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'data' => [
                'id' => (string) $swap->getKey(),
                'provider_reference' => $swap->provider_reference,
                'status' => $swap->statusEnum()->value,
                'source_amount_subunits' => $swap->source_amount_subunits,
                'destination_amount_subunits' => $swap->destination_amount_subunits,
                'quoted_rate' => $swap->quoted_rate,
                'spread_basis_points' => $swap->spread_basis_points,
            ],
        ]);
    }
}
