<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        RateLimiter::for('login', static function (Request $request): Limit {
            return Limit::perMinute(5)->by((string) $request->ip());
        });

        RateLimiter::for('financial', static function (Request $request): Limit {
            $userId = $request->user()?->getAuthIdentifier();

            return Limit::perMinute(30)->by((string) ($userId ?? $request->ip()));
        });
    }
}
