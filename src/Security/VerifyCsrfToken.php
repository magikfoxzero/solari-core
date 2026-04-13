<?php

namespace NewSolari\Core\Security;

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken as LaravelVerifyCsrfToken;

/**
 * CSRF middleware extending Laravel's built-in VerifyCsrfToken.
 *
 * Laravel's middleware handles:
 * - Encrypting/decrypting the XSRF-TOKEN cookie and X-XSRF-TOKEN header
 * - Setting the XSRF-TOKEN cookie on responses
 * - Skipping safe methods (GET, HEAD, OPTIONS)
 *
 * We override shouldPassThrough() to also skip requests that use
 * Bearer token auth (no cookie = no CSRF needed).
 */
class VerifyCsrfToken extends LaravelVerifyCsrfToken
{
    /**
     * Determine if the request has a URI that should pass through CSRF verification.
     */
    protected function isReading($request): bool
    {
        // Let parent handle safe methods
        if (parent::isReading($request)) {
            return true;
        }

        // No cookie auth = no CSRF needed (unauthenticated, API client, or native app)
        $cookieName = config('jwt.cookie.name', 'solari_access_token');
        if (! $request->cookie($cookieName)) {
            return true;
        }

        return false;
    }
}
