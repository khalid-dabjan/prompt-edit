<?php

namespace App\Services\Pixelforge\Data;

/**
 * Final token counts of a succeeded generation.
 */
final readonly class TokenUsage
{
    public function __construct(
        public int $inputTextTokens,
        public int $inputImageTokens,
        public int $outputTokens,
        public int $reasoningTokens,
        public int $totalTokens,
    ) {}

    /**
     * @param  array{input_text_tokens: int, input_image_tokens: int, output_tokens: int, reasoning_tokens: int, total_tokens: int}  $usage
     */
    public static function fromArray(array $usage): self
    {
        return new self(
            inputTextTokens: $usage['input_text_tokens'],
            inputImageTokens: $usage['input_image_tokens'],
            outputTokens: $usage['output_tokens'],
            reasoningTokens: $usage['reasoning_tokens'],
            totalTokens: $usage['total_tokens'],
        );
    }
}
