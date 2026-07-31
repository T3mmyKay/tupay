<?php

namespace App\Http\Controllers\Api;

use App\Domain\Security\ElevatedActionTokenService;
use App\Http\Controllers\Controller;
use App\Http\Requests\StepUpChallengeRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use PragmaRX\Google2FA\Google2FA;

class StepUpChallengeController extends Controller
{
    public function __invoke(
        StepUpChallengeRequest $request,
        Google2FA $google2fa,
        ElevatedActionTokenService $tokens,
    ): JsonResponse {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        if (! $google2fa->verifyKey($user->totp_secret, $request->string('totp_code')->toString(), 1)) {
            throw ValidationException::withMessages([
                'totp_code' => ['The supplied authentication code is invalid.'],
            ]);
        }

        return response()->json([
            'elevated_action_token' => $tokens->issue($user, $request->actionPayload()),
            'token_type' => 'EAT',
            'expires_in' => (int) config('services.eat.ttl_seconds', 60),
        ]);
    }
}
