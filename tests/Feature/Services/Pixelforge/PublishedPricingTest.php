<?php

use App\Services\Pixelforge\PublishedPricing;
use Illuminate\Support\Facades\Http;

it('fetches the published pricing once and then serves it from the cache', function () {
    Http::preventStrayRequests();
    Http::fake([
        'pixelforge.test/v1/pricing' => Http::response(pixelforgeFixture('pricing')),
    ]);

    $first = app(PublishedPricing::class)->get();
    $second = app(PublishedPricing::class)->get();

    Http::assertSentCount(1);
    expect($second)->toEqual($first)
        ->and($second->textTokenVariance)->toBe('0.15')
        ->and($second->outputTokens['high']['1536x1024'])->toBe(6450);
});
