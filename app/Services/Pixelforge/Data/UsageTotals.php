<?php

namespace App\Services\Pixelforge\Data;

use UnexpectedValueException;

/**
 * What Pixelforge billed over a period (one UTC day, or a whole report).
 */
final readonly class UsageTotals
{
    /**
     * @param  string  $costUsd  Exact, unrounded US dollar amount with nine decimals (e.g. "0.169080000").
     */
    public function __construct(
        public int $generations,
        public int $inputTextTokens,
        public int $inputImageTokens,
        public int $outputTokens,
        public int $reasoningTokens,
        public string $costUsd,
    ) {}

    /**
     * The cost as integer nanodollars, parsed exactly from the 9-decimal string ("0.169080000" → 169,080,000).
     */
    public function costNanodollars(): int
    {
        if (preg_match('/^(\d+)\.(\d{9})$/', $this->costUsd, $matches) !== 1) {
            throw new UnexpectedValueException("Unexpected Pixelforge cost [{$this->costUsd}].");
        }

        return (int) $matches[1] * 1_000_000_000 + (int) $matches[2];
    }

    /**
     * @param  array{generations: int, input_text_tokens: int, input_image_tokens: int, output_tokens: int, reasoning_tokens: int, cost_usd: string}  $totals
     */
    public static function fromArray(array $totals): self
    {
        return new self(
            generations: $totals['generations'],
            inputTextTokens: $totals['input_text_tokens'],
            inputImageTokens: $totals['input_image_tokens'],
            outputTokens: $totals['output_tokens'],
            reasoningTokens: $totals['reasoning_tokens'],
            costUsd: $totals['cost_usd'],
        );
    }
}
