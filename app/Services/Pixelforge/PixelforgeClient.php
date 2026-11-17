<?php

namespace App\Services\Pixelforge;

use App\Enums\GenerationStatus;
use App\Enums\ImageQuality;
use App\Enums\ImageSize;
use App\Services\Pixelforge\Data\Generation;
use App\Services\Pixelforge\Data\GenerationPage;
use App\Services\Pixelforge\Data\Pricing;
use App\Services\Pixelforge\Data\UsageReport;
use App\Services\Pixelforge\Exceptions\PixelforgeApiException;
use App\Services\Pixelforge\Exceptions\PixelforgeConnectionException;
use App\Services\Pixelforge\Exceptions\PixelforgeException;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Thin wrapper over the Pixelforge HTTP API. It sends one request per call
 * and never retries: retry timing (Retry-After, backoff, limits) is the
 * caller's decision, usually a queued job.
 */
class PixelforgeClient
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $baseUrl,
        private readonly int $connectTimeout = 5,
        private readonly int $timeout = 15,
    ) {}

    /**
     * Submit a generation. Sending the same idempotency key with the same
     * parameters returns the original generation instead of creating a new one.
     *
     * @param  list<string>  $referenceImages
     * @param  array<string, string>  $metadata
     *
     * @throws PixelforgeException
     */
    public function createGeneration(
        string $prompt,
        ImageSize $size,
        ImageQuality $quality,
        string $idempotencyKey,
        array $referenceImages = [],
        array $metadata = [],
    ): Generation {
        $payload = array_filter([
            'prompt' => $prompt,
            'size' => $size->value,
            'quality' => $quality->value,
            'reference_images' => $referenceImages,
            'metadata' => $metadata,
        ], fn (mixed $value): bool => $value !== []);

        $response = $this->send(fn (PendingRequest $request): Response => $request
            ->withHeaders(['Idempotency-Key' => $idempotencyKey])
            ->post('generations', $payload));

        return Generation::fromArray($response->json());
    }

    /**
     * @throws PixelforgeException
     */
    public function getGeneration(string $id): Generation
    {
        $response = $this->send(fn (PendingRequest $request): Response => $request
            ->get('generations/'.rawurlencode($id)));

        return Generation::fromArray($response->json());
    }

    /**
     * @throws PixelforgeException
     */
    public function listGenerations(int $limit = 20, ?GenerationStatus $status = null, ?string $cursor = null): GenerationPage
    {
        $query = array_filter([
            'limit' => $limit,
            'status' => $status?->value,
            'cursor' => $cursor,
        ], fn (mixed $value): bool => $value !== null);

        $response = $this->send(fn (PendingRequest $request): Response => $request
            ->get('generations', $query));

        return GenerationPage::fromArray($response->json());
    }

    /**
     * @throws PixelforgeException
     */
    public function getPricing(): Pricing
    {
        $response = $this->send(fn (PendingRequest $request): Response => $request->get('pricing'));

        return Pricing::fromArray($response->json());
    }

    /**
     * What Pixelforge billed this API key per UTC day. Without dates it
     * covers the last 30 days; at most 92 days may be requested.
     *
     * @throws PixelforgeException
     */
    public function getUsage(?CarbonInterface $startDate = null, ?CarbonInterface $endDate = null): UsageReport
    {
        $query = array_filter([
            'start_date' => $startDate?->toDateString(),
            'end_date' => $endDate?->toDateString(),
        ]);

        $response = $this->send(fn (PendingRequest $request): Response => $request->get('usage', $query));

        return UsageReport::fromArray($response->json());
    }

    /**
     * @param  Closure(PendingRequest): Response  $request
     *
     * @throws PixelforgeException
     */
    private function send(Closure $request): Response
    {
        try {
            $response = $request($this->newRequest());
        } catch (ConnectionException $exception) {
            throw PixelforgeConnectionException::fromConnectionException($exception);
        }

        if ($response->failed()) {
            throw PixelforgeApiException::fromResponse($response);
        }

        return $response;
    }

    private function newRequest(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl)
            ->withToken($this->apiKey)
            ->acceptJson()
            ->asJson()
            ->connectTimeout($this->connectTimeout)
            ->timeout($this->timeout);
    }
}
