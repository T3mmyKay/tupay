<?php

namespace App\Domain\Swap;

use App\Jobs\RefreshFxRate;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use JsonException;
use Throwable;

final class FxRateService
{
    private const CACHE_KEY = 'fx:NGN:CNY';

    private const REFRESH_LOCK_KEY = 'fx:NGN:CNY:refreshing';

    public function ngnToCny(): string
    {
        $cached = $this->readCached();
        if ($cached === null) {
            return $this->refresh();
        }

        $age = time() - $cached['fetched_at'];
        $freshSeconds = max(1, (int) config('services.fx.fresh_seconds', 30));
        $staleSeconds = max($freshSeconds, (int) config('services.fx.stale_seconds', 300));

        if ($age <= $freshSeconds) {
            return $cached['rate'];
        }

        if ($age <= $staleSeconds) {
            $lockAcquired = $this->redis()->command('set', [self::REFRESH_LOCK_KEY, '1', 'EX', '15', 'NX']);
            if ($lockAcquired === true || $lockAcquired === 'OK') {
                RefreshFxRate::dispatch();
            }

            return $cached['rate'];
        }

        return $this->refresh();
    }

    public function refresh(): string
    {
        try {
            $rate = $this->fetchRate();
            $encoded = json_encode([
                'rate' => $rate,
                'fetched_at' => time(),
            ], JSON_THROW_ON_ERROR);
            $this->redis()->set(self::CACHE_KEY, $encoded);

            return $rate;
        } catch (Throwable $exception) {
            throw new FxRateUnavailable('The NGN/CNY rate is currently unavailable.', previous: $exception);
        }
    }

    public function clearRefreshMarker(): void
    {
        $this->redis()->del(self::REFRESH_LOCK_KEY);
    }

    private function fetchRate(): string
    {
        $staticRate = trim((string) config('services.fx.static_rate'));
        if ($staticRate !== '') {
            return $this->validateRate($staticRate);
        }

        $url = (string) config('services.fx.url');
        if ($url === '') {
            throw new FxRateUnavailable('FX_RATE_URL is not configured.');
        }

        try {
            $response = Http::acceptJson()->timeout(3)->retry(2, 100)->get($url)->throw();
        } catch (ConnectionException $exception) {
            throw new FxRateUnavailable('The external FX provider could not be reached.', previous: $exception);
        }

        $rate = $response->json('rate') ?? $response->json('data.rate');
        if (! is_string($rate) && ! is_int($rate)) {
            throw new FxRateUnavailable('The external FX provider returned an invalid rate.');
        }

        return $this->validateRate((string) $rate);
    }

    private function validateRate(string $rate): string
    {
        if (! preg_match('/^\d+(?:\.\d+)?$/', $rate) || bccomp($rate, '0', 18) <= 0) {
            throw new FxRateUnavailable('The FX rate must be a positive decimal string.');
        }

        return $rate;
    }

    /** @return array{rate: string, fetched_at: int}|null */
    private function readCached(): ?array
    {
        $cached = $this->redis()->get(self::CACHE_KEY);
        if (! is_string($cached)) {
            return null;
        }

        try {
            $decoded = json_decode($cached, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (! is_array($decoded) || ! is_string($decoded['rate'] ?? null) || ! is_int($decoded['fetched_at'] ?? null)) {
            return null;
        }

        return [
            'rate' => $decoded['rate'],
            'fetched_at' => $decoded['fetched_at'],
        ];
    }

    private function redis(): Connection
    {
        return Redis::connection();
    }
}
