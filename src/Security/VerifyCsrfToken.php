<?php

namespace NewSolari\Core\Security;

use Closure;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
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
        // $request->cookie() returns the decrypted value (via EncryptCookies middleware)
        $cookieToken = $request->cookie($csrfCookieName);
        $headerToken = $request->header('X-XSRF-TOKEN');

        if (! $cookieToken || ! $headerToken) {
            return $this->reject($request, 'missing');
        }

        // The header contains the encrypted cookie value that JavaScript read from document.cookie.
        // Laravel's EncryptCookies encrypts cookies before sending them to the browser, so
        // document.cookie returns the encrypted blob. We must decrypt the header to compare
        // against the decrypted cookie value (same approach as Laravel's built-in VerifyCsrfToken).
        try {
            $decryptedHeader = Crypt::decrypt($headerToken, false);
        } catch (DecryptException) {
            // If decryption fails, try direct comparison (unencrypted cookie scenario)
            $decryptedHeader = $headerToken;
        }

        if (! hash_equals($cookieToken, $decryptedHeader)) {
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
