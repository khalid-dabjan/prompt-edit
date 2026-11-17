<?php

namespace App\Services\Pixelforge;

use RuntimeException;

/**
 * Verifies the `Pixelforge-Signature: t=<unix seconds>,v1=<hex>` header:
 * an HMAC-SHA256, keyed with this API key's signing secret, of
 * "<t>.<raw request body>".
 */
final class WebhookSignature
{
    public function __construct(
        private readonly string $secret,
        private readonly int $toleranceSeconds,
    ) {}

    /**
     * @param  string  $payload  The raw request body, before any JSON decoding.
     * @param  int  $now  The current unix time; signatures older or newer than the tolerance are replays.
     *
     * @throws RuntimeException when no signing secret is configured, so the misconfiguration is reported
     *                          and Pixelforge keeps retrying instead of every event being silently refused.
     */
    public function isValid(string $payload, ?string $header, int $now): bool
    {
        if ($this->secret === '') {
            throw new RuntimeException('PIXELFORGE_WEBHOOK_SECRET is not configured, so webhooks cannot be verified.');
        }

        $timestamp = null;
        $signatures = [];

        foreach (explode(',', $header ?? '') as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');

            match ($key) {
                't' => $timestamp = $value,
                'v1' => $signatures[] = $value,
                default => null,
            };
        }

        if ($timestamp === null || ! ctype_digit($timestamp) || $signatures === []) {
            return false;
        }

        if (abs($now - (int) $timestamp) > $this->toleranceSeconds) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$payload, $this->secret);

        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                return true;
            }
        }

        return false;
    }
}
