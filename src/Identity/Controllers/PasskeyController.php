<?php

namespace NewSolari\Core\Identity\Controllers;

use NewSolari\Core\Http\BaseController;

use NewSolari\Core\Constants\ApiConstants;
use NewSolari\Core\Identity\Models\IdentityUser;
use NewSolari\Core\Services\PasskeyService;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\SignatureInvalidException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PasskeyController extends BaseController
{
    public function __construct(
        private readonly PasskeyService $passkeyService
    ) {}

    /**
     * Get passkey configuration.
     *
     * GET /api/passkeys/config
     *
     * @unauthenticated
     */
    public function getConfig(): JsonResponse
    {
        return $this->successResponse($this->passkeyService->getConfig());
    }

    /**
     * Get registration options for passkey creation.
     *
     * POST /api/passkeys/register/options
     *
     * Accepts:
     * - Regular authenticated users (full JWT token)
     * - Users with limited-purpose tokens (recovery, passkey_registration)
     */
    public function registerOptions(Request $request): JsonResponse
    {
        if (!$this->passkeyService->isEnabled()) {
            return $this->errorResponse('Passkeys are disabled', 400);
        }

        // Try to get user from regular auth first, then from limited purpose token
        $user = $this->getUserFromRequest($request);

        if (!$user) {
            return $this->errorResponse('Unauthenticated', 401);
        }

        try {
            $options = $this->passkeyService->createRegistrationOptions($user);

            Log::debug('Passkey registration options created', [
                'user_id' => $user->record_id,
                'rp_id' => $options['rp']['id'] ?? 'unknown',
                'request_host' => $request->getHost(),
            ]);

            return $this->successResponse($options);
        } catch (\Exception $e) {
            Log::error('Failed to create passkey registration options', [
                'user_id' => $user->record_id,
                'error' => $e->getMessage(),
            ]);
            return $this->errorResponse('Failed to create registration options', 500);
        }
    }

    /**
     * Verify registration response and store passkey.
     *
     * POST /api/passkeys/register/verify
     *
     * Accepts:
     * - Regular authenticated users (full JWT token) - returns passkey info only
     * - Users with limited-purpose tokens (recovery) - returns passkey info + full auth token
     */
    public function registerVerify(Request $request): JsonResponse
    {
        if (!$this->passkeyService->isEnabled()) {
            return $this->errorResponse('Passkeys are disabled', 400);
        }

        // Try to get user from regular auth first, then from limited purpose token
        $userResult = $this->getUserFromRequestWithPurpose($request);
        $user = $userResult['user'];
        $tokenPurpose = $userResult['purpose'];

        if (!$user) {
            return $this->errorResponse('Unauthenticated', 401);
        }

        $validated = $request->validate([
            'credential' => 'required|array',
            'device_name' => 'nullable|string|max:' . ApiConstants::STRING_MAX_LENGTH,
        ]);

        try {
            $passkey = $this->passkeyService->verifyRegistration(
                $user,
                $validated['credential'],
                $validated['device_name'] ?? null
            );

            // If this was a recovery flow or new registration, issue a full authentication token
            if ($tokenPurpose === 'recovery' || $tokenPurpose === 'passkey_registration') {
                // Record successful login (recovery complete)
                $user->recordSuccessfulLogin();

                // Generate full JWT token
                $token = $this->generateJwtToken($user);

                // Load groups and permissions
                $user->load('groups.permissions');

                // For new registrations, send verification email now (deferred from registration)
                // BUT only if allow_unverified_login is disabled - otherwise defer to banner click
                $allowUnverifiedLogin = $this->getAllowUnverifiedLoginSetting();
                Log::debug('Passkey registration email check', [
                    'token_purpose' => $tokenPurpose,
                    'requires_email_verification' => $user->requires_email_verification,
                    'has_email' => !empty($user->email),
                    'email' => $user->email,
                    'user_id' => $user->record_id,
                    'allow_unverified_login' => $allowUnverifiedLogin,
                ]);
                if ($tokenPurpose === 'passkey_registration' && $user->requires_email_verification && $user->email && !$allowUnverifiedLogin) {
                    try {
                        $emailService = app(\NewSolari\Core\Services\EmailSecurityService::class);
                        $emailService->sendVerificationEmail($user);
                        Log::info('Verification email sent after passkey registration', [
                            'user_id' => $user->record_id,
                            'email' => $user->email,
                        ]);
                    } catch (\Exception $e) {
                        Log::error('Failed to send verification email after passkey registration', [
                            'user_id' => $user->record_id,
                            'error' => $e->getMessage(),
                        ]);
                        // Don't fail - user can request resend
                    }
                }

                Log::info('Account recovery completed - passkey registered', [
                    'user_id' => $user->record_id,
                    'username' => $user->username,
                    'ip' => $request->ip(),
                ]);

                $expiresIn = (int) config('jwt.expiration', ApiConstants::JWT_EXPIRATION_SECONDS);
                $maxRefreshAge = (int) config('jwt.max_refresh_age', ApiConstants::JWT_MAX_REFRESH_SECONDS);
                $slidingRefresh = (bool) config('jwt.sliding_refresh', false);

                // Check if email verification is pending
                $requiresEmailVerification = $user->requires_email_verification && !$user->email_verified_at;

                // Build response with full auth token
                $response = $this->successResponse([
                    'success' => true,
                    'passkey' => $passkey->toApiArray(),
                    'recovery_complete' => true,
                    'user' => $user,
                    'token' => $token,
                    'token_type' => 'bearer',
                    'expires_in' => $expiresIn,
                    'max_refresh_age' => $maxRefreshAge,
                    'sliding_refresh' => $slidingRefresh,
                    'absolute_max_age' => $slidingRefresh ? (int) config('jwt.absolute_max_age', 31536000) : null,
                    'admin_partition_ids' => $user->getAdminPartitionIds(),
                    'requires_email_verification' => $requiresEmailVerification,
                ]);

                // Attach httpOnly cookie for the full auth token (use max_refresh_age for cookie lifetime)
                return $this->attachAuthCookies($response, $token, $maxRefreshAge);
            }

            // Regular passkey registration - just return passkey info
            return $this->successResponse([
                'success' => true,
                'passkey' => $passkey->toApiArray(),
            ]);
        } catch (\Exception $e) {
            Log::error('Passkey registration failed', [
                'user_id' => $user->record_id,
                'error' => $e->getMessage(),
            ]);
            return $this->errorResponse('Registration failed: ' . $e->getMessage(), 400);
        }
    }

    /**
     * Get authentication options for passkey login.
     *
     * POST /api/passkeys/authenticate/options
     *
     * @unauthenticated
     */
    public function authenticateOptions(Request $request): JsonResponse
    {
        if (!$this->passkeyService->isEnabled()) {
            return $this->errorResponse('Passkeys are disabled', 400);
        }

        $validated = $request->validate([
            'username' => 'nullable|string|max:' . ApiConstants::STRING_MAX_LENGTH,
        ]);

        // Check for orphan accounts when username is provided
        if (!empty($validated['username'])) {
            $user = IdentityUser::withoutGlobalScope('partition')
                ->where('username', $validated['username'])
                ->first();

            if ($user && $user->isOrphanAccount()) {
                Log::info('Orphan account detected during passkey authentication', [
                    'username' => $validated['username'],
                    'user_id' => $user->record_id,
                    'ip' => $request->ip(),
                ]);

                return $this->errorResponse(
                    'Your account registration was not completed. Please use account recovery to set up your passkey.',
                    401,
                    ['orphan_account' => true, 'email' => $user->email]
                );
            }
        }

        try {
            $options = $this->passkeyService->createAuthenticationOptions(
                $validated['username'] ?? null
            );
            return $this->successResponse($options);
        } catch (\Exception $e) {
            Log::error('Failed to create passkey authentication options', [
                'error' => $e->getMessage(),
            ]);
            return $this->errorResponse('Failed to create authentication options', 500);
        }
    }

    /**
     * Verify authentication response and return JWT.
     *
     * POST /api/passkeys/authenticate/verify
     *
     * @unauthenticated
     */
    public function authenticateVerify(Request $request): JsonResponse
    {
        if (!$this->passkeyService->isEnabled()) {
            return $this->errorResponse('Passkeys are disabled', 400);
        }

        $validated = $request->validate([
            'credential' => 'required|array',
            'session_id' => 'required|string|size:32',
            'partition_id' => 'required|string|exists:identity_partitions,record_id',
            'timezone' => 'nullable|string|max:64|timezone:all',
        ]);

        try {
            $user = $this->passkeyService->verifyAuthentication(
                $validated['credential'],
                $validated['session_id']
            );

            // Check if user has access to the specified partition
            if (!$user->is_system_user && !$user->partitions()->where('partition_id', $validated['partition_id'])->exists()) {
                return $this->errorResponse('Invalid credentials', 401);
            }

            // Check if user needs email verification
            if ($user->needsEmailVerification()) {
                // Check if unverified login is allowed
                $allowUnverifiedLogin = $this->getAllowUnverifiedLoginSetting();

                if (! $allowUnverifiedLogin) {
                    Log::info('Passkey login blocked - email verification required', [
                        'user_id' => $user->record_id,
                        'username' => $user->username,
                        'ip' => $request->ip(),
                    ]);

                    return $this->errorResponse('Please verify your email address before logging in.', 403, [
                        'email_verification_required' => true,
                        'email' => $user->email,
                    ]);
                }

                // Allow login but log that it's in warning mode
                Log::info('Passkey login allowed with unverified email - warning mode', [
                    'user_id' => $user->record_id,
                    'username' => $user->username,
                    'ip' => $request->ip(),
                ]);
            }

            // Record successful login
            $user->recordSuccessfulLogin();

            // Fire login event — listeners handle timezone auto-detect, analytics, etc.
            event(new \NewSolari\Core\Events\UserLoggedIn(
                $user,
                $validated['partition_id'],
                $validated['timezone'] ?? null,
                'passkey'
            ));

            // Generate JWT token
            $token = $this->generateJwtToken($user);

            // Load groups and permissions
            $user->load('groups.permissions');

            Log::info('User logged in via passkey', [
                'user_id' => $user->record_id,
                'username' => $user->username,
                'partition_id' => $user->partition_id,
                'ip' => $request->ip(),
            ]);

            $expiresIn = (int) config('jwt.expiration', ApiConstants::JWT_EXPIRATION_SECONDS);
            $maxRefreshAge = (int) config('jwt.max_refresh_age', ApiConstants::JWT_MAX_REFRESH_SECONDS);
            $slidingRefresh = (bool) config('jwt.sliding_refresh', false);

            // Build response
            $response = $this->successResponse([
                'user' => $user,
                'token' => $token,
                'token_type' => 'bearer',
                'expires_in' => $expiresIn,
                'max_refresh_age' => $maxRefreshAge,
                'sliding_refresh' => $slidingRefresh,
                'absolute_max_age' => $slidingRefresh ? (int) config('jwt.absolute_max_age', 31536000) : null,
                'admin_partition_ids' => $user->getAdminPartitionIds(),
                'email_verification_pending' => $user->needsEmailVerification(),
            ]);

            // Attach httpOnly cookie (use max_refresh_age for cookie lifetime, not token expiration)
            return $this->attachAuthCookies($response, $token, $maxRefreshAge);
        } catch (\Exception $e) {
            Log::warning('Passkey authentication failed', [
                'error' => $e->getMessage(),
                'ip' => $request->ip(),
            ]);
            return $this->errorResponse('Authentication failed', 401);
        }
    }

    /**
     * List user's passkeys.
     *
     * GET /api/passkeys
     */
    public function list(Request $request): JsonResponse
    {
        $user = $this->getAuthenticatedUser($request);

        if (!$user) {
            return $this->errorResponse('Unauthenticated', 401);
        }

        return $this->successResponse($this->passkeyService->getUserPasskeys($user));
    }

    /**
     * Delete a passkey.
     *
     * DELETE /api/passkeys/{id}
     */
    public function delete(Request $request, string $id): JsonResponse
    {
        $user = $this->getAuthenticatedUser($request);

        if (!$user) {
            return $this->errorResponse('Unauthenticated', 401);
        }

        try {
            $deleted = $this->passkeyService->deletePasskey($user, $id);

            if (!$deleted) {
                return $this->errorResponse('Passkey not found', 404);
            }

            return $this->successResponse(['message' => 'Passkey deleted successfully']);
        } catch (\RuntimeException $e) {
            return $this->errorResponse($e->getMessage(), 400);
        }
    }

    /**
     * Update a passkey (rename).
     *
     * PATCH /api/passkeys/{id}
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $user = $this->getAuthenticatedUser($request);

        if (!$user) {
            return $this->errorResponse('Unauthenticated', 401);
        }

        $validated = $request->validate([
            'device_name' => 'required|string|max:' . ApiConstants::STRING_MAX_LENGTH,
        ]);

        $updated = $this->passkeyService->renamePasskey($user, $id, $validated['device_name']);

        if (!$updated) {
            return $this->errorResponse('Passkey not found', 404);
        }

        return $this->successResponse(['message' => 'Passkey updated successfully']);
    }

    /**
     * Get user from request, checking both regular auth and limited purpose tokens.
     *
     * This allows passkey registration endpoints to accept:
     * - Regular authenticated users (full JWT)
     * - Users with limited-purpose tokens (recovery, passkey_registration)
     */
    private function getUserFromRequest(Request $request): ?IdentityUser
    {
        return $this->getUserFromRequestWithPurpose($request)['user'];
    }

    /**
     * Get user from request with the token purpose.
     *
     * Returns ['user' => IdentityUser|null, 'purpose' => string|null]
     */
    private function getUserFromRequestWithPurpose(Request $request): array
    {
        // IMPORTANT: Check bearer token FIRST to extract purpose
        // The middleware may have already authenticated the user, but we need the purpose from the token
        $token = $request->bearerToken();

        // If no bearer token, fall back to regular authentication (for users adding passkeys)
        if (!$token) {
            try {
                $user = $this->getAuthenticatedUser($request);
                if ($user) {
                    return ['user' => $user, 'purpose' => null];
                }
            } catch (\Exception $e) {
                // Regular auth failed
            }
            return ['user' => null, 'purpose' => null];
        }

        try {
            // Decode the JWT
            $decoded = JWT::decode($token, new Key(IdentityUser::getJwtSecret(), config('jwt.algorithm', 'HS256')));
            $payload = (array) $decoded;

            // If token has no purpose claim, it's a regular auth token - fall back to regular auth
            if (!isset($payload['purpose'])) {
                try {
                    $user = $this->getAuthenticatedUser($request);
                    if ($user) {
                        return ['user' => $user, 'purpose' => null];
                    }
                } catch (\Exception $e) {
                    // Regular auth failed
                }
                return ['user' => null, 'purpose' => null];
            }

            $purpose = $payload['purpose'];

            // Only accept specific purposes for passkey registration
            if (!in_array($purpose, ['recovery', 'passkey_registration'], true)) {
                Log::warning('Limited purpose token with invalid purpose', [
                    'purpose' => $purpose,
                ]);
                return ['user' => null, 'purpose' => null];
            }

            // Verify required claims
            if (!isset($payload['sub']) || !isset($payload['exp'])) {
                return ['user' => null, 'purpose' => null];
            }

            // Check if token is blacklisted
            // The blacklist stores `true` at key `jwt_blacklist_{jti}`, so existence = blacklisted.
            if (isset($payload['jti'])) {
                $blacklistKey = 'jwt_blacklist_' . $payload['jti'];
                if (Cache::has($blacklistKey)) {
                    return ['user' => null, 'purpose' => null];
                }
            }

            // Find user
            $user = IdentityUser::withoutGlobalScope('partition')
                ->where('username', $payload['sub'])
                ->first();

            if (!$user) {
                return ['user' => null, 'purpose' => null];
            }

            Log::debug('Limited purpose token validated for passkey registration', [
                'user_id' => $user->record_id,
                'purpose' => $purpose,
            ]);

            return ['user' => $user, 'purpose' => $purpose];

        } catch (ExpiredException $e) {
            Log::info('Limited purpose token expired');
            return ['user' => null, 'purpose' => null];
        } catch (SignatureInvalidException $e) {
            Log::warning('Limited purpose token signature invalid');
            return ['user' => null, 'purpose' => null];
        } catch (\Exception $e) {
            Log::error('Limited purpose token validation failed', ['error' => $e->getMessage()]);
            return ['user' => null, 'purpose' => null];
        }
    }

}
