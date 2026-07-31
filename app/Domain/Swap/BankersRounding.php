<?php

namespace App\Domain\Swap;

use InvalidArgumentException;
use OverflowException;

final class BankersRounding
{
    public function toInteger(string $decimal): int
    {
        if (! preg_match('/^-?\d+(?:\.\d+)?$/', $decimal)) {
            throw new InvalidArgumentException('A canonical decimal string is required.');
        }

        $negative = str_starts_with($decimal, '-');
        $absolute = ltrim($decimal, '-');
        [$integerPart, $fractionPart] = array_pad(explode('.', $absolute, 2), 2, '');
        $fractionPart = rtrim($fractionPart, '0');

        $comparison = $this->compareFractionToHalf($fractionPart);
        $rounded = $integerPart;

        if ($comparison > 0 || ($comparison === 0 && ((int) substr($integerPart, -1)) % 2 !== 0)) {
            $rounded = bcadd($integerPart, '1', 0);
        }

        $signed = $negative && $rounded !== '0' ? '-'.$rounded : $rounded;

        if (bccomp($signed, (string) PHP_INT_MAX, 0) > 0 || bccomp($signed, (string) PHP_INT_MIN, 0) < 0) {
            throw new OverflowException('Rounded value exceeds the platform integer range.');
        }

        return (int) $signed;
    }

    private function compareFractionToHalf(string $fraction): int
    {
        if ($fraction === '') {
            return -1;
        }

        $firstDigit = (int) $fraction[0];
        if ($firstDigit < 5) {
            return -1;
        }

        if ($firstDigit > 5) {
            return 1;
        }

        return trim(substr($fraction, 1), '0') === '' ? 0 : 1;
    }
}
