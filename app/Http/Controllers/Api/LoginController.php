<?php

namespace App\Http\Controllers\Api;

use App\Domain\Ledger\WalletBalanceService;
use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Resources\LoginResource;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class LoginController extends Controller
{
    /**
     * Authenticate a user.
     *
     * Returns a Sanctum bearer token and the user's current wallet balances.
     *
     * @unauthenticated
     */
    public function __invoke(LoginRequest $request, WalletBalanceService $balances): LoginResource
    {
        $user = User::query()->where('email', $request->string('email')->toString())->first();

        if ($user === null || ! Hash::check($request->string('password')->toString(), $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The supplied credentials are invalid.'],
            ]);
        }

        $user->tokens()->delete();
        $token = $user->createToken('tupay-api')->plainTextToken;
        $wallets = $user->wallets()->orderBy('currency')->get();

        foreach ($wallets as $wallet) {
            $wallet->setAttribute('balance_subunits', $balances->balance($wallet));
        }

        return new LoginResource([
            'token' => $token,
            'user' => $user,
            'wallets' => $wallets,
        ]);
    }
}
