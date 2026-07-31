<?php

namespace App\Domain\Swap;

use App\Models\Swap;

final readonly class IdempotentSwapResult
{
    public function __construct(
        public Swap $swap,
        public bool $replayed,
    ) {}
}
