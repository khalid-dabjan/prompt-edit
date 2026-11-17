<?php

use App\Enums\GenerationStatus;
use App\Jobs\StoreGenerationImage;
use App\Models\Generation;
use App\Services\Pixelforge\Exceptions\PixelforgeApiException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/**
 * An unfinished generation with no update for two minutes.
 *
 * @param  array<string, mixed>  $attributes
 */
function quietGeneration(string $pixelforgeId, array $attributes = []): Generation
{
    return Generation::factory()->processing()->create([
        'pixelforge_id' => $pixelforgeId,
        'updated_at' => now()->subMinutes(2),
        ...$attributes,
    ]);
}

beforeEach(function () {
    $this->freezeSecond();
    Queue::fake([StoreGenerationImage::class]);
    Http::preventStrayRequests();
});

it('applies the latest state of a generation no webhook has updated for a minute', function () {
    $generation = quietGeneration('gen_A');
    Http::fake(['pixelforge.test/v1/generations/gen_A' => Http::response(array_replace(pixelforgeFixture('generation-succeeded'), ['id' => 'gen_A']))]);

    $this->artisan('pixelforge:poll-generations')->assertSuccessful();

    expect($generation->fresh())
        ->status->toBe(GenerationStatus::Succeeded)
        ->charge_nanodollars->toBe(381_887_500)
        ->polled_at->toEqual(now());
});

it('leaves generations alone that were updated in the last minute or are not in progress', function () {
    quietGeneration('gen_recent', ['updated_at' => now()->subSeconds(30)]);
    Generation::factory()->pending()->create(['updated_at' => now()->subMinutes(5)]);
    Generation::factory()->succeeded()->create(['updated_at' => now()->subMinutes(5)]);
    Generation::factory()->failed()->create(['updated_at' => now()->subMinutes(5)]);
    Http::fake();

    $this->artisan('pixelforge:poll-generations')->assertSuccessful();

    Http::assertNothingSent();
});

it('checks at most the limit per run, starting with the generations checked longest ago', function () {
    quietGeneration('gen_polled_recently', ['polled_at' => now()->subMinutes(2)]);
    quietGeneration('gen_never_polled_1');
    quietGeneration('gen_never_polled_2');
    Http::fake(fn (Request $request) => Http::response(array_replace(pixelforgeFixture('generation-queued'), [
        'id' => basename($request->url()),
        'status' => 'processing',
    ])));

    $this->artisan('pixelforge:poll-generations', ['--limit' => 2])->assertSuccessful();

    Http::assertSentCount(2);
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), 'gen_never_polled_1'));
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), 'gen_never_polled_2'));

    $this->travel(61)->seconds();
    $this->artisan('pixelforge:poll-generations', ['--limit' => 1])->assertSuccessful();

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), 'gen_polled_recently'));
});

it('does not mark a generation as updated when nothing changed', function () {
    $generation = quietGeneration('gen_A');
    Http::fake(['pixelforge.test/v1/generations/gen_A' => Http::response(array_replace(pixelforgeFixture('generation-queued'), [
        'id' => 'gen_A',
        'status' => 'processing',
    ]))]);

    $this->artisan('pixelforge:poll-generations')->assertSuccessful();

    expect($generation->fresh())
        ->updated_at->toEqual(now()->subMinutes(2))
        ->polled_at->toEqual(now());
});

it('stops the run when Pixelforge is rate limiting', function () {
    $first = quietGeneration('gen_A');
    quietGeneration('gen_B');
    Http::fake(['pixelforge.test/v1/generations/*' => Http::response(
        ['error' => ['type' => 'rate_limit_error', 'code' => 'rate_limit_exceeded', 'message' => 'Slow down.']],
        429,
        ['Retry-After' => '30'],
    )]);

    $this->artisan('pixelforge:poll-generations')->assertSuccessful();

    Http::assertSentCount(1);
    expect($first->fresh()->polled_at)->toBeNull();
});

it('reports an unexpected error and moves on to the next generation', function () {
    Exceptions::fake();
    $missing = quietGeneration('gen_missing');
    $healthy = quietGeneration('gen_B');
    Http::fake([
        'pixelforge.test/v1/generations/gen_missing' => Http::response(['error' => ['type' => 'invalid_request_error', 'code' => 'not_found', 'message' => 'No such resource.']], 404),
        'pixelforge.test/v1/generations/gen_B' => Http::response(array_replace(pixelforgeFixture('generation-succeeded'), ['id' => 'gen_B'])),
    ]);

    $this->artisan('pixelforge:poll-generations')->assertSuccessful();

    Exceptions::assertReported(fn (PixelforgeApiException $exception): bool => $exception->rawCode === 'not_found');
    expect($missing->fresh()->polled_at)->toEqual(now())
        ->and($healthy->fresh()->status)->toBe(GenerationStatus::Succeeded);
});
