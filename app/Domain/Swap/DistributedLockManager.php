<?php

namespace App\Domain\Swap;

use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;
use Throwable;

final class DistributedLockManager
{
    /**
     * @template T
     *
     * @param  list<string>  $keys
     * @param  callable(): T  $callback
     * @return T
     */
    public function withLocks(array $keys, callable $callback): mixed
    {
        sort($keys, SORT_STRING);

        /** @var list<Lock> $locks */
        $locks = [];

        try {
            foreach ($keys as $key) {
                $lock = Cache::store('redis')->lock($key, 10);
                if (! $lock->get()) {
                    throw new ResourceBusy('A conflicting financial operation is already in progress.');
                }

                $locks[] = $lock;
            }

            return $callback();
        } finally {
            foreach (array_reverse($locks) as $lock) {
                try {
                    $lock->release();
                } catch (Throwable) {
                    // Lock TTL remains the final safety net if Redis is unavailable during cleanup.
                }
            }
        }
    }
}
