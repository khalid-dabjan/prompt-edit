<?php

namespace App\Models;

use App\Enums\GenerationStatus;
use App\Enums\ImageQuality;
use App\Enums\ImageSize;
use App\Jobs\SubmitGeneration;
use App\Services\Pixelforge\ErrorCode;
use Database\Factories\GenerationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
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
 * @property bool $charge_waived True when Pixelforge billed us but we told the user they would not be charged (see ApplyGenerationSnapshot).
 * @property string|null $error_code
 * @property string|null $error_message
 * @property string|null $image_url
 * @property Carbon|null $image_url_expires_at
 * @property string|null $image_path Our stored copy of the image, on the `services.pixelforge.image_disk` disk.
 * @property Carbon|null $started_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $polled_at When we last fetched it from Pixelforge because no webhook had updated it.
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
    'charge_waived',
    'error_code',
    'error_message',
    'image_url',
    'image_url_expires_at',
    'image_path',
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
     * Generations that haven't reached a final status yet.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function unfinished(Builder $query): void
    {
        $query->whereIn('status', [GenerationStatus::Pending, GenerationStatus::Queued, GenerationStatus::Processing]);
    }

    /**
     * Whether an image can be shown: our stored copy, or Pixelforge's URL until it expires.
     */
    public function hasViewableImage(): bool
    {
        if ($this->image_path !== null) {
            return true;
        }

        return $this->image_url !== null && $this->image_url_expires_at?->isFuture() === true;
    }

    /**
     * What to tell the user about a failure, in plain words. Every failed
     * generation is free, which the UI says separately.
     */
    public function failureMessage(): ?string
    {
        if ($this->status !== GenerationStatus::Failed) {
            return null;
        }

        return match (ErrorCode::tryFrom((string) $this->error_code)) {
            ErrorCode::ContentPolicyViolation => __('This prompt was rejected by the content policy. Try rewording it.'),
            ErrorCode::DailyLimitExceeded => __("Today's generation limit has been reached. Please try again after midnight UTC."),
            ErrorCode::GenerationFailed => __("The model couldn't create an image for this prompt. Try rephrasing it."),
            ErrorCode::InternalError => __('Something went wrong while generating. Please try again.'),
            default => $this->error_code === SubmitGeneration::SUBMISSION_FAILED
                ? __("We couldn't start this generation. Please try again.")
                : __('Something went wrong on our side. Please try again.'),
        };
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
            'charge_waived' => 'boolean',
            'image_url_expires_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'polled_at' => 'datetime',
        ];
    }
}
