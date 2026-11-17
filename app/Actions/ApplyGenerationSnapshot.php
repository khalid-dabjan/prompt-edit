<?php

namespace App\Actions;

use App\Enums\GenerationStatus;
use App\Jobs\StoreGenerationImage;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Services\Billing\PriceCalculator;
use App\Services\Pixelforge\Data\Generation as PixelforgeGeneration;
use Illuminate\Support\Facades\DB;
use UnexpectedValueException;

/**
 * The single place a generation's status and money change. Every source of
 * Pixelforge state (the create response, webhooks, polling) goes through it,
 * so duplicates and out-of-order arrivals are handled the same way:
 *
 *  - the row is locked, so concurrent updates apply one after another;
 *  - status only moves forward; an older or repeated status is ignored;
 *  - succeeded and failed are final;
 *  - cost and charge are written only on the move into a final status,
 *    which can happen once, so a generation is never charged twice.
 *
 * One exception to "final": `submission_failed` is our own guess that
 * Pixelforge never accepted the request. If Pixelforge later reports on it,
 * its answer replaces ours, but the user keeps the "not charged" we promised.
 */
class ApplyGenerationSnapshot
{
    public function __construct(private readonly PriceCalculator $priceCalculator) {}

    /**
     * Find our generation for a Pixelforge snapshot: by its Pixelforge id, or,
     * when the snapshot arrives before we stored that id, by the
     * `generation_id` we sent in its metadata.
     */
    public function findGenerationFor(PixelforgeGeneration $snapshot): ?Generation
    {
        $generation = Generation::query()->where('pixelforge_id', $snapshot->id)->first();

        if ($generation !== null) {
            return $generation;
        }

        $generationId = $snapshot->metadata['generation_id'] ?? null;

        if ($generationId === null || ! ctype_digit($generationId)) {
            return null;
        }

        return Generation::query()
            ->whereKey((int) $generationId)
            ->whereNull('pixelforge_id')
            ->first();
    }

    public function handle(Generation $generation, PixelforgeGeneration $snapshot): Generation
    {
        return DB::transaction(function () use ($generation, $snapshot): Generation {
            $generation = Generation::query()->lockForUpdate()->findOrFail($generation->id);

            if ($generation->pixelforge_id !== null && $generation->pixelforge_id !== $snapshot->id) {
                return $generation;
            }

            $isOurOwnGuess = $generation->status === GenerationStatus::Failed
                && $generation->error_code === SubmitGeneration::SUBMISSION_FAILED;

            if (! $isOurOwnGuess && $snapshot->status->stage() <= $generation->status->stage()) {
                return $generation;
            }

            if ($isOurOwnGuess) {
                // Pixelforge did accept it after all. Its state replaces our guess
                // from here on, but we told the user they won't be charged.
                $generation->fill([
                    'charge_waived' => true,
                    'provider_cost_nanodollars' => null,
                    'charge_nanodollars' => null,
                    'error_code' => null,
                    'error_message' => null,
                ]);
            }

            $generation->fill([
                'pixelforge_id' => $snapshot->id,
                'status' => $snapshot->status,
                'rates' => $snapshot->rates->toArray(),
                'started_at' => $snapshot->startedAt,
                'completed_at' => $snapshot->completedAt,
            ]);

            match ($snapshot->status) {
                GenerationStatus::Succeeded => $this->recordSuccess($generation, $snapshot),
                GenerationStatus::Failed => $this->recordFailure($generation, $snapshot),
                default => null,
            };

            $generation->save();

            if ($generation->status === GenerationStatus::Succeeded) {
                // Pixelforge's image URL expires after 24 hours; keep our own copy.
                // Dispatched after commit, so the job never sees the pre-success row.
                StoreGenerationImage::dispatch($generation)->afterCommit();
            }

            return $generation;
        });
    }

    /**
     * Pixelforge's cost comes from the final token usage at the rates fixed on
     * this generation; the user's charge adds the markup they were quoted.
     */
    private function recordSuccess(Generation $generation, PixelforgeGeneration $snapshot): void
    {
        $usage = $snapshot->usage ?? throw new UnexpectedValueException("Succeeded generation [{$snapshot->id}] has no usage.");

        $providerCost = $this->priceCalculator->providerCost(
            rates: $snapshot->rates,
            inputTextTokens: $usage->inputTextTokens,
            inputImageTokens: $usage->inputImageTokens,
            outputTokens: $usage->outputTokens,
            reasoningTokens: $usage->reasoningTokens,
        );

        $generation->fill([
            'input_text_tokens' => $usage->inputTextTokens,
            'input_image_tokens' => $usage->inputImageTokens,
            'output_tokens' => $usage->outputTokens,
            'reasoning_tokens' => $usage->reasoningTokens,
            'provider_cost_nanodollars' => $providerCost,
            'charge_nanodollars' => $generation->charge_waived ? 0 : $this->priceCalculator->charge($providerCost, $generation->markup_percent),
            'image_url' => $snapshot->imageUrl,
            'image_url_expires_at' => $snapshot->imageUrlExpiresAt,
            'error_code' => null,
            'error_message' => null,
        ]);
    }

    /**
     * Failed generations are never billed by Pixelforge, so they cost nothing.
     */
    private function recordFailure(Generation $generation, PixelforgeGeneration $snapshot): void
    {
        $generation->fill([
            'provider_cost_nanodollars' => 0,
            'charge_nanodollars' => 0,
            'error_code' => $snapshot->error?->code,
            'error_message' => $snapshot->error?->message,
        ]);
    }
}
