<?php

namespace App\Http\Resources;

use App\Models\CreditTransaction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CreditTransaction
 */
class CreditTransactionResource extends JsonResource
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
            'type' => $this->type,
            'amount_nanodollars' => $this->amount_nanodollars,
            'generation' => $this->generation === null ? null : [
                'id' => $this->generation->id,
                'prompt' => $this->generation->prompt,
            ],
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
