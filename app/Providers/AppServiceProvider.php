<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        RateLimiter::for('login', static function (Request $request): array {
            $email = strtolower($request->string('email')->toString());
            $fingerprint = hash('sha256', $email.'|'.(string) $request->ip());

            return [
                Limit::perMinute(5)->by('login:'.$fingerprint),
                Limit::perHour(30)->by('login-ip:'.(string) $request->ip()),
            ];
        });

        RateLimiter::for('financial', static function (Request $request): Limit {
            $userId = $request->user()?->getAuthIdentifier();

            return Limit::perMinute(20)->by('financial:'.(string) ($userId ?? $request->ip()));
        });

        RateLimiter::for('read', static function (Request $request): Limit {
            $userId = $request->user()?->getAuthIdentifier();

            return Limit::perMinute(120)->by('read:'.(string) ($userId ?? $request->ip()));
        });

        RateLimiter::for('webhooks', static fn (Request $request): Limit => Limit::perMinute(120)
            ->by('webhook:'.(string) $request->ip()));

        Gate::define('viewApiDocs', static function (?User $user = null): bool {
            if (app()->environment(['local', 'testing'])) {
                return true;
            }

            $allowedEmails = config('scramble.authorized_emails', []);

            return $user !== null
                && is_array($allowedEmails)
                && in_array($user->email, $allowedEmails, true);
        });
    }
}
