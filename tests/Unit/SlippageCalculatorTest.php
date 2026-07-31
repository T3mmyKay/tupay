<?php

namespace Tests\Unit;

use App\Domain\Swap\SlippageCalculator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SlippageCalculatorTest extends TestCase
{
    /** @return iterable<string, array{int, int}> */
    public static function tiers(): iterable
    {
        yield 'threshold has no spread' => [100_000_000, 0];
        yield 'first subunit above threshold starts base spread' => [100_000_001, 50];
        yield 'base tier includes exactly 1.5m NGN' => [150_000_000, 50];
        yield 'next subunit starts first additional tier' => [150_000_001, 60];
        yield '2m NGN remains first additional tier' => [200_000_000, 60];
        yield 'next subunit starts second additional tier' => [200_000_001, 70];
    }

    #[DataProvider('tiers')]
    public function test_it_calculates_progressive_spread(int $amount, int $basisPoints): void
    {
        self::assertSame($basisPoints, (new SlippageCalculator)->basisPoints($amount));
    }
}
