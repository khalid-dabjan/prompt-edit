<?php

namespace App\Console\Commands;

use App\Actions\ApplyGenerationSnapshot;
use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Services\Pixelforge\Exceptions\PixelforgeException;
use App\Services\Pixelforge\PixelforgeClient;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Webhook delivery isn't guaranteed, so generations that have gone quiet are
 * fetched from Pixelforge and applied like any other update. Duplicates and
 * stale states are harmless: ApplyGenerationSnapshot ignores them.
 */
#[Signature('pixelforge:poll-generations {--limit=20 : Most generations to check in one run}')]
#[Description('Fetch unfinished generations that have had no update for a minute from Pixelforge')]
class PollUnfinishedGenerations extends Command
{
    /**
     * A generation is only polled after this long without an update (from a
     * webhook or a previous poll), so a healthy webhook flow costs no requests.
     */
    private const int QUIET_SECONDS = 60;

    public function handle(PixelforgeClient $pixelforge, ApplyGenerationSnapshot $applySnapshot): int
    {
        $quietSince = now()->subSeconds(self::QUIET_SECONDS);

        $generations = Generation::query()
            ->whereIn('status', [GenerationStatus::Queued, GenerationStatus::Processing])
            ->whereNotNull('pixelforge_id')
            ->where('updated_at', '<=', $quietSince)
            ->where(fn ($query) => $query->whereNull('polled_at')->orWhere('polled_at', '<=', $quietSince))
            ->orderBy('polled_at')
            ->orderBy('id')
            ->limit((int) $this->option('limit'))
            ->get();

        foreach ($generations as $generation) {
            try {
                $snapshot = $pixelforge->getGeneration((string) $generation->pixelforge_id);
            } catch (PixelforgeException $exception) {
                if ($exception->isRetryable()) {
                    // Rate limited, overloaded or unreachable: more requests now would only
                    // eat into the limit submissions need. The next run picks up from here.
                    $this->warn("Pixelforge is unavailable ({$exception->getMessage()}); stopping this run.");

                    return self::SUCCESS;
                }

                report($exception);
                $this->markPolled($generation);

                continue;
            }

            $applySnapshot->handle($generation, $snapshot);
            $this->markPolled($generation);
        }

        $this->info("Checked {$generations->count()} unfinished generation(s).");

        return self::SUCCESS;
    }

    /**
     * Record the check without touching updated_at, which means "the generation changed".
     */
    private function markPolled(Generation $generation): void
    {
        Generation::withoutTimestamps(
            fn () => Generation::query()->whereKey($generation->id)->update(['polled_at' => now()]),
        );
    }
}
