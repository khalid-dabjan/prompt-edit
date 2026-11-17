<?php

namespace App\Services\Pixelforge\Exceptions;

use App\Services\Pixelforge\ErrorCode;
use Illuminate\Http\Client\Response;

/**
 * Pixelforge answered with an error response (4xx/5xx).
 */
class PixelforgeApiException extends PixelforgeException
{
    /**
     * @param  string|null  $rawCode  The `error.code` exactly as sent, even when it is not a known ErrorCode.
     * @param  string|null  $param  For validation errors, the first invalid parameter.
     * @param  array<string, list<string>>  $errors  For validation errors, every invalid parameter and its messages.
     */
    public function __construct(
        string $message,
        public readonly int $status,
        public readonly ?string $rawCode,
        public readonly ?string $type = null,
        public readonly ?string $param = null,
        public readonly array $errors = [],
        private readonly ?int $retryAfterSeconds = null,
    ) {
        parent::__construct($message, $status);
    }

    public static function fromResponse(Response $response): self
    {
        $error = $response->json('error');
        $error = is_array($error) ? $error : [];

        $retryAfter = $response->header('Retry-After');

        return new self(
            message: is_string($error['message'] ?? null) ? $error['message'] : "Pixelforge responded with HTTP {$response->status()}.",
            status: $response->status(),
            rawCode: is_string($error['code'] ?? null) ? $error['code'] : null,
            type: is_string($error['type'] ?? null) ? $error['type'] : null,
            param: is_string($error['param'] ?? null) ? $error['param'] : null,
            errors: is_array($error['errors'] ?? null) ? $error['errors'] : [],
            retryAfterSeconds: is_numeric($retryAfter) ? (int) $retryAfter : null,
        );
    }

    /**
     * The known error code, or null when Pixelforge sent none or one we don't know yet.
     */
    public function errorCode(): ?ErrorCode
    {
        return $this->rawCode === null ? null : ErrorCode::tryFrom($this->rawCode);
    }

    public function isRetryable(): bool
    {
        $errorCode = $this->errorCode();

        if ($errorCode !== null) {
            return $errorCode->isTransient();
        }

        return $this->status >= 500;
    }

    public function retryAfter(): ?int
    {
        return $this->retryAfterSeconds;
    }
}
