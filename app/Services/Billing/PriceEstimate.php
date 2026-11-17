<?php

namespace App\Services\Billing;

/**
 * The range a generation can cost before it runs. The exact price is only
 * known on completion, because Pixelforge reports the real text and
 * reasoning token counts then. All amounts are in nanodollars.
 */
final readonly class PriceEstimate
{
    public function __construct(
        public int $minProviderCostNanodollars,
        public int $maxProviderCostNanodollars,
        public int $minChargeNanodollars,
        public int $maxChargeNanodollars,
    ) {}
}
