<?php

namespace App\Services\Pixelforge\Exceptions;

use Illuminate\Http\Client\ConnectionException;

/**
 * Pixelforge could not be reached, or did not answer before the timeout.
 * For a create request the outcome is unknown, so it must be retried with
 * the same Idempotency-Key.
 */
class PixelforgeConnectionException extends PixelforgeException
{
    public static function fromConnectionException(ConnectionException $exception): self
    {
        return new self('Could not reach Pixelforge: '.$exception->getMessage(), previous: $exception);
    }

    public function isRetryable(): bool
    {
        return true;
    }

    public function retryAfter(): ?int
    {
        return null;
    }
}
