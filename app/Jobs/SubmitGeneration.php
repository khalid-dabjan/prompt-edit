<?php

namespace App\Jobs;

use App\Actions\ApplyGenerationSnapshot;
use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Services\Billing\CreditLedger;
use App\Services\Pixelforge\ErrorCode;
use App\Services\Pixelforge\Exceptions\PixelforgeApiException;
use App\Services\Pixelforge\Exceptions\PixelforgeConnectionException;
use App\Services\Pixelforge\PixelforgeClient;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Sends a pending generation to Pixelforge. Every attempt reuses the
 * generation's idempotency key, so retrying after a timeout or overload can
 * never create (or bill) a second generation.
 *
 * Transient refusals (rate limit, concurrency limit, overload, network) put
 * the job back on the queue for as long as Pixelforge asks. That is how a
 * 4th concurrent generation waits for a free slot instead of failing.
 */
class SubmitGeneration implements ShouldQueue
{
    use Queueable;

    /**
     * Error code we record when submission gave up; Pixelforge never sends it.
     */
    public const string SUBMISSION_FAILED = 'submission_failed';

    public int $timeout = 30;

    /**
     * Pixelforge errors that mean our request or configuration is wrong,
     * rather than anything the user did, so an operator should hear about them.
     *
     * @var list<ErrorCode>
     */
    private const array UNEXPECTED_ERRORS = [
        ErrorCode::InvalidApiKey,
        ErrorCode::ValidationFailed,
        ErrorCode::IdempotencyKeyReused,
    ];

    public function __construct(public Generation $generation) {}

    /**
     * Keep waiting for a slot or for Pixelforge to recover for up to an hour.
     */
    public function retryUntil(): DateTimeInterface
    {
        return now()->addHour();
    }

    public function handle(PixelforgeClient $pixelforge, ApplyGenerationSnapshot $applySnapshot): void
    {
        if ($this->generation->status !== GenerationStatus::Pending) {
            return;
        }

        try {
            $accepted = $pixelforge->createGeneration(
                prompt: $this->generation->prompt,
                size: $this->generation->size,
                quality: $this->generation->quality,
                idempotencyKey: $this->generation->idempotency_key,
                referenceImages: $this->generation->reference_images,
                metadata: ['generation_id' => (string) $this->generation->id],
            );
        } catch (PixelforgeConnectionException) {
            $this->release($this->backoffSeconds());

            return;
        } catch (PixelforgeApiException $exception) {
            if ($exception->isRetryable()) {
                $this->release($exception->retryAfter() ?? $this->backoffSeconds());

                return;
            }

            if (in_array($exception->errorCode(), self::UNEXPECTED_ERRORS, true) || $exception->errorCode() === null) {
                report($exception);
            }

            $this->markFailed($exception->rawCode ?? self::SUBMISSION_FAILED, $exception->getMessage());

            return;
        }

        // An idempotent replay returns the generation's current state, which may
        // already be processing or finished, so it goes through the same path
        // as webhooks and polling.
        $applySnapshot->handle($this->generation, $accepted);
    }

    /**
     * Retries ran out (or the job crashed). A lost response means Pixelforge
     * may have accepted it anyway; if it reports on it later, the user still
     * isn't charged (see ApplyGenerationSnapshot).
     */
    public function failed(?Throwable $exception): void
    {
        $this->markFailed(
            self::SUBMISSION_FAILED,
            'We could not confirm this generation started with Pixelforge. You will not be charged for it.',
        );
    }

    /**
     * 5s, 10s, 20s, 40s, then every 60s, for failures without a Retry-After.
     */
    private function backoffSeconds(): int
    {
        return min(5 * 2 ** ($this->attempts() - 1), 60);
    }

    /**
     * Only a generation that is still pending can fail here, so a webhook
     * that already moved it forward is never overwritten. Its credit hold is
     * given back in the same transaction.
     */
    private function markFailed(string $errorCode, string $errorMessage): void
    {
        DB::transaction(function () use ($errorCode, $errorMessage): void {
            $failed = Generation::query()
                ->whereKey($this->generation->id)
                ->where('status', GenerationStatus::Pending)
                ->update([
                    'status' => GenerationStatus::Failed,
                    'error_code' => $errorCode,
                    'error_message' => $errorMessage,
                    'provider_cost_nanodollars' => 0,
                    'charge_nanodollars' => 0,
                    'completed_at' => now(),
                ]);

            if ($failed === 1) {
                app(CreditLedger::class)->settle($this->generation->fresh() ?? $this->generation);
            }
        });
    }
}
