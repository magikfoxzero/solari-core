<?php

namespace NewSolari\Core\Security;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * CSRF validation using the double-submit cookie pattern.
 *
 * Compares the XSRF-TOKEN cookie value with the X-XSRF-TOKEN header.
 * Only enforced for state-changing requests (POST/PUT/PATCH/DELETE)
 * that are authenticated via httpOnly cookie (not Bearer token).
 *
 * Naturally exempt:
 * - GET/HEAD/OPTIONS (safe methods)
 * - Requests without solari_access_token cookie (unauthenticated, webhooks, native apps)
 */
class VerifyCsrfToken
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($this->shouldSkip($request)) {
            return $next($request);
        }

        $csrfCookieName = config('jwt.csrf_cookie.name', 'XSRF-TOKEN');
        $headerToken = $request->header('X-XSRF-TOKEN');

        // Try Laravel's decrypted cookie first (same-app cookies)
        $cookieToken = $request->cookie($csrfCookieName);

        // If decryption failed (cross-service cookie with different encryption context),
        // read the raw value from $_COOKIE or from the raw Cookie header.
        // The XSRF-TOKEN is intentionally NOT httpOnly (readable by JavaScript)
        // and is NOT a secret — the double-submit pattern only requires that the
        // cookie value matches the header value.
        if (! $cookieToken) {
            // Try $_COOKIE (production: populated by PHP from the HTTP Cookie header)
            $cookieToken = $_COOKIE[$csrfCookieName] ?? null;
        }
        if (! $cookieToken) {
            // Fallback: parse raw Cookie header (covers test environment + edge cases)
            $rawCookies = $request->header('Cookie', '');
            if (preg_match('/(?:^|;\s*)' . preg_quote($csrfCookieName, '/') . '=([^;]+)/', $rawCookies, $matches)) {
                $cookieToken = urldecode($matches[1]);
            }
        }

        if (! $cookieToken || ! $headerToken) {
            return $this->reject($request, 'missing');
        }

        // Normalize: urldecode both values for consistent comparison
        if (! hash_equals(urldecode($cookieToken), urldecode($headerToken))) {
            return $this->reject($request, 'mismatch');
        }

        return $next($request);
    }

    /**
     * Determine if CSRF validation should be skipped for this request.
     */
    private function shouldSkip(Request $request): bool
    {
        // Safe methods don't need CSRF protection
        if (in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'])) {
            return true;
        }

        // No cookie auth = no CSRF needed (unauthenticated, API client, or native app)
        // Note: we intentionally do NOT skip based on Bearer token presence alone,
        // because an attacker could add a dummy Authorization header to bypass CSRF.
        // Native apps never send cookies (withCredentials=false), so they hit this check.
        $cookieName = config('jwt.cookie.name', 'solari_access_token');
        if (! $request->cookie($cookieName)) {
            return true;
        }

        return false;
    }

    /**
     * Return a 403 response and log the CSRF failure.
     */
    private function reject(Request $request, string $reason): JsonResponse
    {
        Log::warning('CSRF validation failed', [
            'reason' => $reason,
            'ip' => $request->ip(),
            'url' => $request->fullUrl(),
            'method' => $request->method(),
        ]);

        return response()->json([
            'value' => false,
            'result' => 'CSRF token validation failed',
            'code' => 403,
        ], 403);
    }
}
