<?php

namespace NewSolari\Core\Security;

use Closure;
use Illuminate\Cookie\CookieValuePrefix;
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
 * sends it as the X-XSRF-TOKEN header. This middleware decrypts both and compares.
 */
class VerifyCsrfToken
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($this->shouldSkip($request)) {
            return $next($request);
        }

        $cookieToken = $this->getTokenFromCookie($request);
        $headerToken = $this->getTokenFromHeader($request);

        if (! $cookieToken || ! $headerToken) {
            return $this->reject($request, 'missing');
        }

        if (! hash_equals($cookieToken, $headerToken)) {
            return $this->reject($request, 'mismatch');
        }

        return $next($request);
    }

    /**
     * Get the decrypted CSRF token from the cookie.
     * EncryptCookies middleware has already decrypted it.
     */
    private function getTokenFromCookie(Request $request): ?string
    {
        $name = config('jwt.csrf_cookie.name', 'XSRF-TOKEN');

        return $request->cookie($name);
    }

    /**
     * Get the CSRF token from the X-XSRF-TOKEN header.
     * The header contains the encrypted value that JavaScript read from document.cookie.
     * We must decrypt it to compare against the decrypted cookie value.
     */
    private function getTokenFromHeader(Request $request): ?string
    {
        $header = $request->header('X-XSRF-TOKEN');
        if (! $header) {
            return null;
        }

        try {
            $encrypter = App::make('encrypter');
            $value = $encrypter->decrypt($header, false);

            // Remove Laravel's cookie value prefix if present
            if (class_exists(CookieValuePrefix::class)) {
                $value = CookieValuePrefix::remove($value);
            }

            return $value;
        } catch (\Exception) {
            // If decryption fails, try using the raw value (unencrypted cookie scenario)
            return $header;
        }
    }

    private function shouldSkip(Request $request): bool
    {
        if (in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'])) {
            return true;
        }

        $cookieName = config('jwt.cookie.name', 'solari_access_token');
        if (! $request->cookie($cookieName)) {
            return true;
        }

        return false;
    }

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
