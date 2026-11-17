<?php

namespace App\Services\Pixelforge;

use App\Services\Pixelforge\Data\Pricing;
use App\Services\Pixelforge\Exceptions\PixelforgeException;
use Illuminate\Support\Facades\Cache;

/**
 * Pixelforge's published pricing, cached so live price estimates don't spend
 * the account's 60 requests per minute. Fresh for an hour, then served stale
 * for up to a day while it refreshes after the response.
 */
class PublishedPricing
{
    private const string CACHE_KEY = 'pixelforge.pricing';

    public function __construct(private readonly PixelforgeClient $client) {}

    /**
     * @throws PixelforgeException when nothing is cached and Pixelforge can't be reached.
     */
    public function get(): Pricing
    {
        $pricing = Cache::flexible(
            self::CACHE_KEY,
            [3600, 86400],
            fn (): array => $this->client->getPricing()->toArray(),
        );

        return Pricing::fromArray($pricing);
    }
}
