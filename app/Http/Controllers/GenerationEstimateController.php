<?php

namespace App\Http\Controllers;

use App\Enums\ImageQuality;
use App\Enums\ImageSize;
use App\Http\Requests\EstimateGenerationRequest;
use App\Services\Billing\PriceCalculator;
use App\Services\Pixelforge\Exceptions\PixelforgeException;
use App\Services\Pixelforge\PublishedPricing;
use Illuminate\Http\JsonResponse;

/**
 * The live price range shown on the Generate page. The same calculation
 * quotes the price stored when the user submits, so the two always agree.
 */
class GenerationEstimateController extends Controller
{
    public function __invoke(
        EstimateGenerationRequest $request,
        PublishedPricing $publishedPricing,
        PriceCalculator $priceCalculator,
    ): JsonResponse {
        try {
            $pricing = $publishedPricing->get();
        } catch (PixelforgeException $exception) {
            report($exception);

            return response()->json(['message' => __('Prices are unavailable right now.')], 503);
        }

        $estimate = $priceCalculator->estimate(
            pricing: $pricing,
            prompt: $request->string('prompt')->toString(),
            size: $request->enum('size', ImageSize::class),
            quality: $request->enum('quality', ImageQuality::class),
            referenceImageCount: $request->integer('reference_image_count'),
            markupPercent: config()->integer('billing.markup_percent'),
        );

        return response()->json([
            'min_charge_nanodollars' => $estimate->minChargeNanodollars,
            'max_charge_nanodollars' => $estimate->maxChargeNanodollars,
        ]);
    }
}
