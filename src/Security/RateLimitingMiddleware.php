<?php

namespace NewSolari\Core\Security;

use Closure;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class RateLimitingMiddleware
{
    /**
     * The rate limiter instance.
     *
     * @var \Illuminate\Cache\RateLimiter
     */
    protected $limiter;

    /**
     * The maximum number of attempts allowed.
     *
     * @var int
     */
    protected $maxAttempts;

    /**
     * The number of minutes to throttle for.
     *
     * @var int
     */
    protected $decayMinutes;

    /**
     * Create a new middleware instance.
     *
     * @return void
     */
    public function __construct(RateLimiter $limiter)
    {
        $this->limiter = $limiter;

        // Validate and sanitize rate limit config values
        // Must be positive integers within reasonable bounds
        $maxAttempts = (int) config('ratelimits.global.max_attempts', 200);
        $decayMinutes = (int) config('ratelimits.global.decay_minutes', 1);

        // Define bounds from config (prevents misconfiguration from causing security issues)
        $minAttempts = (int) config('ratelimits.global.min_attempts', 10);
        $maxAttemptsLimit = (int) config('ratelimits.global.max_attempts_limit', 10000);
        $minDecay = (int) config('ratelimits.global.min_decay', 1);
        $maxDecay = (int) config('ratelimits.global.max_decay', 60);

        // Clamp values within bounds to prevent bypass (too high) or DoS (too low)
        $this->maxAttempts = max($minAttempts, min($maxAttempts, $maxAttemptsLimit));
        $this->decayMinutes = max($minDecay, min($decayMinutes, $maxDecay));

        // Log warning if values were clamped
        if ($maxAttempts !== $this->maxAttempts || $decayMinutes !== $this->decayMinutes) {
            Log::warning('RateLimitingMiddleware: Config values clamped to bounds', [
                'original_max_attempts' => $maxAttempts,
                'original_decay_minutes' => $decayMinutes,
                'clamped_max_attempts' => $this->maxAttempts,
                'clamped_decay_minutes' => $this->decayMinutes,
                'bounds' => "attempts: {$minAttempts}-{$maxAttemptsLimit}, decay: {$minDecay}-{$maxDecay}",
            ]);
        }
    }

    /**
     * Handle an incoming request.
     *
     * @return mixed
     */
    public function handle(Request $request, Closure $next): Response
    {
        $key = $this->resolveRequestSignature($request);

        Log::debug('Rate limiting check', [
            'key' => $key,
            'ip' => $request->ip(),
            'path' => $request->path(),
            'attempts' => $this->limiter->attempts($key),
        ]);

        // IMPORTANT: Check BEFORE incrementing to prevent race condition
        // This ensures we don't allow an extra request when limit is reached
        if ($this->limiter->tooManyAttempts($key, $this->maxAttempts)) {
            $retryAfter = $this->limiter->availableIn($key);

            Log::warning('Rate limit exceeded', [
                'key' => $key,
                'ip' => $request->ip(),
                'path' => $request->path(),
                'retry_after' => $retryAfter,
            ]);

            $response = response()->json([
                'value' => false,
                'result' => 'Too many requests',
                'code' => 429,
                'retry_after' => $retryAfter,
            ], 429);

            $response->headers->add([
                'Retry-After' => $retryAfter,
            ]);

            return $this->addHeaders(
                $response,
                $this->maxAttempts,
                0 // No remaining attempts when rate limited
            );
        }

        // Increment the counter after checking (not before)
        $this->limiter->hit($key, $this->decayMinutes * 60);

        $response = $next($request);

        return $this->addHeaders(
            $response,
            $this->maxAttempts,
            $this->calculateRemainingAttempts($key)
        );
    }

    /**
     * Resolve request signature.
     *
     * @param  \Illuminate\Http\Request  $request
     */
    protected function resolveRequestSignature($request): string
    {
        if ($user = $request->user()) {
            return sha1($user->getAuthIdentifier());
        }

        if ($route = $request->route()) {
            return sha1($route->getDomain().'|'.$route->uri());
        }

        return sha1($request->ip());
    }

    /**
     * Calculate the number of remaining attempts.
     *
     * @param  string  $key
     */
    protected function calculateRemainingAttempts($key): int
    {
        return max(0, $this->maxAttempts - $this->limiter->attempts($key));
    }

    /**
     * Add the limit header information to the given response.
     */
    protected function addHeaders(Response $response, int $maxAttempts, int $remainingAttempts): Response
    {
        $response->headers->add([
            'X-RateLimit-Limit' => $maxAttempts,
            'X-RateLimit-Remaining' => $remainingAttempts,
        ]);

        return $response;
    }
}
