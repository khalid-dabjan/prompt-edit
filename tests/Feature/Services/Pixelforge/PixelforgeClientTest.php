<?php

use App\Enums\GenerationStatus;
use App\Enums\ImageQuality;
use App\Enums\ImageSize;
use App\Services\Pixelforge\ErrorCode;
use App\Services\Pixelforge\Exceptions\PixelforgeApiException;
use App\Services\Pixelforge\Exceptions\PixelforgeConnectionException;
use App\Services\Pixelforge\PixelforgeClient;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
});

it('creates a generation with the bearer token, idempotency key and options', function () {
    Http::fake([
        'pixelforge.test/v1/generations' => Http::response(pixelforgeFixture('generation-queued'), 202),
    ]);

    $generation = app(PixelforgeClient::class)->createGeneration(
        prompt: 'A lighthouse on a cliff at dusk',
        size: ImageSize::Landscape,
        quality: ImageQuality::High,
        idempotencyKey: 'idem-123',
        referenceImages: ['https://example.com/reference.png'],
        metadata: ['order_id' => '1042'],
    );

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === 'https://pixelforge.test/v1/generations'
        && $request->hasHeader('Authorization', 'Bearer pf_test_key')
        && $request->hasHeader('Idempotency-Key', 'idem-123')
        && $request->data() === [
            'prompt' => 'A lighthouse on a cliff at dusk',
            'size' => '1536x1024',
            'quality' => 'high',
            'reference_images' => ['https://example.com/reference.png'],
            'metadata' => ['order_id' => '1042'],
        ]);

    expect($generation->id)->toBe('gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K')
        ->and($generation->status)->toBe(GenerationStatus::Queued)
        ->and($generation->size)->toBe(ImageSize::Landscape)
        ->and($generation->quality)->toBe(ImageQuality::High)
        ->and($generation->rates->reasoningTokens)->toBe('12.50')
        ->and($generation->usage)->toBeNull()
        ->and($generation->completedAt)->toBeNull();
});

it('omits empty reference images and metadata from the request', function () {
    Http::fake([
        'pixelforge.test/v1/generations' => Http::response(pixelforgeFixture('generation-queued'), 202),
    ]);

    app(PixelforgeClient::class)->createGeneration(
        prompt: 'A lighthouse',
        size: ImageSize::Square,
        quality: ImageQuality::Medium,
        idempotencyKey: 'idem-123',
    );

    Http::assertSent(fn (Request $request): bool => $request->data() === [
        'prompt' => 'A lighthouse',
        'size' => '1024x1024',
        'quality' => 'medium',
    ]);
});

it('returns the usage, image and timestamps of a succeeded generation', function () {
    Http::fake([
        'pixelforge.test/v1/generations/gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K' => Http::response(pixelforgeFixture('generation-succeeded')),
    ]);

    $generation = app(PixelforgeClient::class)->getGeneration('gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K');

    expect($generation->status)->toBe(GenerationStatus::Succeeded)
        ->and($generation->usage->inputTextTokens)->toBe(12)
        ->and($generation->usage->inputImageTokens)->toBe(765)
        ->and($generation->usage->outputTokens)->toBe(6450)
        ->and($generation->usage->reasoningTokens)->toBe(3184)
        ->and($generation->usage->totalTokens)->toBe(10411)
        ->and($generation->imageUrl)->toStartWith('https://pixelforge.promptedit.dev/images/')
        ->and($generation->imageUrlExpiresAt->equalTo(CarbonImmutable::parse('2026-11-05T14:03:29Z')))->toBeTrue()
        ->and($generation->startedAt->equalTo(CarbonImmutable::parse('2026-11-04T14:03:13Z')))->toBeTrue()
        ->and($generation->completedAt->equalTo(CarbonImmutable::parse('2026-11-04T14:03:29Z')))->toBeTrue()
        ->and($generation->error)->toBeNull();
});

it('returns the error of a generation that failed after being accepted', function () {
    Http::fake([
        'pixelforge.test/v1/generations/gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K' => Http::response(pixelforgeFixture('generation-failed')),
    ]);

    $generation = app(PixelforgeClient::class)->getGeneration('gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K');

    expect($generation->status)->toBe(GenerationStatus::Failed)
        ->and($generation->error->errorCode())->toBe(ErrorCode::InternalError)
        ->and($generation->error->message)->toContain('You were not charged')
        ->and($generation->usage)->toBeNull()
        ->and($generation->metadata)->toBe([]);
});

it('lists generations with the status filter and cursor', function () {
    Http::fake([
        'pixelforge.test/v1/generations?*' => Http::response([
            'object' => 'list',
            'data' => [pixelforgeFixture('generation-succeeded')],
            'has_more' => true,
            'next_cursor' => 'cursor-2',
        ]),
    ]);

    $page = app(PixelforgeClient::class)->listGenerations(limit: 1, status: GenerationStatus::Succeeded, cursor: 'cursor-1');

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://pixelforge.test/v1/generations?limit=1&status=succeeded&cursor=cursor-1');

    expect($page->generations)->toHaveCount(1)
        ->and($page->generations[0]->status)->toBe(GenerationStatus::Succeeded)
        ->and($page->hasMore)->toBeTrue()
        ->and($page->nextCursor)->toBe('cursor-2');
});

