<?php

namespace NewSolari\Core\Security;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class CorsMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @return mixed
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Handle OPTIONS requests immediately for CORS preflight
        if ($request->getMethod() === 'OPTIONS') {
            $response = new Response('', 200);
            $this->addCorsHeaders($response, $request);
            $response->headers->set('Content-Length', '0');

            return $response;
        }

        $response = $next($request);
        $this->addCorsHeaders($response, $request);

        return $response;
    }

    /**
     * Add CORS headers to response.
     *
     * SECURITY: Never reflects arbitrary origins. Always validates against explicit allowlist.
     */
    private function addCorsHeaders(Response $response, ?Request $request = null): void
    {
        // Get configuration values
        $allowedOrigins = config('cors.allowed_origins', []);
        $allowedMethods = config('cors.allowed_methods', ['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS']);
        $allowedHeaders = config('cors.allowed_headers', ['Content-Type', 'Authorization', 'X-Requested-With']);
        $exposedHeaders = config('cors.exposed_headers', []);
        $supportsCredentials = config('cors.supports_credentials', false);
        $maxAge = config('cors.max_age', 86400);

        // Sanitize the origin header to prevent header injection attacks
        $requestOrigin = $request ? $this->sanitizeOrigin($request->header('Origin')) : null;

        // Determine the allowed origin using strict validation
        $origin = $this->determineAllowedOrigin($requestOrigin, $allowedOrigins, $supportsCredentials);

        // If no origin is allowed, don't set CORS headers at all
        // This is more secure than returning 'null' as it completely blocks cross-origin access
        if ($origin === null) {
            return;
        }

        $response->headers->set('Access-Control-Allow-Origin', $origin);
        $response->headers->set('Access-Control-Allow-Methods', implode(', ', $allowedMethods));
        $response->headers->set('Access-Control-Allow-Headers', implode(', ', $allowedHeaders));

        // Set exposed headers if any
        if (! empty($exposedHeaders)) {
            $response->headers->set('Access-Control-Expose-Headers', implode(', ', $exposedHeaders));
        }

        // Only set credentials header when origin is not wildcard and credentials are supported
        if ($origin !== '*' && $supportsCredentials) {
            $response->headers->set('Access-Control-Allow-Credentials', 'true');
        }

        $response->headers->set('Access-Control-Max-Age', (string) $maxAge);

        // Vary header is important when origin can change
        $response->headers->set('Vary', 'Origin');
    }

    /**
     * Determine the allowed origin based on strict allowlist validation.
     * SECURITY: Never reflects arbitrary origins.
     */
    private function determineAllowedOrigin(?string $requestOrigin, array $allowedOrigins, bool $supportsCredentials): ?string
    {
        if (empty($allowedOrigins)) {
            return null;
        }

        $hasWildcard = in_array('*', $allowedOrigins, true);

        // SECURITY: Wildcard with credentials is ALWAYS rejected
        if ($hasWildcard && $supportsCredentials) {
            Log::error('CORS security misconfiguration: Wildcard origins with credentials is forbidden. ' .
                'Configure explicit allowed_origins in config/cors.php');

            return null;
        }

        // Wildcard without credentials - return '*' (not the request origin)
        if ($hasWildcard) {
            return '*';
        }

        // No request origin (same-origin request or non-browser client)
        if ($requestOrigin === null) {
            return null;
        }

        // SECURITY: Strict allowlist validation - exact match
        if (in_array($requestOrigin, $allowedOrigins, true)) {
            return $requestOrigin;
        }

        // Check for pattern-based origins (e.g., https://*.example.com)
        foreach ($allowedOrigins as $allowed) {
            if ($this->originMatchesPattern($requestOrigin, $allowed)) {
                return $requestOrigin;
            }
        }

        // Origin not in allowlist - reject
        return null;
    }

    /**
     * Check if origin matches a pattern with subdomain wildcard.
     * e.g., pattern "https://*.example.com" matches "https://app.example.com"
     */
    private function originMatchesPattern(string $origin, string $pattern): bool
    {
        // Only process patterns containing '*'
        if (strpos($pattern, '*') === false || $pattern === '*') {
            return false;
        }

        // Convert pattern to regex
        $regex = preg_quote($pattern, '/');
        // Replace \* with subdomain pattern (alphanumeric and hyphens)
        $regex = str_replace('\*', '[a-zA-Z0-9-]+', $regex);
        $regex = '/^' . $regex . '$/';

        return (bool) preg_match($regex, $origin);
    }

    /**
     * Sanitize the Origin header to prevent HTTP Response Splitting attacks.
     */
    private function sanitizeOrigin(?string $origin): ?string
    {
        if ($origin === null) {
            return null;
        }

        // Remove any newlines or carriage returns (prevents header injection)
        $origin = str_replace(["\r", "\n", "\t"], '', $origin);

        // Validate it's a proper URL format
        // Origin should be: scheme "://" host [ ":" port ]
        if (! preg_match('/^https?:\/\/[a-zA-Z0-9][-a-zA-Z0-9.]*[a-zA-Z0-9](:\d+)?$/', $origin)) {
            // If it doesn't match expected origin format, reject it
            return null;
        }

        return $origin;
    }
}
