<?php

namespace App\Http\Controllers;

use App\Actions\CreateGeneration;
use App\Http\Requests\StoreGenerationRequest;
use App\Http\Resources\GenerationResource;
use App\Models\Generation;
use App\Services\Pixelforge\Exceptions\PixelforgeException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class GenerationController extends Controller
{
    /**
     * The user's generations, newest first. The page polls while any are unfinished.
     */
    public function index(Request $request): Response
    {
        $generations = $request->user()->generations()
            ->latest()
            ->orderByDesc('id')
            ->paginate(12);

        return Inertia::render('generations/Index', [
            'generations' => GenerationResource::collection($generations),
            'hasUnfinished' => $request->user()->generations()->unfinished()->exists(),
        ]);
    }

    /**
     * The Generate page. Prices are estimated live via GenerationEstimateController.
     * `?from={id}` pre-fills it from one of the user's earlier generations (to retry or tweak it).
     */
    public function create(Request $request): Response
    {
        $source = $request->integer('from') > 0
            ? $request->user()->generations()->find($request->integer('from'))
            : null;

        return Inertia::render('generations/Create', [
            'markupPercent' => config()->integer('billing.markup_percent'),
            'prefill' => $source instanceof Generation ? [
                'prompt' => $source->prompt,
                'size' => $source->size,
                'quality' => $source->quality,
                'reference_images' => $source->reference_images,
            ] : null,
        ]);
    }

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

        return to_route('generations.index');
    }
}
