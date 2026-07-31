<?php

use App\Http\Controllers\Api\LedgerController;
use App\Http\Controllers\Api\LoginController;
use App\Http\Controllers\Api\SettlementWebhookController;
use App\Http\Controllers\Api\StepUpChallengeController;
use App\Http\Controllers\Api\SwapController;
use Illuminate\Support\Facades\Route;

$registerApiRoutes = static function (): void {
    Route::get('/health', static fn (): array => ['data' => ['status' => 'ok']]);

    Route::post('/login', LoginController::class)->middleware('throttle:login');
    Route::post('/webhooks/settlement', SettlementWebhookController::class)
        ->middleware(['settlement.signature', 'throttle:webhooks']);

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::post('/2fa/challenge', StepUpChallengeController::class)->middleware('throttle:financial');
        Route::post('/swap', SwapController::class)->middleware('throttle:financial');
        Route::get('/ledger/{walletId}', LedgerController::class)->middleware('throttle:read');
    });
};

Route::prefix('v1')->name('api.v1.')->group($registerApiRoutes);

// Original assessment paths preserve their request and response contracts while advertising v1.
Route::middleware(['api.deprecated', 'api.legacy'])->group($registerApiRoutes);

Route::fallback(static fn () => abort(404));
