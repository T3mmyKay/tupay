<?php

namespace App\Domain\Swap;

final class SlippageCalculator
{
    private const THRESHOLD_SUBUNITS = 100_000_000;

    private const BASE_TIER_UPPER_BOUND_SUBUNITS = 150_000_000;

    private const ADDITIONAL_TIER_SUBUNITS = 50_000_000;

    public function basisPoints(int $amountSubunits): int
    {
        if ($amountSubunits <= self::THRESHOLD_SUBUNITS) {
            return 0;
        }

        $amountBeyondBaseTier = max(0, $amountSubunits - self::BASE_TIER_UPPER_BOUND_SUBUNITS);
        $additionalTiers = $amountBeyondBaseTier === 0
            ? 0
            : intdiv($amountBeyondBaseTier + self::ADDITIONAL_TIER_SUBUNITS - 1, self::ADDITIONAL_TIER_SUBUNITS);

        return 50 + ($additionalTiers * 10);
    }
}
