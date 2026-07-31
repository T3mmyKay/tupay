<?php

namespace App\Http\Controllers\Api;

use App\Domain\Ledger\WalletBalanceService;
use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class LoginController extends Controller
{
    public function __invoke(LoginRequest $request, WalletBalanceService $balances): JsonResponse
    {
        $user = User::query()->where('email', $request->string('email')->toString())->first();

        if ($user === null || ! Hash::check($request->string('password')->toString(), $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The supplied credentials are invalid.'],
            ]);
        }

        $user->tokens()->delete();
        $token = $user->createToken('tupay-api')->plainTextToken;

        $wallets = $user->wallets()->orderBy('currency')->get()->map(static function ($wallet) use ($balances): array {
            return [
                'id' => (string) $wallet->getKey(),
                'currency' => $wallet->currency->value,
                'balance_subunits' => $balances->balance($wallet),
            ];
        })->values();

        return response()->json([
            'token' => $token,
            'token_type' => 'Bearer',
            'user' => [
                'id' => (string) $user->getKey(),
                'name' => $user->name,
                'email' => $user->email,
            ],
            'wallets' => $wallets,
        ]);
    }
}
