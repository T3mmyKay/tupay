<?php

namespace App\Domain\Swap;

use App\Models\Swap;
use App\Models\User;
use Closure;

final class SwapIdempotencyService
{
    public function __construct(private readonly DistributedLockManager $locks) {}

    /** @param Closure(): Swap $create */
    public function execute(
        User $user,
        string $idempotencyKey,
        string $requestHash,
        Closure $create,
    ): IdempotentSwapResult {
        $lockKey = 'idempotency:swap:'.$user->getKey().':'.hash('sha256', $idempotencyKey);

        return $this->locks->withLocks([$lockKey], function () use ($user, $idempotencyKey, $requestHash, $create): IdempotentSwapResult {
            $existing = Swap::query()
                ->where('user_id', $user->getKey())
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existing !== null) {
                if (! hash_equals((string) $existing->getAttribute('request_hash'), $requestHash)) {
                    throw new IdempotencyConflict('This idempotency key was already used with a different request.');
                }

                return new IdempotentSwapResult($existing, true);
            }

            return new IdempotentSwapResult($create(), false);
        });
    }
}
