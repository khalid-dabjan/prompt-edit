<?php

namespace App\Http\Controllers;

use App\Actions\ApplyGenerationSnapshot;
use App\Http\Requests\PixelforgeWebhookRequest;
use App\Models\WebhookEvent;
use App\Services\Pixelforge\Data\Generation as PixelforgeGeneration;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Receives Pixelforge webhook events (signature already verified).
 *
 * Recording the event id and applying it happen in one transaction:
 *  - a second delivery of the same event hits the unique event id and is
 *    acknowledged without being applied again;
 *  - if applying fails, the event id is rolled back too and we answer 500,
 *    so Pixelforge's retry gets a clean second chance.
 *
 * Ordering is not handled here: ApplyGenerationSnapshot ignores stale states.
 */
class PixelforgeWebhookController extends Controller
{
    public function __invoke(PixelforgeWebhookRequest $request, ApplyGenerationSnapshot $applySnapshot): JsonResponse
    {
        $isNewEvent = DB::transaction(fn (): bool => $this->recordAndApply($request, $applySnapshot));

        return response()->json(['received' => true, 'duplicate' => ! $isNewEvent]);
    }

    /**
     * Record the event, then apply it. Returns false, without applying
     * anything, when this event id was already recorded (a duplicate delivery).
     */
    private function recordAndApply(PixelforgeWebhookRequest $request, ApplyGenerationSnapshot $applySnapshot): bool
    {
        $eventId = $request->string('id')->toString();

        try {
            $webhookEvent = WebhookEvent::create([
                'event_id' => $eventId,
                'type' => $request->string('type')->toString(),
                'pixelforge_generation_id' => $request->isGenerationEvent() ? $request->string('data.object.id')->toString() : null,
                'payload' => $request->json()->all(),
            ]);
        } catch (UniqueConstraintViolationException) {
            return false;
        }

        if ($request->isGenerationEvent()) {
            $this->applyGenerationEvent($applySnapshot, PixelforgeGeneration::fromArray($request->input('data.object')), $eventId);
        }

        $webhookEvent->update(['processed_at' => now()]);

        return true;
    }

    private function applyGenerationEvent(ApplyGenerationSnapshot $applySnapshot, PixelforgeGeneration $snapshot, string $eventId): void
    {
        $generation = $applySnapshot->findGenerationFor($snapshot);

        if ($generation === null) {
            Log::warning('Pixelforge webhook for an unknown generation.', [
                'event_id' => $eventId,
                'pixelforge_id' => $snapshot->id,
            ]);

            return;
        }

        $applySnapshot->handle($generation, $snapshot);
    }
}
