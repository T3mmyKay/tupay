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

        /** @var list<array{id: string, currency: string, balance_subunits: int}> $wallets */
        $wallets = [];

        foreach ($user->wallets()->orderBy('currency')->get() as $wallet) {
            $wallets[] = [
                'id' => (string) $wallet->getKey(),
                'currency' => $wallet->currencyEnum()->value,
                'balance_subunits' => $balances->balance($wallet),
            ];
        }

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
