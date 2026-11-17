<?php

namespace App\Actions;

use App\Enums\GenerationStatus;
use App\Enums\ImageQuality;
use App\Enums\ImageSize;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Models\User;
use App\Services\Billing\CreditLedger;
use App\Services\Billing\InsufficientCreditsException;
use App\Services\Billing\PriceCalculator;
use App\Services\Pixelforge\Exceptions\PixelforgeException;
use App\Services\Pixelforge\PublishedPricing;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Records a user's generation with the price range they were quoted, reserves
 * the most it can cost from their credits, then queues it for submission.
 */
class CreateGeneration
{
    public function __construct(
        private readonly PublishedPricing $publishedPricing,
        private readonly PriceCalculator $priceCalculator,
        private readonly CreditLedger $creditLedger,
    ) {}

    /**
     * @param  list<string>  $referenceImages
     *
     * @throws PixelforgeException when the published pricing can't be loaded to quote a price.
     * @throws InsufficientCreditsException when the user can't afford the highest possible price; nothing is created.
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

        return DB::transaction(function () use ($user, $prompt, $size, $quality, $referenceImages, $markupPercent, $estimate): Generation {
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

            $this->creditLedger->hold($generation);

            SubmitGeneration::dispatch($generation)->afterCommit();

            return $generation;
        });
    }
}
