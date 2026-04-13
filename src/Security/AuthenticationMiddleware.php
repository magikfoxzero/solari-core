<?php

namespace NewSolari\Core\Security;

use NewSolari\Core\Identity\Models\IdentityPartition;
use NewSolari\Core\Identity\Models\IdentityUser;
use NewSolari\Core\Identity\UserContext;
use Closure;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\SignatureInvalidException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class AuthenticationMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @return mixed
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Check if this is a public route that doesn't require authentication
        $isPublic = $this->isPublicRoute($request);

        if ($isPublic) {
            return $next($request);
        }

        // FE-CRIT-002: Validate WS token for broadcasting/auth endpoint within middleware
        if ($request->is('api/broadcasting/auth') && $request->header('X-WS-Token')) {
            $wsToken = $request->header('X-WS-Token');
            $tokenData = Cache::get("ws_token:{$wsToken}");
            if ($tokenData) {
                $user = IdentityUser::withoutGlobalScope('partition')->where('record_id', $tokenData['user_id'])->first();
                if ($user) {
                    $request->attributes->set('authenticated_user', $user);
                    $request->attributes->set('partition_id', $tokenData['partition_id'] ?? null);
                    return $next($request);
                }
            }
            // Invalid/expired WS token — fall through to normal JWT auth or reject
        }

        // Check if user was already authenticated via TestAuthenticationMiddleware
        // This only happens in testing environment with proper configuration
        $authenticatedUser = $request->attributes->get('authenticated_user');
        if ($authenticatedUser) {
            // User is already authenticated via test helper
            $partitionId = $request->get('partition_id');

            // If partition_id is not set in request, use the user's default partition
            // BUT only for non-system users. System admins must explicitly choose a partition context.
            if (empty($partitionId) && $authenticatedUser->partition_id && ! $authenticatedUser->is_system_user) {
                $partitionId = $authenticatedUser->partition_id;
                $request->attributes->set('partition_id', $partitionId);
            }

            return $next($request);
        }

        // Check for API key and secret in headers
        $apiKey = $request->header('x-api-key') ?? $request->header('X-API-Key');
        $secretKey = $request->header('x-secret-key') ?? $request->header('X-Secret-Key');
        $partitionId = $request->header('x-partition') ?? $request->header('X-Partition') ?? $request->header('X-Partition-ID');

        // FE-CRIT-001: Check for JWT token in Bearer header first, then httpOnly cookie.
        // Bearer takes priority because Android WebView can retain stale httpOnly cookies
        // after logout/re-login, even when withCredentials=false is set. The Bearer token
        // is always the intentionally-provided token from the client.
        // Security note: On web, no Bearer header is sent (tokens live in httpOnly cookies).
        // CSRF protection is unaffected — it checks cookie presence, not auth method.
        $cookieName = config('jwt.cookie.name', 'solari_access_token');
        $cookieToken = $request->cookie($cookieName);
        $bearerToken = $request->bearerToken();

        $jwtToken = $bearerToken ?? $cookieToken;
        $isJwtAuth = ! empty($jwtToken);

        // Check for rate limiting on authentication endpoints (atomic check + increment)
        if ($this->isAuthenticationEndpoint($request)) {
            if ($this->checkAndIncrementRateLimit($request)) {
                return response()->json([
                    'value' => false,
                    'result' => 'Authentication failed: Too many attempts. Please try again later.',
                    'code' => 429,
                ], 429)->header('Retry-After', 60);
            }
        }

        // Handle JWT token authentication (cookie or bearer)
        // FE-CRIT-001: Cookie auth is preferred for browser clients (httpOnly prevents XSS theft)
        if ($isJwtAuth) {
            // Rate limit token validation attempts to prevent brute force
            if ($this->checkTokenValidationRateLimit($request)) {
                return response()->json([
                    'value' => false,
                    'result' => 'Authentication failed: Too many invalid token attempts. Please try again later.',
                    'code' => 429,
                ], 429)->header('Retry-After', 60);
            }

            // For JWT token authentication, we need to validate the token
            // and extract the user information from it
            try {
                // Check if this is a passkey registration endpoint that accepts limited-purpose tokens
                $allowPurposeTokens = $this->isPasskeyRegistrationEndpoint($request);

                // Validate the JWT token and get the user
                $user = $this->validateBearerToken($jwtToken, $allowPurposeTokens);

                // If partition_id is not provided in header, use the user's default partition
                // BUT only for non-system users. System admins must explicitly choose a partition context.
                if (empty($partitionId) && $user && $user->partition_id && ! $user->is_system_user) {
                    $partitionId = $user->partition_id;
                }

                if (! $user) {
                    return response()->json([
                        'value' => false,
                        'result' => 'Authentication failed: Invalid token',
                        'code' => 401,
                    ], 401);
                }

                // Check if user is active
                if (! $user->is_active) {
                    return response()->json([
                        'value' => false,
                        'result' => 'Authentication failed: Account is inactive',
                        'code' => 403,
                    ], 403);
                }

                // Validate partition if provided
                if ($partitionId) {
                    $partition = IdentityPartition::find($partitionId);

                    if (! $partition) {
                        Log::warning('Authentication attempt with invalid partition', [
                            'user_id' => $user->record_id,
                            'invalid_partition_id' => $partitionId,
                            'path' => $request->path(),
                        ]);

                        return response()->json([
                            'value' => false,
                            'result' => 'Authentication failed: Invalid partition specified',
                            'code' => 401,
                        ], 401);
                    }

                    // Check if user has access to this partition (system users have access to all partitions)
                    if (! $user->is_system_user && $user->partition_id !== $partitionId) {
                        Log::warning('Authentication attempt without partition access', [
                            'user_id' => $user->record_id,
                            'partition_id' => $partitionId,
                            'user_partition_id' => $user->partition_id,
                            'path' => $request->path(),
                        ]);

                        return response()->json([
                            'value' => false,
                            'result' => 'Authentication failed: No access to specified partition',
                            'code' => 403,
                        ], 403);
                    }

                    // Set the partition in the request using attributes (not merge)
                    // to prevent POST body manipulation attacks
                    $request->attributes->set('partition_id', $partitionId);
                }

                // Attach user to request using attributes (not merge)
                // to prevent POST body manipulation attacks
                $request->attributes->set('authenticated_user', $user);

                // Hydrate Auth facade so Auth::user() works throughout the request lifecycle
                // (e.g. in PartitionScope's safety-net check for JWT-authenticated requests)
                if ($user instanceof IdentityUser) {
                    Auth::setUser($user);
                } else {
                    // UserContext doesn't implement Authenticatable, so use request resolver
                    $request->setUserResolver(fn () => $user);
                }

                return $next($request);
            } catch (\Exception $e) {
                // Increment failed token validation attempts
                $this->incrementTokenValidationAttempts($request);

                Log::error('Bearer token authentication failed', [
                    'error' => $e->getMessage(),
                    'path' => $request->path(),
                    'ip' => $request->ip(),
                ]);

                return response()->json([
                    'value' => false,
                    'result' => 'Authentication failed: Invalid token',
                    'code' => 401,
                ], 401);
            }
        }

        // Validate required headers for API key authentication
        if (! $apiKey || ! $secretKey) {
            // Increment failed attempt counter for rate limiting
            if ($this->isAuthenticationEndpoint($request)) {
                $this->incrementAuthAttempts($request);
            }

            return response()->json([
                'value' => false,
                'result' => 'Authentication failed: Invalid credentials',
                'code' => 401,
            ], 401);
        }

        // Rate limiting is handled atomically at the beginning of the request
        // No duplicate check needed here

        // Find the user by API key - bypass partition scope since auth happens before partition context
        $user = IdentityUser::withoutGlobalScope('partition')
            ->where('username', $apiKey)
            ->first();

        if (! $user) {
            // Increment failed attempt counter for rate limiting
            if ($this->isAuthenticationEndpoint($request)) {
                $this->incrementAuthAttempts($request);
            }

            return response()->json([
                'value' => false,
                'result' => 'Authentication failed: Invalid credentials',
                'code' => 401,
            ], 401);
        }

        // Verify the secret key (password)
        if (! $user->authenticate($secretKey)) {
            // Increment failed attempt counter for rate limiting
            if ($this->isAuthenticationEndpoint($request)) {
                $this->incrementAuthAttempts($request);
            }

            return response()->json([
                'value' => false,
                'result' => 'Authentication failed: Invalid credentials',
                'code' => 401,
            ], 401);
        }

        // Check if user is active
        if (! $user->is_active) {
            return response()->json([
                'value' => false,
                'result' => 'Authentication failed: Account is inactive',
                'code' => 403,
            ], 403);
        }

        // Validate partition if provided
        if ($partitionId) {
            $partition = IdentityPartition::find($partitionId);

            if (! $partition) {
                Log::warning('Authentication attempt with invalid partition', [
                    'user_id' => $user->record_id,
                    'username' => $user->username,
                    'invalid_partition_id' => $partitionId,
                    'path' => $request->path(),
                    'ip' => $request->ip(),
                ]);

                return response()->json([
                    'value' => false,
                    'result' => 'Authentication failed: Invalid partition specified',
                    'code' => 401,
                ], 401);
            }

            // Check if user has access to this partition (system users have access to all partitions)
            if (! $user->is_system_user && ! $user->partitions()->where('partition_id', $partitionId)->exists()) {
                Log::warning('Authentication attempt without partition access', [
                    'user_id' => $user->record_id,
                    'username' => $user->username,
                    'partition_id' => $partitionId,
                    'user_partitions' => $user->partitions->pluck('record_id')->toArray(),
                    'path' => $request->path(),
                    'ip' => $request->ip(),
                ]);

                return response()->json([
                    'value' => false,
                    'result' => 'Authentication failed: No access to specified partition',
                    'code' => 403,
                ], 403);
            }

            // Set the validated partition in the request using attributes (not merge)
            // to prevent POST body manipulation attacks
            $request->attributes->set('partition_id', $partitionId);
        }

        // SECURITY: Also validate any partition_id in the request body
        // This prevents privilege escalation via body parameter injection
        $requestBodyPartitionId = $request->input('partition_id');
        if ($requestBodyPartitionId && ! $user->is_system_user) {
            // If partition_id is in the body, it MUST be in user's allowed partitions
            if (! $user->partitions()->where('partition_id', $requestBodyPartitionId)->exists()) {
                Log::warning('Attempted partition access via request body denied', [
                    'user_id' => $user->record_id,
                    'username' => $user->username,
                    'requested_partition_id' => $requestBodyPartitionId,
                    'allowed_partitions' => $user->partitions->pluck('record_id')->toArray(),
                    'path' => $request->path(),
                    'ip' => $request->ip(),
                ]);

                return response()->json([
                    'value' => false,
                    'result' => 'Access denied: No permission to access specified partition',
                    'code' => 403,
                ], 403);
            }

            // SECURITY: Ensure body partition_id matches header partition_id to prevent context confusion attacks
            if (! empty($partitionId) && $requestBodyPartitionId !== $partitionId) {
                Log::warning('Partition ID mismatch between header and body', [
                    'user_id' => $user->record_id,
                    'header_partition' => $partitionId,
                    'body_partition' => $requestBodyPartitionId,
                    'path' => $request->path(),
                    'ip' => $request->ip(),
                ]);

                return response()->json([
                    'value' => false,
                    'result' => 'Partition ID mismatch between header and request body',
                    'code' => 400,
                ], 400);
            }
        }

        // Attach user to request using attributes (not merge)
        // to prevent POST body manipulation attacks
        $request->attributes->set('authenticated_user', $user);

        // Hydrate Auth facade so Auth::user() works throughout the request lifecycle
        Auth::setUser($user);

        Log::info('Authentication successful', [
            'user_id' => $user->record_id,
            'username' => $user->username,
            'partition_id' => $partitionId,
            'path' => $request->path(),
        ]);

        return $next($request);
    }

    /**
     * Check if the request is for a public route that doesn't require authentication.
     */
    private function isPublicRoute(Request $request): bool
    {
        $publicRoutes = [
            '/api/system/status',
            '/api/system/health',
            '/api/auth/login',
            '/api/auth/refresh',
        ];

        foreach ($publicRoutes as $route) {
            if ($request->is($route)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if the request is for an authentication endpoint.
     */
    private function isAuthenticationEndpoint(Request $request): bool
    {
        $authEndpoints = [
            '/api/auth/login',
            '/api/auth/refresh',
        ];

        foreach ($authEndpoints as $endpoint) {
            if ($request->is($endpoint)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if rate limit exceeded and increment counter atomically.
     * Returns true if rate limit exceeded, false otherwise.
     *
     * @return bool True if rate limit exceeded
     */
    private function checkAndIncrementRateLimit(Request $request): bool
    {
        $rateLimitKey = 'auth_attempts_'.$request->ip();
        $maxAttempts = 5;

        // Use atomic increment - if key doesn't exist, it starts at 0 and increments to 1
        // This prevents race conditions between check and increment
        $attempts = Cache::increment($rateLimitKey);

        // Set expiry on first attempt (increment returns 1 for new keys)
        if ($attempts === 1) {
            Cache::put($rateLimitKey, 1, 60); // Reset TTL to 1 minute
        }

        if ($attempts > $maxAttempts) {
            Log::warning('Rate limit exceeded for authentication', [
                'ip' => $request->ip(),
                'attempts' => $attempts,
                'path' => $request->path(),
                'timestamp' => now()->toDateTimeString(),
            ]);

            return true;
        }

        return false;
    }

    /**
     * Log failed authentication attempt (for auditing without incrementing counter).
     */
    private function logFailedAuthAttempt(Request $request): void
    {
        $rateLimitKey = 'auth_attempts_'.$request->ip();
        $attempts = Cache::get($rateLimitKey, 0);

        Log::warning('Failed authentication attempt', [
            'ip' => $request->ip(),
            'attempts' => $attempts,
            'path' => $request->path(),
            'timestamp' => now()->toDateTimeString(),
        ]);
    }

    /**
     * @deprecated Use checkAndIncrementRateLimit() instead
     * Increment the authentication attempt counter for rate limiting.
     */
    private function incrementAuthAttempts(Request $request): void
    {
        // For backward compatibility, just log - rate limiting is now handled atomically
        $this->logFailedAuthAttempt($request);
    }

    /**
     * Check if token validation rate limit has been exceeded.
     * Uses a separate counter from authentication attempts to track
     * invalid bearer tokens across all protected endpoints.
     *
     * @return bool True if rate limit exceeded
     */
    private function checkTokenValidationRateLimit(Request $request): bool
    {
        $rateLimitKey = 'token_validation_'.$request->ip();
        // Higher limit for token validation - tokens can fail for legitimate reasons
        // (expired tokens, page refreshes, multiple tabs, network issues)
        $maxAttempts = 100;

        $attempts = Cache::get($rateLimitKey, 0);

        if ($attempts >= $maxAttempts) {
            Log::warning('Token validation rate limit exceeded', [
                'ip' => $request->ip(),
                'attempts' => $attempts,
                'path' => $request->path(),
                'timestamp' => now()->toDateTimeString(),
            ]);

            return true;
        }

        return false;
    }

    /**
     * Increment the token validation attempt counter on failure.
     */
    private function incrementTokenValidationAttempts(Request $request): void
    {
        $rateLimitKey = 'token_validation_'.$request->ip();

        // Atomic increment
        $attempts = Cache::increment($rateLimitKey);

        // Set expiry on first attempt
        if ($attempts === 1) {
            Cache::put($rateLimitKey, 1, 300); // 5 minute window
        }

        Log::info('Token validation attempt failed', [
            'ip' => $request->ip(),
            'attempts' => $attempts,
            'max_attempts' => 100, // Must match checkTokenValidationRateLimit threshold
            'path' => $request->path(),
        ]);
    }

    /**
     * Check if the request is for a passkey registration endpoint.
     * These endpoints accept limited-purpose tokens (recovery, passkey_registration).
     */
    private function isPasskeyRegistrationEndpoint(Request $request): bool
    {
        $passkeyRegistrationPaths = [
            'api/passkeys/register/options',
            'api/passkeys/register/verify',
        ];

        foreach ($passkeyRegistrationPaths as $path) {
            if ($request->is($path)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Validate Bearer token (JWT) and return the authenticated user.
     * Uses firebase/php-jwt for proper JWT validation including expiration.
     *
     * Supports dual token validation:
     * - RS256 with kid header → OIDC token from identity service → returns UserContext
     * - HS512/HS256 without kid → legacy shared secret → returns IdentityUser
     * - Any other algorithm → rejected (algorithm confusion protection)
     *
     * @param  string  $token  The JWT token to validate
     * @param  bool  $allowPurposeTokens  Whether to allow tokens with a 'purpose' claim
     */
    protected function validateBearerToken(string $token, bool $allowPurposeTokens = false): IdentityUser|UserContext|null
    {
        try {
            // Check if this is a valid JWT token format (must have at least header.payload.signature)
            if (substr_count($token, '.') !== 2) {
                return null;
            }

            // SECURITY: Decode the JWT header to determine algorithm BEFORE validation.
            // This prevents algorithm confusion attacks where an attacker crafts a token
            // with alg=HS256 using the public key as the HMAC secret.
            $header = $this->decodeJwtHeader($token);
            if (! $header) {
                return null;
            }

            $alg = $header['alg'] ?? null;
            $kid = $header['kid'] ?? null;

            // Route to the appropriate validation path based on algorithm + kid presence
            if ($alg === 'RS256' && $kid !== null) {
                return $this->validateOidcToken($token, $allowPurposeTokens);
            }

            if (in_array($alg, ['HS512', 'HS256'], true) && $kid === null) {
                return $this->validateLegacyToken($token, $allowPurposeTokens);
            }

            // Reject any other algorithm (none, HS384, RS384, etc.)
            Log::warning('JWT with disallowed algorithm rejected', [
                'alg' => $alg,
                'has_kid' => $kid !== null,
            ]);

            return null;

        } catch (ExpiredException $e) {
            Log::info('JWT token expired');

            // Do NOT clean up push subscriptions here. Token expiry is a normal event
            // in the JWT refresh lifecycle — the frontend will refresh via /auth/refresh.
            // Push cleanup is handled by:
            // 1. Explicit logout (IdentityController::logout)
            // 2. Refresh rejection when session truly expires (max_refresh_age exceeded)
            // 3. Scheduled push:cleanup command for crash/uninstall scenarios

            return null;
        } catch (SignatureInvalidException $e) {
            Log::warning('JWT signature invalid');

            return null;
        } catch (\Exception $e) {
            Log::error('JWT validation failed', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Decode the JWT header without verifying the signature.
     * Used to determine the algorithm before routing to the correct validator.
     *
     * @return array|null The decoded header, or null if invalid
     */
    private function decodeJwtHeader(string $token): ?array
    {
        $parts = explode('.', $token);
        $headerJson = base64_decode(strtr($parts[0], '-_', '+/'), true);
        if ($headerJson === false) {
            return null;
        }

        $header = json_decode($headerJson, true);
        if (! is_array($header)) {
            return null;
        }

        return $header;
    }

    /**
     * Validate an OIDC RS256 token from the identity service.
     * Returns a UserContext (no database query needed — all data is in the JWT claims).
     */
    private function validateOidcToken(string $token, bool $allowPurposeTokens): ?UserContext
    {
        $keys = $this->getJwksKeys();
        if (empty($keys)) {
            Log::error('OIDC validation failed: unable to fetch JWKS keys');

            return null;
        }

        // JWT::decode will match the kid from the token header against the key set
        $decoded = JWT::decode($token, $keys);
        $payload = (array) $decoded;

        // Verify required claims
        if (! isset($payload['sub']) || ! isset($payload['exp'])) {
            Log::warning('OIDC JWT missing required claims', [
                'has_sub' => isset($payload['sub']),
                'has_exp' => isset($payload['exp']),
            ]);

            return null;
        }

        // Verify issuer matches expected OIDC issuer (reject tokens missing iss entirely)
        $expectedIssuer = config('jwt.oidc.issuer');
        if (!isset($payload['iss']) || $payload['iss'] !== $expectedIssuer) {
            Log::warning('OIDC JWT issuer missing or mismatched', [
                'expected' => $expectedIssuer,
                'actual' => $payload['iss'] ?? 'missing',
            ]);

            return null;
        }

        // SECURITY: Reject purpose tokens for general API access
        if (isset($payload['purpose']) && ! $allowPurposeTokens) {
            Log::warning('OIDC JWT with limited purpose rejected for general API access', [
                'purpose' => $payload['purpose'],
                'jti' => $payload['jti'] ?? 'unknown',
            ]);

            return null;
        }

        // Check token blacklist (shared Redis, written by identity service)
        if (isset($payload['jti'])) {
            $blacklistKey = 'jwt_blacklist_' . $payload['jti'];
            if (Cache::has($blacklistKey)) {
                Log::info('OIDC JWT token is blacklisted', ['jti' => $payload['jti']]);

                return null;
            }
        }

        return UserContext::fromJwtClaims($decoded);
    }

    /**
     * Validate a legacy HS512/HS256 token signed with the shared secret.
     * Returns an IdentityUser (fetched from database by user_id claim).
     */
    private function validateLegacyToken(string $token, bool $allowPurposeTokens): ?IdentityUser
    {
        // Decode and validate JWT using firebase/php-jwt
        // This automatically validates signature, expiration (exp), and not-before (nbf) claims
        $decoded = JWT::decode($token, new Key(IdentityUser::getJwtSecret(), config('jwt.algorithm', 'HS256')));

        // Convert to array for easier access
        $payload = (array) $decoded;

        // Verify required claims exist
        if (! isset($payload['sub']) || ! isset($payload['exp'])) {
            Log::warning('JWT missing required claims', ['has_sub' => isset($payload['sub']), 'has_exp' => isset($payload['exp'])]);

            return null;
        }

        // SECURITY: Reject tokens with a 'purpose' claim for general API access
        // These are limited-purpose tokens (e.g., recovery, passkey_registration) that
        // should only be accepted by specific endpoints (like passkey registration)
        if (isset($payload['purpose'])) {
            // Allow purpose tokens only for specific endpoints (e.g., passkey registration)
            if (! $allowPurposeTokens) {
                Log::warning('JWT with limited purpose rejected for general API access', [
                    'purpose' => $payload['purpose'],
                    'jti' => $payload['jti'] ?? 'unknown',
                ]);

                return null;
            }

            // Validate the purpose is one we accept
            $allowedPurposes = ['recovery', 'passkey_registration'];
            if (! in_array($payload['purpose'], $allowedPurposes, true)) {
                Log::warning('JWT with unknown purpose rejected', [
                    'purpose' => $payload['purpose'],
                    'jti' => $payload['jti'] ?? 'unknown',
                ]);

                return null;
            }

            Log::debug('Limited purpose token accepted for passkey registration', [
                'purpose' => $payload['purpose'],
                'jti' => $payload['jti'] ?? 'unknown',
            ]);
        }

        // Check if token is blacklisted (logout)
        // The blacklist stores `true` at key `jwt_blacklist_{jti}`, so existence = blacklisted.
        if (isset($payload['jti'])) {
            $blacklistKey = 'jwt_blacklist_'.$payload['jti'];
            if (Cache::has($blacklistKey)) {
                Log::info('JWT token is blacklisted', ['jti' => $payload['jti']]);

                return null;
            }
        }

        // Find user by user_id claim (unique across all partitions)
        // Bypass partition scope since auth happens before partition context is established
        $user = IdentityUser::withoutGlobalScope('partition')
            ->where('record_id', $payload['user_id'])
            ->first();

        if (! $user) {
            return null;
        }

        // Verify partition access if specified in token
        if (isset($payload['partition_id']) && ! $user->is_system_user) {
            if ($user->partition_id !== $payload['partition_id']) {
                return null;
            }
        }

        return $user;
    }

    /**
     * Fetch and cache JWKS public keys for OIDC token validation.
     *
     * Uses a three-tier cache strategy for resilience:
     * 1. Redis cache (fast, 1 hour TTL)
     * 2. Disk cache (survives Redis restart, no TTL)
     * 3. HTTP fetch from JWKS endpoint (source of truth)
     *
     * @return array<string, Key> Parsed key set for JWT::decode()
     */
    private function getJwksKeys(): array
    {
        $cacheTtl = config('jwt.oidc.jwks_cache_ttl', 3600);
        $cacheKey = 'oidc_jwks_keys';
        $diskPath = storage_path('framework/cache/jwks.json');

        // Tier 1: Redis cache
        $jwksJson = Cache::get($cacheKey);
        if ($jwksJson) {
            try {
                $jwks = json_decode($jwksJson, true);

                return JWK::parseKeySet($jwks);
            } catch (\Exception $e) {
                Log::warning('Failed to parse cached JWKS, will refetch', ['error' => $e->getMessage()]);
                Cache::forget($cacheKey);
            }
        }

        // Tier 2: Disk cache (cold-start resilience, HMAC-verified)
        $hmacPath = $diskPath . '.hmac';
        if (file_exists($diskPath) && file_exists($hmacPath)) {
            try {
                $diskJson = file_get_contents($diskPath);
                $storedHmac = file_get_contents($hmacPath);
                $expectedHmac = hash_hmac('sha256', $diskJson, config('app.key'));

                if (! hash_equals($expectedHmac, $storedHmac)) {
                    Log::warning('JWKS disk cache HMAC verification failed — possible tampering, will refetch');
                } else {
                    $jwks = json_decode($diskJson, true);
                    $keys = JWK::parseKeySet($jwks);

                    // Restore to Redis
                    Cache::put($cacheKey, $diskJson, $cacheTtl);

                    return $keys;
                }
            } catch (\Exception $e) {
                Log::warning('Failed to parse disk-cached JWKS, will refetch', ['error' => $e->getMessage()]);
            }
        }

        // Tier 3: HTTP fetch from JWKS endpoint
        try {
            $jwksUri = config('jwt.oidc.jwks_uri');
            if (! $jwksUri) {
                Log::error('OIDC JWKS URI not configured');

                return [];
            }

            $response = \Illuminate\Support\Facades\Http::timeout(5)
                ->retry(2, 100)
                ->get($jwksUri);

            if (! $response->successful()) {
                Log::error('Failed to fetch JWKS', [
                    'uri' => $jwksUri,
                    'status' => $response->status(),
                ]);

                return [];
            }

            $jwksJson = $response->body();
            $jwks = json_decode($jwksJson, true);

            if (! is_array($jwks) || ! isset($jwks['keys'])) {
                Log::error('Invalid JWKS response format', ['uri' => $jwksUri]);

                return [];
            }

            $keys = JWK::parseKeySet($jwks);

            // Cache in both Redis and disk
            Cache::put($cacheKey, $jwksJson, $cacheTtl);

            $diskDir = dirname($diskPath);
            if (! is_dir($diskDir)) {
                mkdir($diskDir, 0755, true);
            }
            file_put_contents($diskPath, $jwksJson);
            file_put_contents($diskPath . '.hmac', hash_hmac('sha256', $jwksJson, config('app.key')));

            return $keys;
        } catch (\Exception $e) {
            Log::error('Failed to fetch or parse JWKS', [
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }
}
