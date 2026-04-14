<?php

namespace NewSolari\Core\Security;

use Closure;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * CSRF validation using the double-submit cookie pattern.
 *
 * The XSRF-TOKEN cookie is encrypted by Laravel's EncryptCookies middleware.
 * The browser's JavaScript reads the encrypted value from document.cookie and
 * sends it as the X-XSRF-TOKEN header. This middleware decrypts both the cookie
 * (via EncryptCookies) and the header (explicitly) before comparing.
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

        // EncryptCookies middleware already decrypted the cookie
        $cookieToken = $request->cookie($csrfCookieName);

        // The header contains the encrypted blob that JavaScript read from document.cookie.
        // We must decrypt it to compare against the decrypted cookie value.
        $headerToken = $this->decryptHeader($request->header('X-XSRF-TOKEN'));

        if (! $cookieToken || ! $headerToken) {
            return $this->reject($request, 'missing');
        }

        if (! hash_equals($cookieToken, $headerToken)) {
            return $this->reject($request, 'mismatch');
        }

        return $next($request);
    }

    /**
     * Decrypt the X-XSRF-TOKEN header value.
     * Matches Laravel's built-in VerifyCsrfToken behavior (framework line 155).
     */
    private function decryptHeader(?string $header): ?string
    {
        if (! $header) {
            return null;
        }

        try {
            $value = App::make('encrypter')->decrypt($header, false);

            // Strip Laravel's cookie value prefix if present
            if (class_exists(\Illuminate\Cookie\CookieValuePrefix::class)) {
                $value = \Illuminate\Cookie\CookieValuePrefix::remove($value);
            }

            return $value;
        } catch (DecryptException) {
            return null;
        }
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
