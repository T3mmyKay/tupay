<?php

namespace Tests\Unit;

use App\Domain\Swap\BankersRounding;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class BankersRoundingTest extends TestCase
{
    /** @return iterable<string, array{string, int}> */
    public static function values(): iterable
    {
        yield 'even integer remains even on exact half' => ['2.500000', 2];
        yield 'odd integer advances on exact half' => ['3.500000', 4];
        yield 'below half rounds down' => ['9.499999', 9];
        yield 'above half rounds up' => ['9.500001', 10];
        yield 'negative exact half rounds to even' => ['-2.500000', -2];
        yield 'negative odd exact half rounds to even' => ['-3.500000', -4];
    }

    #[DataProvider('values')]
    public function test_it_uses_round_half_even(string $value, int $expected): void
    {
        self::assertSame($expected, (new BankersRounding())->toInteger($value));
    }
}
