<?php

namespace App\Domain\Swap;

final class SwapQuoteService
{
    public function __construct(
        private readonly FxRateService $rates,
        private readonly SlippageCalculator $slippage,
        private readonly BankersRounding $rounding,
    ) {}

    public function quoteNgnToCny(int $sourceAmountSubunits): SwapQuote
    {
        $marketRate = $this->rates->ngnToCny();
        $spreadBasisPoints = $this->slippage->basisPoints($sourceAmountSubunits);
        $spreadRatio = bcdiv((string) $spreadBasisPoints, '10000', 18);
        $effectiveRate = bcmul($marketRate, bcsub('1', $spreadRatio, 18), 18);
        $rawDestinationAmount = bcmul((string) $sourceAmountSubunits, $effectiveRate, 18);
        $destinationAmount = $this->rounding->toInteger($rawDestinationAmount);

        if ($destinationAmount < 1) {
            throw new InvalidSwap('Calculated destination amount is below one subunit.');
        }

        return new SwapQuote(
            marketRate: $marketRate,
            effectiveRate: $effectiveRate,
            spreadBasisPoints: $spreadBasisPoints,
            destinationAmountSubunits: $destinationAmount,
        );
    }
}
