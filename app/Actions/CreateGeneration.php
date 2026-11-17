<?php

namespace App\Actions;

use App\Enums\GenerationStatus;
use App\Enums\ImageQuality;
use App\Enums\ImageSize;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Models\User;
use App\Services\Billing\PriceCalculator;
use App\Services\Pixelforge\Exceptions\PixelforgeException;
use App\Services\Pixelforge\PublishedPricing;
use Illuminate\Support\Str;

/**
 * Records a user's generation with the price range they were quoted, then
 * queues it for submission to Pixelforge.
 */
class CreateGeneration
{
    public function __construct(
        private readonly PublishedPricing $publishedPricing,
        private readonly PriceCalculator $priceCalculator,
    ) {}

    /**
     * @param  list<string>  $referenceImages
     *
     * @throws PixelforgeException when the published pricing can't be loaded to quote a price.
     */
    public function handle(User $user, string $prompt, ImageSize $size, ImageQuality $quality, array $referenceImages = []): Generation
    {
        $markupPercent = config()->integer('billing.markup_percent');

        $estimate = $this->priceCalculator->estimate(
            pricing: $this->publishedPricing->get(),
            prompt: $prompt,
            size: $size,
            quality: $quality,
            referenceImageCount: count($referenceImages),
            markupPercent: $markupPercent,
        );

        $generation = $user->generations()->create([
            'idempotency_key' => (string) Str::uuid(),
            'status' => GenerationStatus::Pending,
            'prompt' => $prompt,
            'size' => $size,
            'quality' => $quality,
            'reference_images' => $referenceImages,
            'markup_percent' => $markupPercent,
            'estimated_min_charge_nanodollars' => $estimate->minChargeNanodollars,
            'estimated_max_charge_nanodollars' => $estimate->maxChargeNanodollars,
        ]);

        SubmitGeneration::dispatch($generation);

        return $generation;
    }
}
