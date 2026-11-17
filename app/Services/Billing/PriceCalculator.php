<?php

namespace App\Services\Billing;

use App\Enums\ImageQuality;
use App\Enums\ImageSize;
use App\Services\Pixelforge\Data\Pricing;
use App\Services\Pixelforge\Data\TokenRates;
use InvalidArgumentException;

/**
 * Works out what a generation costs, using integer nanodollars throughout
 * (1 USD = 1,000,000,000) so no amount is ever rounded by floating point.
 */
final class PriceCalculator
{
    private const int BASIS_POINTS = 10_000;

    /**
     * The lowest and highest possible price of a generation, from the
     * published pricing. Output and reference image tokens are exact. Text
     * tokens may differ from the estimate by the published variance, and
     * reasoning tokens can fall anywhere in the quality's range.
     */
    public function estimate(
        Pricing $pricing,
        string $prompt,
        ImageSize $size,
        ImageQuality $quality,
        int $referenceImageCount,
        int $markupPercent,
    ): PriceEstimate {
        $estimatedTextTokens = $this->divideRoundingUp(mb_strlen($prompt), $pricing->charactersPerTextToken);
        $varianceBasisPoints = $this->parseFixedPoint($pricing->textTokenVariance, decimals: 4);

        $minTextTokens = intdiv($estimatedTextTokens * (self::BASIS_POINTS - $varianceBasisPoints), self::BASIS_POINTS);
        $maxTextTokens = $this->divideRoundingUp($estimatedTextTokens * (self::BASIS_POINTS + $varianceBasisPoints), self::BASIS_POINTS);

        $imageTokens = $referenceImageCount * $pricing->tokensPerReferenceImage;
        $outputTokens = $pricing->outputTokens[$quality->value][$size->value];
        $reasoningTokens = $pricing->reasoningTokens[$quality->value];

        $minProviderCost = $this->providerCost($pricing->rates, $minTextTokens, $imageTokens, $outputTokens, $reasoningTokens['min']);
        $maxProviderCost = $this->providerCost($pricing->rates, $maxTextTokens, $imageTokens, $outputTokens, $reasoningTokens['max']);

        return new PriceEstimate(
            minProviderCostNanodollars: $minProviderCost,
            maxProviderCostNanodollars: $maxProviderCost,
            minChargeNanodollars: $this->charge($minProviderCost, $markupPercent),
            maxChargeNanodollars: $this->charge($maxProviderCost, $markupPercent),
        );
    }

    /**
     * What Pixelforge charges for the given token usage at the given rates.
     */
    public function providerCost(
        TokenRates $rates,
        int $inputTextTokens,
        int $inputImageTokens,
        int $outputTokens,
        int $reasoningTokens,
    ): int {
        return $inputTextTokens * $this->nanodollarsPerToken($rates->inputTextTokens)
            + $inputImageTokens * $this->nanodollarsPerToken($rates->inputImageTokens)
            + $outputTokens * $this->nanodollarsPerToken($rates->outputTokens)
            + $reasoningTokens * $this->nanodollarsPerToken($rates->reasoningTokens);
    }

    /**
     * What the user pays: the provider cost plus the markup. At the published
     * rates this is always a whole number of nanodollars; if it ever were
     * not, the fraction is rounded up to the next nanodollar.
     */
    public function charge(int $providerCostNanodollars, int $markupPercent): int
    {
        return $this->divideRoundingUp($providerCostNanodollars * (100 + $markupPercent), 100);
    }

    /**
     * Convert a rate in USD per 1M tokens (e.g. "12.50") to nanodollars per token (12,500).
     * $1 per 1M tokens is 10^9 / 10^6 = 1,000 nanodollars per token, so the rate
     * scaled by 10^3 is exactly the nanodollars per token.
     */
    private function nanodollarsPerToken(string $ratePerMillionTokens): int
    {
        return $this->parseFixedPoint($ratePerMillionTokens, decimals: 3);
    }

    /**
     * Parse a non-negative decimal string into an integer scaled by 10^$decimals,
     * without floats: parseFixedPoint("12.50", 3) is 12,500.
     *
     * @throws InvalidArgumentException when the value has more decimals than can be represented exactly.
     */
    private function parseFixedPoint(string $value, int $decimals): int
    {
        if (preg_match('/^(\d+)(?:\.(\d+))?$/', $value, $matches) !== 1) {
            throw new InvalidArgumentException("[{$value}] is not a decimal number.");
        }

        $fraction = rtrim($matches[2] ?? '', '0');

        if (strlen($fraction) > $decimals) {
            throw new InvalidArgumentException("[{$value}] has more than {$decimals} decimals and cannot be priced exactly.");
        }

        return (int) $matches[1] * 10 ** $decimals + (int) str_pad($fraction, $decimals, '0');
    }

    private function divideRoundingUp(int $dividend, int $divisor): int
    {
        return intdiv($dividend + $divisor - 1, $divisor);
    }
}
