<?php

namespace App\Services\Pixelforge\Exceptions;

use RuntimeException;

/**
 * Base for every failure talking to Pixelforge. Callers decide whether and
 * when to retry from isRetryable() and retryAfter().
 */
abstract class PixelforgeException extends RuntimeException
{
    /**
     * Whether sending the same request again later may succeed.
     */
    abstract public function isRetryable(): bool;

    /**
     * Seconds Pixelforge asked us to wait before retrying, when it said so.
     */
    abstract public function retryAfter(): ?int;
}
