<?php

namespace App\Http\Resources;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A generation as its owner sees it. Money is in nanodollars; Pixelforge's
 * cost to us and internal error details are deliberately left out.
 *
 * @mixin Generation
 */
class GenerationResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'prompt' => $this->prompt,
            'size' => $this->size,
            'quality' => $this->quality,
            'reference_image_count' => count($this->reference_images),
            'estimated_min_charge_nanodollars' => $this->estimated_min_charge_nanodollars,
            'estimated_max_charge_nanodollars' => $this->estimated_max_charge_nanodollars,
            'charge_nanodollars' => $this->charge_nanodollars,
            'charge_waived' => $this->charge_waived,
            'failure_message' => $this->failureMessage(),
            'image_url' => $this->hasViewableImage() ? route('generations.image', $this->resource) : null,
            'image_expired' => $this->status === GenerationStatus::Succeeded && ! $this->hasViewableImage(),
            'created_at' => $this->created_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
        ];
    }
}
