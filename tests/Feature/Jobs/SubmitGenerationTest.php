<?php

use App\Actions\ApplyGenerationSnapshot;
use App\Enums\GenerationStatus;
use App\Enums\ImageQuality;
use App\Enums\ImageSize;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Services\Pixelforge\Exceptions\PixelforgeApiException;
use App\Services\Pixelforge\PixelforgeClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;

function runSubmitGeneration(Generation $generation): SubmitGeneration
{
    $job = (new SubmitGeneration($generation))->withFakeQueueInteractions();
    $job->handle(app(PixelforgeClient::class), app(ApplyGenerationSnapshot::class));

    return $job;
}

/**
 * @return array<string, mixed>
 */
function pixelforgeError(string $type, string $code): array
{
    return ['error' => ['type' => $type, 'code' => $code, 'message' => 'Pixelforge says no.']];
}

it('submits the generation with its idempotency key and records that Pixelforge accepted it', function () {
    Http::preventStrayRequests();
    Http::fake(['pixelforge.test/v1/generations' => Http::response(pixelforgeFixture('generation-queued'), 202)]);
    $generation = Generation::factory()->pending()->create([
        'prompt' => 'A lighthouse on a cliff at dusk',
        'size' => ImageSize::Landscape,
        'quality' => ImageQuality::High,
        'reference_images' => ['https://example.com/reference.png'],
    ]);

    $job = runSubmitGeneration($generation);

    Http::assertSent(fn (Request $request): bool => $request->hasHeader('Idempotency-Key', $generation->idempotency_key)
        && $request->data() === [
            'prompt' => 'A lighthouse on a cliff at dusk',
            'size' => '1536x1024',
            'quality' => 'high',
            'reference_images' => ['https://example.com/reference.png'],
            'metadata' => ['generation_id' => (string) $generation->id],
        ]);

    $job->assertNotReleased();
    expect($generation->fresh())
        ->status->toBe(GenerationStatus::Queued)
        ->pixelforge_id->toBe('gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K')
        ->rates->toBe([
            'input_text_tokens' => '5.00',
            'input_image_tokens' => '10.00',
            'output_tokens' => '40.00',
            'reasoning_tokens' => '12.50',
        ]);
});

it('does not submit a generation that is no longer pending', function () {
    Http::preventStrayRequests();
    Http::fake();
    $generation = Generation::factory()->processing()->create();

    runSubmitGeneration($generation);

    Http::assertNothingSent();
});

it('waits and retries with the same idempotency key when Pixelforge refuses for now', function (int $status, array $body, array $headers, int $expectedDelay) {
    Http::preventStrayRequests();
    Http::fake(['pixelforge.test/v1/generations' => Http::response($body, $status, $headers)]);
    $generation = Generation::factory()->pending()->create();

    $job = runSubmitGeneration($generation);

    $job->assertReleased(delay: $expectedDelay);
    expect($generation->fresh()->status)->toBe(GenerationStatus::Pending);
})->with([
    'overloaded' => [503, pixelforgeError('api_error', 'overloaded'), ['Retry-After' => '2'], 2],
    'rate limit exceeded' => [429, pixelforgeError('rate_limit_error', 'rate_limit_exceeded'), ['Retry-After' => '12'], 12],
    'concurrency limit exceeded' => [429, pixelforgeError('rate_limit_error', 'concurrency_limit_exceeded'), ['Retry-After' => '5'], 5],
    'server error without Retry-After' => [502, [], [], 5],
]);

it('waits and retries when Pixelforge cannot be reached', function () {
    Http::preventStrayRequests();
    Http::fake(['pixelforge.test/v1/generations' => Http::failedConnection()]);
    $generation = Generation::factory()->pending()->create();

    $job = runSubmitGeneration($generation);

    $job->assertReleased(delay: 5);
    expect($generation->fresh()->status)->toBe(GenerationStatus::Pending);
});

it('fails the generation at no cost when Pixelforge rejects it', function (int $status, string $type, string $code) {
    Http::preventStrayRequests();
    Http::fake(['pixelforge.test/v1/generations' => Http::response(pixelforgeError($type, $code), $status)]);
    $generation = Generation::factory()->pending()->create();

    $job = runSubmitGeneration($generation);

    $job->assertNotReleased();
    expect($generation->fresh())
        ->status->toBe(GenerationStatus::Failed)
        ->error_code->toBe($code)
        ->error_message->toBe('Pixelforge says no.')
        ->provider_cost_nanodollars->toBe(0)
        ->charge_nanodollars->toBe(0)
        ->completed_at->not->toBeNull();
})->with([
    'content policy violation' => [400, 'invalid_request_error', 'content_policy_violation'],
    'daily limit exceeded' => [429, 'rate_limit_error', 'daily_limit_exceeded'],
]);

it('reports a rejection caused by our own configuration', function () {
    Exceptions::fake();
    Http::preventStrayRequests();
    Http::fake(['pixelforge.test/v1/generations' => Http::response(pixelforgeError('authentication_error', 'invalid_api_key'), 401)]);
    $generation = Generation::factory()->pending()->create();

    runSubmitGeneration($generation);

    Exceptions::assertReported(fn (PixelforgeApiException $exception): bool => $exception->rawCode === 'invalid_api_key');
    expect($generation->fresh()->status)->toBe(GenerationStatus::Failed);
});

it('fails a still-pending generation at no cost once retries run out', function () {
    $generation = Generation::factory()->pending()->create();

    (new SubmitGeneration($generation))->failed(new RuntimeException('Retries exhausted.'));

    expect($generation->fresh())
        ->status->toBe(GenerationStatus::Failed)
        ->error_code->toBe(SubmitGeneration::SUBMISSION_FAILED)
        ->charge_nanodollars->toBe(0);
});

it('leaves a generation Pixelforge already accepted untouched when retries run out', function () {
    $generation = Generation::factory()->create();

    (new SubmitGeneration($generation))->failed(new RuntimeException('Retries exhausted.'));

    expect($generation->fresh())
        ->status->toBe(GenerationStatus::Queued)
        ->error_code->toBeNull();
});

it('charges a generation whose idempotent replay shows it already succeeded', function () {
    Http::preventStrayRequests();
    Http::fake(['pixelforge.test/v1/generations' => Http::response(pixelforgeFixture('generation-succeeded'), 202, ['Idempotent-Replayed' => 'true'])]);
    $generation = Generation::factory()->pending()->create();

    runSubmitGeneration($generation);

    expect($generation->fresh())
        ->status->toBe(GenerationStatus::Succeeded)
        ->provider_cost_nanodollars->toBe(305_510_000)
        ->charge_nanodollars->toBe(381_887_500);
});
