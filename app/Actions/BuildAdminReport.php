<?php

namespace App\Actions;

use App\Enums\GenerationStatus;
use App\Services\Pixelforge\Data\UsageReport;
use App\Services\Pixelforge\Exceptions\PixelforgeException;
use App\Services\Pixelforge\PixelforgeClient;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Credit spend (what users paid), provider cost (what Pixelforge charged us)
 * and profit, per UTC day and per user, with each day reconciled against
 * Pixelforge's own usage report. All money is in nanodollars.
 *
 * Generations are counted the way Pixelforge bills them: only succeeded ones,
 * on the UTC day they completed.
 *
 * @phpstan-type Report array{
 *     days: list<array{date: string, generations: int, credit_spend_nanodollars: int, provider_cost_nanodollars: int, profit_nanodollars: int, pixelforge_generations: int|null, pixelforge_cost_nanodollars: int|null, difference_nanodollars: int|null}>,
 *     users: list<array{user_id: int|null, name: string|null, email: string|null, generations: int, credit_spend_nanodollars: int, provider_cost_nanodollars: int, profit_nanodollars: int}>,
 *     totals: array{generations: int, credit_spend_nanodollars: int, provider_cost_nanodollars: int, profit_nanodollars: int, pixelforge_generations: int|null, pixelforge_cost_nanodollars: int|null, difference_nanodollars: int|null},
 *     pixelforge_available: bool,
 * }
 */
class BuildAdminReport
{
    public function __construct(private readonly PixelforgeClient $pixelforge) {}

    /**
     * @return Report
     */
    public function handle(CarbonImmutable $startDate, CarbonImmutable $endDate): array
    {
        $usage = $this->pixelforgeUsage($startDate, $endDate);

        $ours = $this->billedGenerations($startDate, $endDate)
            ->selectRaw('DATE(generations.completed_at) as day')
            ->groupBy('day')
            ->get()
            ->keyBy('day');

        $days = [];

        for ($day = $startDate; $day->lte($endDate); $day = $day->addDay()) {
            $date = $day->toDateString();
            $row = $ours->get($date);
            $pixelforgeDay = $usage?->days[$date] ?? null;

            $days[] = [
                'date' => $date,
                ...$this->totals($row),
                ...$this->reconcile(
                    providerCost: (int) ($row->provider_cost ?? 0),
                    pixelforgeGenerations: $pixelforgeDay?->generations,
                    pixelforgeCost: $pixelforgeDay?->costNanodollars(),
                ),
            ];
        }

        $users = array_values($this->billedGenerations($startDate, $endDate)
            ->leftJoin('users', 'users.id', '=', 'generations.user_id')
            ->addSelect('generations.user_id', 'users.name', 'users.email')
            ->groupBy('generations.user_id', 'users.name', 'users.email')
            ->orderByDesc('credit_spend')
            ->get()
            ->map(fn (object $row): array => [
                'user_id' => $row->user_id === null ? null : (int) $row->user_id,
                'name' => is_string($row->name) ? $row->name : null,
                'email' => is_string($row->email) ? $row->email : null,
                ...$this->totals($row),
            ])
            ->all());

        $totals = [
            'generations' => array_sum(array_column($days, 'generations')),
            'credit_spend_nanodollars' => array_sum(array_column($days, 'credit_spend_nanodollars')),
            'provider_cost_nanodollars' => array_sum(array_column($days, 'provider_cost_nanodollars')),
            'profit_nanodollars' => array_sum(array_column($days, 'profit_nanodollars')),
        ];

        return [
            'days' => $days,
            'users' => $users,
            'totals' => [
                ...$totals,
                ...$this->reconcile(
                    providerCost: $totals['provider_cost_nanodollars'],
                    pixelforgeGenerations: $usage?->total->generations,
                    pixelforgeCost: $usage?->total->costNanodollars(),
                ),
            ],
            'pixelforge_available' => $usage !== null,
        ];
    }

    /**
     * Succeeded generations completed within the range, with their sums selected.
     */
    private function billedGenerations(CarbonImmutable $startDate, CarbonImmutable $endDate): Builder
    {
        return DB::table('generations')
            ->where('generations.status', GenerationStatus::Succeeded->value)
            ->where('generations.completed_at', '>=', $startDate->startOfDay())
            ->where('generations.completed_at', '<', $endDate->addDay()->startOfDay())
            ->selectRaw('COUNT(*) as generations')
            ->selectRaw('COALESCE(SUM(generations.charge_nanodollars), 0) as credit_spend')
            ->selectRaw('COALESCE(SUM(generations.provider_cost_nanodollars), 0) as provider_cost');
    }

    /**
     * @return array{generations: int, credit_spend_nanodollars: int, provider_cost_nanodollars: int, profit_nanodollars: int}
     */
    private function totals(?object $row): array
    {
        $creditSpend = (int) ($row->credit_spend ?? 0);
        $providerCost = (int) ($row->provider_cost ?? 0);

        return [
            'generations' => (int) ($row->generations ?? 0),
            'credit_spend_nanodollars' => $creditSpend,
            'provider_cost_nanodollars' => $providerCost,
            'profit_nanodollars' => $creditSpend - $providerCost,
        ];
    }

    /**
     * @return array{pixelforge_generations: int|null, pixelforge_cost_nanodollars: int|null, difference_nanodollars: int|null}
     */
    private function reconcile(int $providerCost, ?int $pixelforgeGenerations, ?int $pixelforgeCost): array
    {
        return [
            'pixelforge_generations' => $pixelforgeGenerations,
            'pixelforge_cost_nanodollars' => $pixelforgeCost,
            'difference_nanodollars' => $pixelforgeCost === null ? null : $providerCost - $pixelforgeCost,
        ];
    }

    /**
     * Pixelforge's own record of what this API key was billed, or null when it
     * can't be reached; the report still shows our side.
     */
    private function pixelforgeUsage(CarbonImmutable $startDate, CarbonImmutable $endDate): ?UsageReport
    {
        try {
            return $this->pixelforge->getUsage($startDate, $endDate);
        } catch (PixelforgeException $exception) {
            report($exception);

            return null;
        }
    }
}
