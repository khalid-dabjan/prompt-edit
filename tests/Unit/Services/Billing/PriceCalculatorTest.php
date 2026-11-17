<?php

use App\Enums\ImageQuality;
use App\Enums\ImageSize;
use App\Services\Billing\PriceCalculator;
use App\Services\Pixelforge\Data\Pricing;
use App\Services\Pixelforge\Data\TokenRates;

function publishedPricing(): Pricing
{
    return Pricing::fromArray(pixelforgeFixture('pricing'));
}

function publishedRates(): TokenRates
{
    return publishedPricing()->rates;
}

it('estimates the docs worst-case example and its lowest possible price', function () {
    $estimate = (new PriceCalculator)->estimate(
        pricing: publishedPricing(),
        prompt: str_repeat('a', 400),
        size: ImageSize::Landscape,
        quality: ImageQuality::High,
        referenceImageCount: 2,
        markupPercent: 25,
    );

    // Docs: 115 text + 1,530 image + 6,450 output + 8,000 reasoning tokens = $0.373875000.
    expect($estimate->maxProviderCostNanodollars)->toBe(373_875_000)
        ->and($estimate->maxChargeNanodollars)->toBe(467_343_750)
        // 85 text + 1,530 image + 6,450 output + 1,000 reasoning tokens.
        ->and($estimate->minProviderCostNanodollars)->toBe(286_225_000)
        ->and($estimate->minChargeNanodollars)->toBe(357_781_250);
});

it('widens the text token range outward when the variance gives a fraction of a token', function () {
    // 10 characters estimate 3 text tokens; ±15% is 2.55–3.45, so 2–4 tokens.
    $estimate = (new PriceCalculator)->estimate(publishedPricing(), str_repeat('a', 10), ImageSize::Square, ImageQuality::Low, 0, 25);

    // 2 text + 280 output + 0 reasoning tokens, and 4 text + 280 output + 500 reasoning tokens.
    expect($estimate->minProviderCostNanodollars)->toBe(11_210_000)
        ->and($estimate->maxProviderCostNanodollars)->toBe(17_470_000);
});

it('counts prompt length in characters, not bytes', function () {
    // 8 two-byte characters estimate 2 text tokens (not 4): 1–3 tokens.
    $estimate = (new PriceCalculator)->estimate(publishedPricing(), str_repeat('é', 8), ImageSize::Square, ImageQuality::Low, 0, 25);

    expect($estimate->minProviderCostNanodollars)->toBe(11_205_000)
        ->and($estimate->maxProviderCostNanodollars)->toBe(17_465_000);
});

it('prices token usage at the given rates', function (int $text, int $image, int $output, int $reasoning, int $expected) {
    expect((new PriceCalculator)->providerCost(publishedRates(), $text, $image, $output, $reasoning))->toBe($expected);
})->with([
    'docs succeeded generation' => [12, 765, 6450, 3184, 305_510_000],
    'docs usage report day' => [41, 0, 3300, 2950, 169_080_000],
    'no tokens' => [0, 0, 0, 0, 0],
]);

it('adds the markup to the provider cost', function (int $providerCost, int $markupPercent, int $expected) {
    expect((new PriceCalculator)->charge($providerCost, $markupPercent))->toBe($expected);
})->with([
    'docs succeeded generation' => [305_510_000, 25, 381_887_500],
    'failed generation' => [0, 25, 0],
    'no markup' => [305_510_000, 0, 305_510_000],
    'fraction of a nanodollar rounds up' => [1, 25, 2],
]);

it('refuses a rate that cannot be priced in whole nanodollars', function () {
    $rates = new TokenRates(inputTextTokens: '5.0001', inputImageTokens: '10.00', outputTokens: '40.00', reasoningTokens: '12.50');

    (new PriceCalculator)->providerCost($rates, 1, 0, 0, 0);
})->throws(InvalidArgumentException::class);
