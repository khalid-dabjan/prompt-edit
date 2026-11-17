<?php

namespace App\Services\Pixelforge;

/**
 * Error codes Pixelforge returns in `error.code`, both for rejected HTTP
 * requests and on failed generations.
 */
enum ErrorCode: string
{
    case InvalidApiKey = 'invalid_api_key';
    case ValidationFailed = 'validation_failed';
    case ContentPolicyViolation = 'content_policy_violation';
    case NotFound = 'not_found';
    case IdempotencyKeyReused = 'idempotency_key_reused';
    case RateLimitExceeded = 'rate_limit_exceeded';
    case ConcurrencyLimitExceeded = 'concurrency_limit_exceeded';
    case DailyLimitExceeded = 'daily_limit_exceeded';
    case Overloaded = 'overloaded';
    case DateRangeTooLong = 'date_range_too_long';
    case GenerationFailed = 'generation_failed';
    case InternalError = 'internal_error';

    /**
     * Whether the same request may succeed if sent again after waiting.
     */
    public function isTransient(): bool
    {
        return match ($this) {
            self::RateLimitExceeded,
            self::ConcurrencyLimitExceeded,
            self::Overloaded => true,
            default => false,
        };
    }
}
