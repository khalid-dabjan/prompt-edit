<?php

namespace App\Jobs;

use App\Models\Generation;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Keeps our own copy of a succeeded generation's image, because Pixelforge's
 * signed URL stops working 24 hours after completion. If every attempt
 * fails, the app falls back to Pixelforge's URL until it expires.
 */
class StoreGenerationImage implements ShouldQueue
{
    use Queueable;

    private const string PNG_SIGNATURE = "\x89PNG\r\n\x1a\n";

    public int $tries = 5;

    public int $timeout = 60;

    /**
     * @var list<int>
     */
    public array $backoff = [10, 60, 300, 900];

    public function __construct(public Generation $generation) {}

    /**
     * @throws ConnectionException|RequestException when the download fails; the job is retried.
     */
    public function handle(): void
    {
        if ($this->generation->image_path !== null || $this->generation->image_url === null) {
            return;
        }

        $image = Http::connectTimeout(5)
            ->timeout(30)
            ->get($this->generation->image_url)
            ->throw()
            ->body();

        if (! str_starts_with($image, self::PNG_SIGNATURE)) {
            $this->fail(new RuntimeException("Pixelforge image for generation [{$this->generation->id}] is not a PNG."));

            return;
        }

        $path = "generations/{$this->generation->id}.png";
        $disk = Storage::disk(config()->string('services.pixelforge.image_disk'));

        $disk->put($path, $image);

        $this->generation->update(['image_path' => $path]);
    }
}
