<?php

namespace App\Services\Pixelforge\Data;

/**
 * One page of `GET /v1/generations`, newest first.
 *
 * @phpstan-import-type GenerationPayload from Generation
 */
final readonly class GenerationPage
{
    /**
     * @param  list<Generation>  $generations
     */
    public function __construct(
        public array $generations,
        public bool $hasMore,
        public ?string $nextCursor,
    ) {}

    /**
     * @param  array{data: list<GenerationPayload>, has_more: bool, next_cursor: string|null}  $page
     */
    public static function fromArray(array $page): self
    {
        return new self(
            generations: array_map(Generation::fromArray(...), $page['data']),
            hasMore: $page['has_more'],
            nextCursor: $page['next_cursor'],
        );
    }
}
