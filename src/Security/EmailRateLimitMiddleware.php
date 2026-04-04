<?php

namespace NewSolari\Core\Security;

use Closure;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rate limiting middleware specifically for email-related endpoints.
 *
 * This middleware applies stricter rate limits to email endpoints
 * (password reset, email verification) to prevent:
 * - Email bombing attacks
 * - Brute force token attempts
 * - Email enumeration via timing attacks
 *
 * Default: 3 attempts per minute per IP
 */
class EmailRateLimitMiddleware
{
    /**
     * Maximum attempts allowed per decay period.
     */
    protected int $maxAttempts = 3;

    /**
     * Decay period in minutes.
     */
    protected int $decayMinutes = 1;

    public function __construct(protected RateLimiter $limiter)
    {
        // Use config() for proper caching and consistency with other middleware
        $maxAttempts = (int) config('ratelimits.email.max_attempts', 3);
        $decayMinutes = (int) config('ratelimits.email.decay_minutes', 1);

        // Ensure values are within reasonable bounds
        $this->maxAttempts = max(1, min($maxAttempts, 100));
        $this->decayMinutes = max(1, min($decayMinutes, 60));
    }

    public function handle(Request $request, Closure $next): Response
    {
        $key = $this->resolveRequestSignature($request);

        if ($this->limiter->tooManyAttempts($key, $this->maxAttempts)) {
            $retryAfter = $this->limiter->availableIn($key);

            Log::warning('Email endpoint rate limit exceeded', [
                'ip' => $request->ip(),
                'path' => $request->path(),
                'retry_after' => $retryAfter,
            ]);

            $response = response()->json([
                'value' => false,
                'result' => 'Too many requests. Please wait before trying again.',
                'code' => 429,
                'retry_after' => $retryAfter,
            ], 429);

            $response->headers->add([
                'Retry-After' => $retryAfter,
                'X-RateLimit-Limit' => $this->maxAttempts,
                'X-RateLimit-Remaining' => 0,
            ]);

            return $response;
        }

        $this->limiter->hit($key, $this->decayMinutes * 60);

        $response = $next($request);

        $remaining = max(0, $this->maxAttempts - $this->limiter->attempts($key));
        $response->headers->add([
            'X-RateLimit-Limit' => $this->maxAttempts,
            'X-RateLimit-Remaining' => $remaining,
        ]);

        return $response;
    }

    /**
     * Generate a unique key for rate limiting based on IP and endpoint.
     */
    protected function resolveRequestSignature(Request $request): string
    {
        // Use IP + path to allow different email endpoints to have separate limits
        return 'email_rate_limit:'.sha1($request->ip().'|'.$request->path());
    }
}
