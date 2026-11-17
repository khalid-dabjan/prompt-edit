<?php

namespace App\Models;

use App\Enums\GenerationStatus;
use App\Enums\ImageQuality;
use App\Enums\ImageSize;
use Database\Factories\GenerationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An image generation a user requested. Money is in nanodollars (1 USD = 1,000,000,000).
 *
 * @property int $id
 * @property int|null $user_id Null once the user deleted their account; the billing record is kept.
 * @property string $idempotency_key Sent as Pixelforge's Idempotency-Key, so a retried submit never creates a second generation.
 * @property string|null $pixelforge_id Null until Pixelforge accepts the generation.
 * @property GenerationStatus $status
 * @property string $prompt
 * @property ImageSize $size
 * @property ImageQuality $quality
 * @property list<string> $reference_images
 * @property int $markup_percent Our markup on Pixelforge's cost, fixed when the user submitted.
 * @property int $estimated_min_charge_nanodollars Lowest price the user can be charged, shown before submitting.
 * @property int $estimated_max_charge_nanodollars Highest price the user can be charged, shown before submitting.
 * @property array{input_text_tokens: string, input_image_tokens: string, output_tokens: string, reasoning_tokens: string}|null $rates USD per 1M tokens, fixed by Pixelforge when it accepted the generation.
 * @property int|null $input_text_tokens
 * @property int|null $input_image_tokens
 * @property int|null $output_tokens
 * @property int|null $reasoning_tokens
 * @property int|null $provider_cost_nanodollars What Pixelforge charged us. Set once the generation is final.
 * @property int|null $charge_nanodollars What we charged the user. Set once the generation is final.
 * @property string|null $error_code
 * @property string|null $error_message
 * @property string|null $image_url
 * @property Carbon|null $image_url_expires_at
 * @property Carbon|null $started_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'user_id',
    'idempotency_key',
    'pixelforge_id',
    'status',
    'prompt',
    'size',
    'quality',
    'reference_images',
    'markup_percent',
    'estimated_min_charge_nanodollars',
    'estimated_max_charge_nanodollars',
    'rates',
    'input_text_tokens',
    'input_image_tokens',
    'output_tokens',
    'reasoning_tokens',
    'provider_cost_nanodollars',
    'charge_nanodollars',
    'error_code',
    'error_message',
    'image_url',
    'image_url_expires_at',
    'started_at',
    'completed_at',
])]
class Generation extends Model
{
    /** @use HasFactory<GenerationFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => GenerationStatus::class,
            'size' => ImageSize::class,
            'quality' => ImageQuality::class,
            'reference_images' => 'array',
            'rates' => 'array',
            'markup_percent' => 'integer',
            'estimated_min_charge_nanodollars' => 'integer',
            'estimated_max_charge_nanodollars' => 'integer',
            'input_text_tokens' => 'integer',
            'input_image_tokens' => 'integer',
            'output_tokens' => 'integer',
            'reasoning_tokens' => 'integer',
            'provider_cost_nanodollars' => 'integer',
            'charge_nanodollars' => 'integer',
            'image_url_expires_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }
}
