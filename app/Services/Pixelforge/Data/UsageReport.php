<?php

namespace App\Services\Pixelforge\Data;

use Carbon\CarbonImmutable;

/**
 * `GET /v1/usage`: what Pixelforge billed this API key, per UTC day.
 * A generation counts on the UTC day it completed.
 *
 * @phpstan-type UsageTotalsPayload array{generations: int, input_text_tokens: int, input_image_tokens: int, output_tokens: int, reasoning_tokens: int, cost_usd: string}
 * @phpstan-type UsageDayPayload array{date: string, generations: int, input_text_tokens: int, input_image_tokens: int, output_tokens: int, reasoning_tokens: int, cost_usd: string}
 */
final readonly class UsageReport
{
    /**
     * @param  array<string, UsageTotals>  $days  Keyed by UTC date (Y-m-d). Days without usage are included with zeros.
     */
    public function __construct(
        public CarbonImmutable $startDate,
        public CarbonImmutable $endDate,
        public array $days,
        public UsageTotals $total,
    ) {}

    /**
     * @param  array{start_date: string, end_date: string, data: list<UsageDayPayload>, total: UsageTotalsPayload}  $report
     */
    public static function fromArray(array $report): self
    {
        $days = [];

        foreach ($report['data'] as $day) {
            $days[$day['date']] = UsageTotals::fromArray($day);
        }

        return new self(
            startDate: CarbonImmutable::parse($report['start_date'], 'UTC'),
            endDate: CarbonImmutable::parse($report['end_date'], 'UTC'),
            days: $days,
            total: UsageTotals::fromArray($report['total']),
        );
    }
}
