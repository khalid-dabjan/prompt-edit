<?php

namespace App\Services\Pixelforge\Data;

use App\Enums\GenerationStatus;
use App\Enums\ImageQuality;
use App\Enums\ImageSize;
use Carbon\CarbonImmutable;

/**
 * A snapshot of a Pixelforge generation object, as returned by the API or
 * carried inside a webhook event.
 *
 * @phpstan-type GenerationPayload array{
 *     id: string,
 *     status: string,
 *     prompt: string,
 *     size: string,
 *     quality: string,
 *     reference_images: list<string>,
 *     metadata: array<string, string>,
 *     image_url: string|null,
 *     image_url_expires_at: string|null,
 *     usage: array{input_text_tokens: int, input_image_tokens: int, output_tokens: int, reasoning_tokens: int, total_tokens: int}|null,
 *     rates: array{input_text_tokens: string, input_image_tokens: string, output_tokens: string, reasoning_tokens: string},
 *     error: array{code: string, message: string}|null,
 *     created_at: string,
 *     started_at: string|null,
 *     completed_at: string|null,
 * }
 */
final readonly class Generation
{
    /**
     * @param  list<string>  $referenceImages
     * @param  array<string, string>  $metadata
     */
    public function __construct(
        public string $id,
        public GenerationStatus $status,
        public string $prompt,
        public ImageSize $size,
        public ImageQuality $quality,
        public array $referenceImages,
        public array $metadata,
        public ?string $imageUrl,
        public ?CarbonImmutable $imageUrlExpiresAt,
        public ?TokenUsage $usage,
        public TokenRates $rates,
        public ?GenerationError $error,
        public CarbonImmutable $createdAt,
        public ?CarbonImmutable $startedAt,
        public ?CarbonImmutable $completedAt,
    ) {}

    /**
     * @param  GenerationPayload  $generation
     */
    public static function fromArray(array $generation): self
    {
        return new self(
            id: $generation['id'],
            status: GenerationStatus::from($generation['status']),
            prompt: $generation['prompt'],
            size: ImageSize::from($generation['size']),
            quality: ImageQuality::from($generation['quality']),
            referenceImages: $generation['reference_images'],
            metadata: $generation['metadata'],
            imageUrl: $generation['image_url'],
            imageUrlExpiresAt: self::parseTimestamp($generation['image_url_expires_at']),
            usage: $generation['usage'] === null ? null : TokenUsage::fromArray($generation['usage']),
            rates: TokenRates::fromArray($generation['rates']),
            error: $generation['error'] === null ? null : GenerationError::fromArray($generation['error']),
            createdAt: CarbonImmutable::parse($generation['created_at']),
            startedAt: self::parseTimestamp($generation['started_at']),
            completedAt: self::parseTimestamp($generation['completed_at']),
        );
    }

    private static function parseTimestamp(?string $timestamp): ?CarbonImmutable
    {
        return $timestamp === null ? null : CarbonImmutable::parse($timestamp);
    }
}
