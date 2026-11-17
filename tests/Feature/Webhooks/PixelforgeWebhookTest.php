<?php

use App\Enums\GenerationStatus;
use App\Jobs\StoreGenerationImage;
use App\Models\Generation;
use App\Models\WebhookEvent;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;

/**
 * @param  array<string, mixed>  $generation
 * @return array<string, mixed>
 */
function pixelforgeEvent(string $type, array $generation, string $eventId = 'evt_01JC2Z8Q4N6V0M3H7X5T9R2B1K'): array
{
    return [
        'id' => $eventId,
        'object' => 'event',
        'type' => $type,
        'created_at' => '2026-11-04T14:03:29Z',
        'data' => ['object' => $generation],
    ];
}

/**
 * Send a webhook the way Pixelforge does: HMAC-SHA256 of "<t>.<raw body>" with the signing secret.
 *
 * @param  array<string, mixed>|string  $event
 */
function sendPixelforgeWebhook(array|string $event, ?int $timestamp = null, string $secret = 'whsec_test_secret', ?string $signatureHeader = null): TestResponse
{
    $body = is_string($event) ? $event : json_encode($event);
    $timestamp ??= now()->getTimestamp();
    $signatureHeader ??= 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$body, $secret);

    return test()->call('POST', route('webhooks.pixelforge'), server: [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_PIXELFORGE_SIGNATURE' => $signatureHeader,
    ], content: $body);
}

beforeEach(function () {
    $this->freezeTime();
    Queue::fake([StoreGenerationImage::class]);
});

it('charges a generation when Pixelforge reports it succeeded', function () {
    $generation = Generation::factory()->processing()->create(['pixelforge_id' => 'gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K']);

    sendPixelforgeWebhook(pixelforgeEvent('generation.succeeded', pixelforgeFixture('generation-succeeded')))
        ->assertOk()
        ->assertExactJson(['received' => true, 'duplicate' => false]);

    expect($generation->fresh())
        ->status->toBe(GenerationStatus::Succeeded)
        ->charge_nanodollars->toBe(381_887_500);

    expect(WebhookEvent::query()->sole())
        ->event_id->toBe('evt_01JC2Z8Q4N6V0M3H7X5T9R2B1K')
        ->type->toBe('generation.succeeded')
        ->pixelforge_generation_id->toBe('gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K')
        ->processed_at->not->toBeNull();
});

it('acknowledges an event delivered twice without recording it twice', function () {
    $generation = Generation::factory()->processing()->create(['pixelforge_id' => 'gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K']);
    $event = pixelforgeEvent('generation.succeeded', pixelforgeFixture('generation-succeeded'));

    sendPixelforgeWebhook($event)->assertOk();
    sendPixelforgeWebhook($event)
        ->assertOk()
        ->assertExactJson(['received' => true, 'duplicate' => true]);

    expect(WebhookEvent::query()->count())->toBe(1)
        ->and($generation->fresh()->charge_nanodollars)->toBe(381_887_500);
});

it('keeps the final state when an older event arrives late', function () {
    $generation = Generation::factory()->processing()->create(['pixelforge_id' => 'gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K']);
    $processing = array_replace(pixelforgeFixture('generation-queued'), ['status' => 'processing', 'started_at' => '2026-11-04T14:03:13Z']);

    sendPixelforgeWebhook(pixelforgeEvent('generation.succeeded', pixelforgeFixture('generation-succeeded'), 'evt_succeeded'))->assertOk();
    sendPixelforgeWebhook(pixelforgeEvent('generation.processing', $processing, 'evt_processing'))->assertOk();

    expect($generation->fresh())
        ->status->toBe(GenerationStatus::Succeeded)
        ->charge_nanodollars->toBe(381_887_500);
});

it('links an event that arrives before the create response to its generation', function () {
    $generation = Generation::factory()->pending()->create();
    $processing = array_replace(pixelforgeFixture('generation-queued'), [
        'status' => 'processing',
        'metadata' => ['generation_id' => (string) $generation->id],
    ]);

    sendPixelforgeWebhook(pixelforgeEvent('generation.processing', $processing))->assertOk();

    expect($generation->fresh())
        ->pixelforge_id->toBe('gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K')
        ->status->toBe(GenerationStatus::Processing);
});

it('acknowledges an event for a generation we do not know', function () {
    $unknown = array_replace(pixelforgeFixture('generation-succeeded'), ['metadata' => []]);

    sendPixelforgeWebhook(pixelforgeEvent('generation.succeeded', $unknown))->assertOk();

    expect(WebhookEvent::query()->sole()->processed_at)->not->toBeNull();
});

