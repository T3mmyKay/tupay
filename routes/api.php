<?php

use App\Http\Controllers\Api\LedgerController;
use App\Http\Controllers\Api\LoginController;
use App\Http\Controllers\Api\SettlementWebhookController;
use App\Http\Controllers\Api\StepUpChallengeController;
use App\Http\Controllers\Api\SwapController;
use Illuminate\Support\Facades\Route;

Route::get('/health', static fn (): array => ['status' => 'ok']);

Route::post('/login', LoginController::class)->middleware('throttle:login');
Route::post('/webhooks/settlement', SettlementWebhookController::class)
    ->middleware('settlement.signature');

Route::middleware('auth:sanctum')->group(function (): void {
    Route::post('/2fa/challenge', StepUpChallengeController::class)->middleware('throttle:financial');
    Route::post('/swap', SwapController::class)->middleware('throttle:financial');
    Route::get('/ledger/{walletId}', LedgerController::class);
});
