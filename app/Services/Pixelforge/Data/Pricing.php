<?php

namespace App\Services\Pixelforge\Data;

/**
 * Published rates and token rules from `GET /v1/pricing`.
 *
 * @phpstan-type PricingPayload array{
 *     rates: array{input_text_tokens: string, input_image_tokens: string, output_tokens: string, reasoning_tokens: string},
 *     tokens: array{
 *         characters_per_text_token: int,
 *         text_token_variance: float|int|string,
 *         per_reference_image: int,
 *         output: array<string, array<string, int>>,
 *         reasoning: array<string, array{min: int, max: int}>,
 *     },
 * }
 */
final readonly class Pricing
{
    /**
     * @param  string  $textTokenVariance  Fraction the real text token count may differ from the estimate (e.g. "0.15").
     * @param  array<string, array<string, int>>  $outputTokens  Exact output tokens, keyed by quality then size.
     * @param  array<string, array{min: int, max: int}>  $reasoningTokens  Reasoning token range, keyed by quality.
     */
    public function __construct(
        public TokenRates $rates,
        public int $charactersPerTextToken,
        public string $textTokenVariance,
        public int $tokensPerReferenceImage,
        public array $outputTokens,
        public array $reasoningTokens,
    ) {}

    /**
     * @phpstan-param PricingPayload $pricing
     */
    public static function fromArray(array $pricing): self
    {
        $tokens = $pricing['tokens'];

        return new self(
            rates: TokenRates::fromArray($pricing['rates']),
            charactersPerTextToken: $tokens['characters_per_text_token'],
            textTokenVariance: (string) $tokens['text_token_variance'],
            tokensPerReferenceImage: $tokens['per_reference_image'],
            outputTokens: $tokens['output'],
            reasoningTokens: $tokens['reasoning'],
        );
    }

    /**
     * The same shape Pixelforge sends, so it can be cached as plain data.
     *
     * @return PricingPayload
     */
    public function toArray(): array
    {
        return [
            'rates' => $this->rates->toArray(),
            'tokens' => [
                'characters_per_text_token' => $this->charactersPerTextToken,
                'text_token_variance' => $this->textTokenVariance,
                'per_reference_image' => $this->tokensPerReferenceImage,
                'output' => $this->outputTokens,
                'reasoning' => $this->reasoningTokens,
            ],
        ];
    }
}