it('returns the published rates and token rules', function () {
    Http::fake([
        'pixelforge.test/v1/pricing' => Http::response(pixelforgeFixture('pricing')),
    ]);

    $pricing = app(PixelforgeClient::class)->getPricing();

    expect($pricing->rates->inputTextTokens)->toBe('5.00')
        ->and($pricing->rates->outputTokens)->toBe('40.00')
        ->and($pricing->charactersPerTextToken)->toBe(4)
        ->and($pricing->textTokenVariance)->toBe('0.15')
        ->and($pricing->tokensPerReferenceImage)->toBe(765)
        ->and($pricing->outputTokens['high']['1536x1024'])->toBe(6450)
        ->and($pricing->reasoningTokens['high'])->toBe(['min' => 1000, 'max' => 8000]);
});

it('returns the usage report keyed by UTC day for the requested range', function () {
    Http::fake([
        'pixelforge.test/v1/usage?*' => Http::response(pixelforgeFixture('usage')),
    ]);

    $report = app(PixelforgeClient::class)->getUsage(
        CarbonImmutable::parse('2026-11-01'),
        CarbonImmutable::parse('2026-11-02'),
    );

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://pixelforge.test/v1/usage?start_date=2026-11-01&end_date=2026-11-02');

    expect(array_keys($report->days))->toBe(['2026-11-01', '2026-11-02'])
        ->and($report->days['2026-11-01']->generations)->toBe(3)
        ->and($report->days['2026-11-01']->costUsd)->toBe('0.169080000')
        ->and($report->days['2026-11-02']->costUsd)->toBe('0.000000000')
        ->and($report->total->costUsd)->toBe('0.169080000');
});

it('throws an API exception describing the error response', function (int $status, string $type, string $code, array $headers, bool $retryable, ?int $retryAfter) {
    Http::fake([
        'pixelforge.test/v1/generations/*' => Http::response(
            ['error' => ['type' => $type, 'code' => $code, 'message' => 'Pixelforge says no.']],
            $status,
            $headers,
        ),
    ]);

    try {
        app(PixelforgeClient::class)->getGeneration('gen_123');
        $this->fail('Expected a PixelforgeApiException.');
    } catch (PixelforgeApiException $exception) {
        expect($exception->status)->toBe($status)
            ->and($exception->type)->toBe($type)
            ->and($exception->errorCode())->toBe(ErrorCode::from($code))
            ->and($exception->getMessage())->toBe('Pixelforge says no.')
            ->and($exception->isRetryable())->toBe($retryable)
            ->and($exception->retryAfter())->toBe($retryAfter);
    }
})->with([
    'invalid api key' => [401, 'authentication_error', 'invalid_api_key', [], false, null],
    'content policy violation' => [400, 'invalid_request_error', 'content_policy_violation', [], false, null],
    'not found' => [404, 'invalid_request_error', 'not_found', [], false, null],
    'idempotency key reused' => [409, 'idempotency_error', 'idempotency_key_reused', [], false, null],
    'rate limit exceeded' => [429, 'rate_limit_error', 'rate_limit_exceeded', ['Retry-After' => '12'], true, 12],
    'concurrency limit exceeded' => [429, 'rate_limit_error', 'concurrency_limit_exceeded', ['Retry-After' => '5'], true, 5],
    'daily limit exceeded' => [429, 'rate_limit_error', 'daily_limit_exceeded', [], false, null],
    'overloaded' => [503, 'api_error', 'overloaded', ['Retry-After' => '2'], true, 2],
]);

it('exposes every invalid parameter of a validation error', function () {
    Http::fake([
        'pixelforge.test/v1/generations' => Http::response([
            'error' => [
                'type' => 'invalid_request_error',
                'code' => 'validation_failed',
                'message' => 'The selected size is invalid.',
                'param' => 'size',
                'errors' => [
                    'size' => ['The selected size is invalid.'],
                    'prompt' => ['The prompt field must not be greater than 4000 characters.'],
                ],
            ],
        ], 422),
    ]);

    try {
        app(PixelforgeClient::class)->createGeneration('A lighthouse', ImageSize::Square, ImageQuality::Low, 'idem-123');
        $this->fail('Expected a PixelforgeApiException.');
    } catch (PixelforgeApiException $exception) {
        expect($exception->errorCode())->toBe(ErrorCode::ValidationFailed)
            ->and($exception->param)->toBe('size')
            ->and($exception->errors)->toHaveKeys(['size', 'prompt'])
            ->and($exception->isRetryable())->toBeFalse();
    }
});

it('treats a server error without a known error code as retryable', function () {
    Http::fake([
        'pixelforge.test/v1/generations/*' => Http::response('Bad Gateway', 502),
    ]);

    try {
        app(PixelforgeClient::class)->getGeneration('gen_123');
        $this->fail('Expected a PixelforgeApiException.');
    } catch (PixelforgeApiException $exception) {
        expect($exception->status)->toBe(502)
            ->and($exception->errorCode())->toBeNull()
            ->and($exception->isRetryable())->toBeTrue();
    }
});

it('throws a connection exception when Pixelforge cannot be reached', function () {
    Http::fake([
        'pixelforge.test/v1/generations' => Http::failedConnection(),
    ]);

    app(PixelforgeClient::class)->createGeneration('A lighthouse', ImageSize::Square, ImageQuality::Low, 'idem-123');
})->throws(PixelforgeConnectionException::class);
