<?php

namespace App\Http\Controllers;

use App\Models\Generation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * One stable image URL for the UI: our stored copy when we have it,
 * otherwise Pixelforge's signed URL while it still works.
 */
class GenerationImageController extends Controller
{
    public function __invoke(Generation $generation): StreamedResponse|RedirectResponse
    {
        if ($generation->image_path !== null) {
            return Storage::disk(config()->string('services.pixelforge.image_disk'))
                ->response($generation->image_path, headers: [
                    'Cache-Control' => 'private, max-age=31536000, immutable',
                ]);
        }

        abort_unless($generation->hasViewableImage(), 404);

        return redirect()->away((string) $generation->image_url);
    }
}
