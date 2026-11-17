<?php

namespace App\Http\Middleware;

use App\Services\Pixelforge\WebhookSignature;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rejects any webhook request not signed by Pixelforge with this
 * environment's signing secret, or signed too long ago (a replay).
 */
class VerifyPixelforgeSignature
{
    public function __construct(private readonly WebhookSignature $signature) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $isValid = $this->signature->isValid(
            payload: $request->getContent(),
            header: $request->header('Pixelforge-Signature'),
            now: now()->getTimestamp(),
        );

        if (! $isValid) {
            return response()->json(['message' => 'Invalid signature.'], 400);
        }

        return $next($request);
    }
}
