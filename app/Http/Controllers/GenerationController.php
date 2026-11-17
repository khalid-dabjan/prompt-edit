<?php

namespace App\Http\Controllers;

use App\Actions\CreateGeneration;
use App\Http\Requests\StoreGenerationRequest;
use App\Services\Pixelforge\Exceptions\PixelforgeException;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class GenerationController extends Controller
{
    /**
     * Quote and queue a new generation for the signed-in user.
     */
    public function store(StoreGenerationRequest $request, CreateGeneration $createGeneration): RedirectResponse
    {
        try {
            $createGeneration->handle(
                user: $request->user(),
                prompt: $request->validated('prompt'),
                size: $request->size(),
                quality: $request->quality(),
                referenceImages: $request->referenceImages(),
            );
        } catch (PixelforgeException $exception) {
            report($exception);

            return back()->withErrors([
                'generation' => __('Image generation is unavailable right now. Please try again in a moment.'),
            ]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Generation started.')]);

        return back();
    }
}
