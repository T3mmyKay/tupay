<?php

namespace App\Domain\Swap;

final readonly class SwapQuote
{
    public function __construct(
        public string $marketRate,
        public string $effectiveRate,
        public int $spreadBasisPoints,
        public int $destinationAmountSubunits,
    ) {}
}
