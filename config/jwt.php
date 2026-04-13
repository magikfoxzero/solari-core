<?php

return [
    /*
    |--------------------------------------------------------------------------
    | JWT Secret Key
    |--------------------------------------------------------------------------
    |
    | This key is used to sign JWT tokens. It should be a separate key from
    | your application key for security purposes. If not set, the application
    | will throw an error in production to prevent using the insecure default.
    |
    | Generate a new key with: php -r "echo base64_encode(random_bytes(32));"
    |
    */
    'secret' => env('JWT_SECRET'),

    /*
    |--------------------------------------------------------------------------
    | JWT Algorithm
    |--------------------------------------------------------------------------
    |
    | The algorithm used to sign JWT tokens. HS256 is the default and
    | recommended for symmetric key signing.
    |
    */
    'algorithm' => env('JWT_ALGORITHM', 'HS256'),

    /*
    |--------------------------------------------------------------------------
    | JWT Expiration Time
    |--------------------------------------------------------------------------
    |
    | The time in seconds that the JWT token will be valid for.
    | Default is 14400 seconds (4 hours). This provides a balance between
    | security and user convenience for typical work sessions.
    |
    | Recommended values:
    | - 3600 (1 hour): High security environments
    | - 14400 (4 hours): Standard business use (recommended)
    | - 28800 (8 hours): Extended work sessions
    |
    */
    'expiration' => (int) env('JWT_EXPIRATION', 14400),

    /*
    |--------------------------------------------------------------------------
    | JWT Issuer
    |--------------------------------------------------------------------------
    |
    | The issuer claim (iss) for the JWT token.
    |
    */
    'issuer' => env('JWT_ISSUER', env('APP_URL', 'webos')),

    /*
    |--------------------------------------------------------------------------
    | Require Separate Secret
    |--------------------------------------------------------------------------
    |
    | When true, the application will throw an error if JWT_SECRET is not set
    | in production. This prevents accidentally using APP_KEY for JWT signing.
    |
    */
    'require_separate_secret' => env('JWT_REQUIRE_SEPARATE_SECRET', true),

    /*
    |--------------------------------------------------------------------------
    | Maximum Refresh Age
    |--------------------------------------------------------------------------
    |
    | The maximum time in seconds from the original login that a token can
    | be refreshed. After this period, users must log in again. This prevents
    | tokens from being refreshed indefinitely.
    | Default is 2592000 seconds (30 days) - similar to social media apps.
    |
    | Recommended values:
    | - 259200 (3 days): High security environments
    | - 2592000 (30 days): Social/consumer apps (recommended)
    | - 7776000 (90 days): Maximum convenience, lower security
    |
    */
    'max_refresh_age' => (int) env('JWT_MAX_REFRESH_AGE', 2592000),

    /*
    |--------------------------------------------------------------------------
    | Sliding Refresh Window
    |--------------------------------------------------------------------------
    |
    | When enabled, max_refresh_age becomes an inactivity timeout rather than
    | a hard session lifetime. Each successful token refresh resets the window,
    | allowing active users to stay logged in indefinitely (up to the absolute
    | max age). When disabled (default), max_refresh_age is measured from the
    | original login time.
    |
    */
    'sliding_refresh' => filter_var(env('JWT_SLIDING_REFRESH', false), FILTER_VALIDATE_BOOLEAN),

    /*
    |--------------------------------------------------------------------------
    | Absolute Maximum Session Age
    |--------------------------------------------------------------------------
    |
    | Only used when sliding_refresh is enabled. This is the hard cap on total
    | session duration measured from the original login, regardless of user
    | activity. Prevents sessions from lasting forever even with continuous use.
    | Default is 31536000 seconds (1 year). Ignored when sliding_refresh is
    | disabled.
    |
    */
    'absolute_max_age' => (int) env('JWT_ABSOLUTE_MAX_AGE', 31536000),

    /*
    |--------------------------------------------------------------------------
    | Cookie Configuration (FE-CRIT-001)
    |--------------------------------------------------------------------------
    |
    | Configuration for httpOnly cookie-based JWT storage.
    | This prevents XSS attacks from stealing tokens as JavaScript cannot
    | access httpOnly cookies.
    |
    | SECURITY: When using cookies, CSRF protection must also be enabled.
    |
    */
    'cookie' => [
        // Cookie name for the JWT access token
        'name' => env('JWT_COOKIE_NAME', 'solari_access_token'),

        // Cookie path - '/' allows the cookie to be sent for all paths
        'path' => '/',

        // Cookie domain - null means current domain only
        // Set explicitly for cross-subdomain auth (e.g., '.example.com')
        'domain' => env('JWT_COOKIE_DOMAIN', null),

        // Secure flag - true requires HTTPS (always true in production)
        'secure' => env('JWT_COOKIE_SECURE', env('APP_ENV') === 'production'),

        // HttpOnly flag - CRITICAL: prevents JavaScript access
        'http_only' => true,

        // SameSite attribute - 'lax' for normal navigation, 'strict' for high security
        // 'lax' allows the cookie on top-level navigations (links)
        // 'strict' blocks the cookie on all cross-site requests
        'same_site' => env('JWT_COOKIE_SAME_SITE', 'lax'),
    ],

    /*
    |--------------------------------------------------------------------------
    | CSRF Cookie Configuration
    |--------------------------------------------------------------------------
    |
    | CSRF token cookie for double-submit cookie pattern.
    | This cookie is readable by JavaScript (not httpOnly) so the frontend
    | can include it in request headers.
    |
    */
    'csrf_cookie' => [
        'name' => env('JWT_CSRF_COOKIE_NAME', 'XSRF-TOKEN'),
        'path' => '/',
        'domain' => env('JWT_COOKIE_DOMAIN', null),
        'secure' => env('JWT_COOKIE_SECURE', env('APP_ENV') === 'production'),
        'http_only' => false, // Must be readable by JavaScript
        'same_site' => env('JWT_COOKIE_SAME_SITE', 'lax'),
    ],

    /*
    |--------------------------------------------------------------------------
    | OIDC Configuration (Identity Service RS256 Tokens)
    |--------------------------------------------------------------------------
    |
    | Configuration for validating RS256 tokens issued by the identity service.
    | These tokens are validated using JWKS public keys fetched from the
    | identity service's well-known endpoint.
    |
    */
    'oidc' => [
        'issuer' => env('OIDC_ISSUER', 'https://auth.solarinet.org'),
        'jwks_uri' => env('OIDC_JWKS_URI', 'http://127.0.0.1:8170/.well-known/jwks.json'),
        'jwks_cache_ttl' => (int) env('OIDC_JWKS_CACHE_TTL', 3600),
    ],
];
