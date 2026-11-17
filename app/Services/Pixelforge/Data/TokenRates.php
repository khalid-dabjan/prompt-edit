<?php

namespace App\Services\Pixelforge\Data;

/**
 * Prices in US dollars per 1M tokens, kept as the exact decimal strings
 * Pixelforge sends (e.g. "12.50") so no precision is lost to floats.
 */
final readonly class TokenRates
{
    public function __construct(
        public string $inputTextTokens,
        public string $inputImageTokens,
        public string $outputTokens,
        public string $reasoningTokens,
    ) {}

    /**
     * @param  array{input_text_tokens: string, input_image_tokens: string, output_tokens: string, reasoning_tokens: string}  $rates
     */
    public static function fromArray(array $rates): self
    {
        return new self(
            inputTextTokens: $rates['input_text_tokens'],
            inputImageTokens: $rates['input_image_tokens'],
            outputTokens: $rates['output_tokens'],
            reasoningTokens: $rates['reasoning_tokens'],
        );
    }

    /**
     * @return array{input_text_tokens: string, input_image_tokens: string, output_tokens: string, reasoning_tokens: string}
     */
    public function toArray(): array
    {
        return [
            'input_text_tokens' => $this->inputTextTokens,
            'input_image_tokens' => $this->inputImageTokens,
            'output_tokens' => $this->outputTokens,
            'reasoning_tokens' => $this->reasoningTokens,
        ];
    }
}