it('acknowledges a test ping without touching any generation', function () {
    $generation = Generation::factory()->processing()->create();

    sendPixelforgeWebhook(['id' => 'evt_ping', 'object' => 'event', 'type' => 'ping', 'created_at' => '2026-11-04T14:03:29Z'])
        ->assertOk();

    expect(WebhookEvent::query()->sole()->type)->toBe('ping')
        ->and($generation->fresh()->status)->toBe(GenerationStatus::Processing);
});

it('rejects a request that was not signed by Pixelforge just now', function (Closure $send) {
    $generation = Generation::factory()->processing()->create(['pixelforge_id' => 'gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K']);
    $event = pixelforgeEvent('generation.succeeded', pixelforgeFixture('generation-succeeded'));

    $send($event)
        ->assertBadRequest()
        ->assertExactJson(['message' => 'Invalid signature.']);

    expect(WebhookEvent::query()->count())->toBe(0)
        ->and($generation->fresh()->status)->toBe(GenerationStatus::Processing);
})->with([
    'missing signature' => [fn (array $event) => sendPixelforgeWebhook($event, signatureHeader: '')],
    'signed with another secret' => [fn (array $event) => sendPixelforgeWebhook($event, secret: 'whsec_someone_else')],
    'body changed after signing' => [function (array $event) {
        $timestamp = now()->getTimestamp();
        $signature = hash_hmac('sha256', $timestamp.'.'.json_encode($event), 'whsec_test_secret');
        $event['data']['object']['usage']['reasoning_tokens'] = 1;

        return sendPixelforgeWebhook($event, signatureHeader: "t={$timestamp},v1={$signature}");
    }],
    'signed more than 300 seconds ago' => [fn (array $event) => sendPixelforgeWebhook($event, timestamp: now()->getTimestamp() - 301)],
    'signed more than 300 seconds in the future' => [fn (array $event) => sendPixelforgeWebhook($event, timestamp: now()->getTimestamp() + 301)],
]);

it('accepts a signature exactly 300 seconds old', function () {
    Generation::factory()->processing()->create(['pixelforge_id' => 'gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K']);

    sendPixelforgeWebhook(pixelforgeEvent('generation.succeeded', pixelforgeFixture('generation-succeeded')), timestamp: now()->getTimestamp() - 300)
        ->assertOk();
});

it('rejects a signed event that is malformed', function (array $event, array $invalidFields) {
    sendPixelforgeWebhook($event)
        ->assertUnprocessable()
        ->assertInvalid($invalidFields);

    expect(WebhookEvent::query()->count())->toBe(0);
})->with([
    'without id or type' => [['object' => 'event', 'data' => []], ['id', 'type']],
    'generation event without a generation' => [['id' => 'evt_1', 'type' => 'generation.succeeded', 'data' => []], ['data.object']],
    'generation with an unknown status' => [
        ['id' => 'evt_1', 'type' => 'generation.succeeded', 'data' => ['object' => ['id' => 'gen_1', 'status' => 'exploded']]],
        ['data.object.status'],
    ],
]);

it('rolls back the event when applying it fails, so the retried delivery is processed', function () {
    Exceptions::fake();
    $generation = Generation::factory()->processing()->create(['pixelforge_id' => 'gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K']);
    $broken = array_replace(pixelforgeFixture('generation-succeeded'), ['usage' => null]);
    $event = pixelforgeEvent('generation.succeeded', $broken);

    sendPixelforgeWebhook($event)->assertServerError();
    expect(WebhookEvent::query()->count())->toBe(0);

    sendPixelforgeWebhook(pixelforgeEvent('generation.succeeded', pixelforgeFixture('generation-succeeded')))
        ->assertOk()
        ->assertJson(['duplicate' => false]);
    expect($generation->fresh()->charge_nanodollars)->toBe(381_887_500);
});

it('fails loudly when the signing secret is not configured', function () {
    Exceptions::fake();
    config(['services.pixelforge.webhook_secret' => '']);

    sendPixelforgeWebhook(pixelforgeEvent('generation.succeeded', pixelforgeFixture('generation-succeeded')))
        ->assertServerError();

    Exceptions::assertReported(RuntimeException::class);
});

it('is served outside the web middleware group', function () {
    // CSRF verification is skipped while running tests, so it can't be exercised
    // here; Pixelforge sends no CSRF token or session cookie, so the route must not use "web".
    expect(Route::getRoutes()->getByName('webhooks.pixelforge')->gatherMiddleware())->not->toContain('web');
});
