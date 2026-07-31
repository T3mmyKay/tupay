<?php

namespace App\Http\Controllers\Api;

use App\Domain\Security\ElevatedActionTokenService;
use App\Http\Controllers\Controller;
use App\Http\Requests\StepUpChallengeRequest;
use App\Http\Resources\StepUpTokenResource;
use App\Models\User;
use Illuminate\Validation\ValidationException;
use PragmaRX\Google2FA\Google2FA;

class StepUpChallengeController extends Controller
{
    /**
     * Issue an elevated action token.
     *
     * Verifies the user's TOTP and binds a single-use 60-second token to the exact financial action.
     */
    public function __invoke(
        StepUpChallengeRequest $request,
        Google2FA $google2fa,
        ElevatedActionTokenService $tokens,
    ): StepUpTokenResource {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        if (! $google2fa->verifyKey($user->totp_secret, $request->string('totp_code')->toString(), 1)) {
            throw ValidationException::withMessages([
                'totp_code' => ['The supplied authentication code is invalid.'],
            ]);
        }

        return new StepUpTokenResource([
            'token' => $tokens->issue($user, $request->actionPayload()),
            'expires_in' => (int) config('services.eat.ttl_seconds', 60),
        ]);
    }
}
