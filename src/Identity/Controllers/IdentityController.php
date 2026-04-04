<?php
namespace NewSolari\Core\Identity\Controllers;

use NewSolari\Core\Http\BaseController;
use NewSolari\Core\Constants\ApiConstants;
use NewSolari\Core\Identity\Models\Group;
use NewSolari\Core\Identity\Models\IdentityPartition;
use NewSolari\Core\Identity\Models\IdentityUser;
use NewSolari\PushNotifications\Jobs\CleanupUserPushSubscriptions;
use NewSolari\PushNotifications\Models\NativePushToken;
use NewSolari\PushNotifications\Models\PushSubscription;
use NewSolari\Core\Identity\Models\RegistrySetting;
use NewSolari\Core\Rules\ValidUsername;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class IdentityController extends BaseController
{
    public function index(Request $request): JsonResponse
    {
        $this->logRequest($request, 'index', 'users');

        $user = $this->getAuthenticatedUser($request);
        $requestedPartitionId = $this->getPartitionId($request);

        // Load user's groups and permissions for partition admin check
        if (! $user->is_system_user) {
            $user->load('groups.permissions');
        }

        // For non-system users, verify they have access to the requested partition
        if (! $user->is_system_user) {
            // Users can only access partitions they belong to
            $userPartitionIds = $user->partitions()->pluck('identity_partitions.record_id')->toArray();
            $userPartitionIds[] = $user->partition_id; // Include home partition

            // If a specific partition is requested, verify access
            if ($requestedPartitionId && ! in_array($requestedPartitionId, $userPartitionIds)) {
                return $this->errorResponse('Unauthorized: You do not have access to this partition', 403);
            }

            // Use the requested partition if valid, otherwise use user's home partition
            $partitionId = $requestedPartitionId && in_array($requestedPartitionId, $userPartitionIds)
                ? $requestedPartitionId
                : $user->partition_id;

            // Only partition admins can list users
            if (! $user->isPartitionAdmin($partitionId)) {
                return $this->errorResponse('Unauthorized', 403);
            }

            $users = IdentityUser::with(['partitions', 'groups'])
                ->where('partition_id', $partitionId)
                ->get();

            return $this->successResponse($users);
        }

        // System admins can see all users
        $query = IdentityUser::query();

        if ($requestedPartitionId) {
            $query->where('partition_id', $requestedPartitionId);
        }

        $users = $query->with(['partitions', 'groups'])->get();

        return $this->successResponse($users);
    }

    /**
     * Get a simplified list of users for recipient pickers (messages, etc).
     * Returns only basic info (id, username) and only users in the same partition.
     * Does NOT require partition admin - any user with users.read can access.
     *
     * GET /api/users/simple
     */
    public function listSimple(Request $request): JsonResponse
    {
        $user = $this->getAuthenticatedUser($request);
        $partitionId = $this->getPartitionId($request);

        // For non-system users, only allow access to their own partition
        if (! $user->is_system_user) {
            $partitionId = $partitionId ?? $user->partition_id;
            if ($partitionId !== $user->partition_id) {
                return $this->errorResponse('Unauthorized', 403);
            }
        }

        // Get users in the partition, excluding the current user
        // Only return non-sensitive fields
        $query = IdentityUser::where('record_id', '!=', $user->record_id)
            ->where('is_active', true)
            ->select('record_id', 'username', 'first_name', 'last_name')
            ->orderBy('username');

        // System users can see all users, or filter by partition if specified
        if (! $user->is_system_user) {
            $query->where('partition_id', $partitionId);
        } elseif ($partitionId) {
            $query->where('partition_id', $partitionId);
        }

        $users = $query->get()
            ->map(fn ($u) => [
                'id' => $u->record_id,
                'username' => $u->username,
                'display_name' => trim(($u->first_name ?? '') . ' ' . ($u->last_name ?? '')) ?: $u->username,
            ]);

        return $this->successResponse($users);
    }

    public function store(Request $request): JsonResponse
    {
        $this->logRequest($request, 'store', 'users');

        $authenticatedUser = $this->getAuthenticatedUser($request);
        $partitionId = $this->getPartitionId($request);

        // Merge partition_id from header if not in request body
        if (! $request->has('partition_id') && $partitionId) {
            $request->merge(['partition_id' => $partitionId]);
        }

        // Load user's groups and permissions for partition admin check
        if (! $authenticatedUser->is_system_user) {
            $authenticatedUser->load('groups.permissions');
        }

        // Only system admins and partition admins can create users
        // Check if the user is a partition admin for their OWN partition, not the requested one
        if (! $authenticatedUser->is_system_user && ! $authenticatedUser->isPartitionAdmin($authenticatedUser->partition_id)) {
            return $this->errorResponse('Unauthorized', 403);
        }

        try {
            // SECURITY: Build password rule with breach check in production only
            // Testing environments skip haveibeenpwned API calls
            $passwordRule = Password::min(12)->mixedCase()->numbers()->symbols();
            if (! app()->environment('testing')) {
                $passwordRule = $passwordRule->uncompromised(3);
            }

            $validated = $request->validate([
                'username' => ['required', 'string', 'max:'.ApiConstants::STRING_MAX_LENGTH, 'unique:identity_users', new ValidUsername()],
                'email' => 'nullable|email|max:'.ApiConstants::STRING_MAX_LENGTH.'|unique:identity_users',
                'password' => ['required', 'string', $passwordRule],
                'partition_id' => 'required|string|exists:identity_partitions,record_id',
                'first_name' => 'nullable|string|max:'.ApiConstants::STRING_MAX_LENGTH,
                'last_name' => 'nullable|string|max:'.ApiConstants::STRING_MAX_LENGTH,
                'is_active' => 'boolean',
                'group_ids' => 'sometimes|array',
                'group_ids.*' => 'string|exists:groups,record_id',
            ]);
        } catch (ValidationException $e) {
            return $this->handleValidationException($e);
        }

        // Partition admins can only create users in their own partition
        // Return an error if they try to create in a different partition (don't silently override)
        if (! $authenticatedUser->is_system_user && $validated['partition_id'] !== $authenticatedUser->partition_id) {
            return $this->errorResponse('Partition admins can only create users in their own partition', 403);
        }

        // Only system admins can create system admin users
        // Partition admins cannot elevate users to system admin status
        if (! $authenticatedUser->is_system_user && $request->boolean('is_system_user')) {
            return $this->errorResponse('Only system administrators can create system admin users', 403);
        }

        // Wrap user creation and partition assignment in a transaction
        $user = DB::transaction(function () use ($validated) {
            // Create the user
            $user = IdentityUser::createWithValidation([
                'username' => $validated['username'],
                'email' => $validated['email'] ?? null,
                'password_hash' => $validated['password'],
                'partition_id' => $validated['partition_id'],
                'first_name' => $validated['first_name'] ?? null,
                'last_name' => $validated['last_name'] ?? null,
                'is_active' => $validated['is_active'] ?? true,
            ]);

            // Add user to the partition
            $user->partitions()->attach($validated['partition_id']);

            // Add user to groups if provided
            if (! empty($validated['group_ids'])) {
                $user->groups()->sync($validated['group_ids']);
            }

            return $user;
        });

        return $this->successResponse($user, 201);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $this->logRequest($request, 'show', 'users');

        $targetUser = IdentityUser::with(['partitions', 'groups', 'permissions'])->find($id);

        if (! $targetUser) {
            return $this->errorResponse('User not found', 404);
        }

        $authenticatedUser = $this->getAuthenticatedUser($request);
        $requestPartitionId = $this->getPartitionId($request);

        // Regular users can only view themselves
        if (! $authenticatedUser->is_system_user && ! $authenticatedUser->isPartitionAdmin($targetUser->partition_id)) {
            if ($authenticatedUser->record_id !== $id) {
                return $this->errorResponse('Unauthorized', 403);
            }
        }

        // Partition admins can only view users in their partition
        if (! $authenticatedUser->is_system_user) {
            if ($targetUser->partition_id !== $authenticatedUser->partition_id) {
                return $this->errorResponse('Unauthorized', 403);
            }
        }

        // Calculate if user has permissions through group inheritance
        $targetUser->has_permission = $targetUser->permissions->isNotEmpty() ||
            $targetUser->groups->flatMap->permissions->isNotEmpty();

        return $this->successResponse($targetUser);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $this->logRequest($request, 'update', 'users');

        $targetUser = IdentityUser::find($id);
        $authenticatedUser = $this->getAuthenticatedUser($request);
        $requestPartitionId = $this->getPartitionId($request);

        if (! $targetUser) {
            return $this->errorResponse('User not found', 404);
        }

        // Regular users can only update themselves
        if (! $authenticatedUser->is_system_user && ! $authenticatedUser->isPartitionAdmin($targetUser->partition_id)) {
            if ($authenticatedUser->record_id !== $id) {
                return $this->errorResponse('Unauthorized', 403);
            }
        }

        // Partition admins can only update users in their partition
        if (! $authenticatedUser->is_system_user) {
            if ($targetUser->partition_id !== $authenticatedUser->partition_id) {
                return $this->errorResponse('Unauthorized', 403);
            }
        }

        // Only system admins can promote users to system admin status
        // Partition admins cannot elevate users to system admin status
        if (! $authenticatedUser->is_system_user && $request->boolean('is_system_user')) {
            return $this->errorResponse('Only system administrators can grant system admin privileges', 403);
        }

        // Partition admins cannot modify existing system admin users
        if (! $authenticatedUser->is_system_user && $targetUser->is_system_user) {
            return $this->errorResponse('Partition admins cannot modify system administrator accounts', 403);
        }

        $validated = $request->validate([
            'username' => 'sometimes|string|max:255|unique:identity_users,username,'.$id.',record_id',
            'email' => 'nullable|email|max:'.ApiConstants::STRING_MAX_LENGTH.'|unique:identity_users,email,'.$id.',record_id',
            'first_name' => 'sometimes|nullable|string|max:'.ApiConstants::STRING_MAX_LENGTH,
            'last_name' => 'sometimes|nullable|string|max:'.ApiConstants::STRING_MAX_LENGTH,
            'is_active' => 'sometimes|boolean',
            'password_hash' => ['sometimes', 'string', Password::min(12)->mixedCase()->numbers()->symbols()],
            'partition_id' => 'sometimes|string|exists:identity_partitions,record_id',
            'group_ids' => 'sometimes|array',
            'group_ids.*' => 'string|exists:groups,record_id',
        ]);

        // Extract group_ids before updating user
        $groupIds = null;
        if (array_key_exists('group_ids', $validated)) {
            $groupIds = $validated['group_ids'];
            unset($validated['group_ids']);
        }

        DB::transaction(function () use ($targetUser, $validated, $authenticatedUser, $request, $groupIds) {
            $targetUser->updateWithValidation($validated);

            // Explicitly handle is_system_user flag (not mass-assignable for defense-in-depth)
            if ($authenticatedUser->is_system_user && $request->has('is_system_user')) {
                $targetUser->setSystemUser($request->boolean('is_system_user'));
            }

            // Sync groups if provided
            if ($groupIds !== null) {
                $targetUser->groups()->sync($groupIds);
            }
        });

        // Reload relationships
        $targetUser->load(['partitions', 'groups']);

        return $this->successResponse($targetUser);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $this->logRequest($request, 'destroy', 'users');

        $targetUser = IdentityUser::find($id);
        $authenticatedUser = $this->getAuthenticatedUser($request);
        $requestPartitionId = $this->getPartitionId($request);

        if (! $targetUser) {
            return $this->errorResponse('User not found', 404);
        }

        // Prevent deleting the authenticated user
        if ($authenticatedUser->record_id === $id) {
            return $this->errorResponse('Cannot delete currently authenticated user', 400);
        }

        // Regular users cannot delete users
        if (! $authenticatedUser->is_system_user && ! $authenticatedUser->isPartitionAdmin($targetUser->partition_id)) {
            return $this->errorResponse('Unauthorized', 403);
        }

        // Partition admins can only delete users in their partition
        if (! $authenticatedUser->is_system_user) {
            if ($targetUser->partition_id !== $authenticatedUser->partition_id) {
                return $this->errorResponse('Unauthorized', 403);
            }
        }

        $deletedUsername = $targetUser->username;
        $deletedUserId = $targetUser->record_id;
        $deletedUserPartition = $targetUser->partition_id;

        $targetUser->deleteWithValidation($authenticatedUser->record_id);

        Log::info('User account deleted', [
            'deleted_user_id' => $deletedUserId,
            'deleted_username' => $deletedUsername,
            'deleted_by' => $authenticatedUser->record_id,
            'deleted_by_username' => $authenticatedUser->username,
            'partition_id' => $deletedUserPartition,
            'ip' => $request->ip(),
        ]);

        return $this->successResponse(null, 200);
    }

    public function updatePassword(Request $request, string $id): JsonResponse
    {
        $this->logRequest($request, 'update_password', 'users');

        $targetUser = IdentityUser::find($id);
        $authenticatedUser = $this->getAuthenticatedUser($request);

        if (! $targetUser) {
            return $this->errorResponse('User not found', 404);
        }

        $isOwnPassword = $authenticatedUser->record_id === $id;
        $isAdmin = $authenticatedUser->is_system_user || $authenticatedUser->isPartitionAdmin($targetUser->partition_id);

        // Regular users can only update their own password
        if (! $isAdmin && ! $isOwnPassword) {
            return $this->errorResponse('Unauthorized', 403);
        }

        // For own password changes: require current password
        // For admin changing other's password: require admin's own password for verification
        if ($isOwnPassword) {
            $validated = $request->validate([
                'current_password' => 'required|string',
                'new_password' => ['required', 'string', 'different:current_password', Password::min(12)->mixedCase()->numbers()->symbols()],
            ]);

            // Verify user's current password
            if (! $targetUser->authenticate($validated['current_password'])) {
                return $this->errorResponse('Current password is incorrect', 401);
            }
        } else {
            // Admin changing another user's password - require admin's password for verification
            $validated = $request->validate([
                'admin_password' => 'required|string',
                'new_password' => ['required', 'string', Password::min(12)->mixedCase()->numbers()->symbols()],
            ]);

            // Verify admin's password to prevent unauthorized changes
            if (! $authenticatedUser->authenticate($validated['admin_password'])) {
                return $this->errorResponse('Admin password verification failed', 401);
            }

            Log::warning('Admin password change for another user', [
                'admin_id' => $authenticatedUser->record_id,
                'admin_username' => $authenticatedUser->username,
                'target_user_id' => $targetUser->record_id,
                'target_username' => $targetUser->username,
                'ip' => $request->ip(),
            ]);
        }

        $targetUser->password_hash = $validated['new_password'];
        $targetUser->save();

        return $this->successResponse(['message' => 'Password updated successfully']);
    }

    public function login(Request $request): JsonResponse
    {
        $this->logRequest($request, 'login', 'auth');

        // Check if password login is allowed
        $authMode = config('passkeys.mode', 'hybrid');
        if ($authMode === 'passkeys_only') {
            return $this->errorResponse('Password login is disabled. Please use a passkey.', 403);
        }

        $validated = $request->validate([
            'username' => 'required|string',
            'password' => 'required|string',
            'partition_id' => 'required|string|exists:identity_partitions,record_id',
            'timezone' => 'nullable|string|max:64|timezone:all',
        ]);

        // Bypass partition scope - users can log in from any partition they have access to
        $user = IdentityUser::withoutGlobalScope('partition')
            ->where('username', $validated['username'])
            ->first();

        // Timing attack protection: Always perform password hash comparison
        // even when user doesn't exist, to prevent timing-based username enumeration
        if (! $user) {
            // Use a dummy hash to ensure consistent timing
            // This hash is bcrypt of 'dummy_password' - the actual value doesn't matter
            $dummyHash = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi';
            password_verify($validated['password'], $dummyHash);

            return $this->errorResponse('Invalid credentials', 401);
        }

        // Check if account is locked out
        if ($user->isLockedOut()) {
            $remainingSeconds = $user->getRemainingLockoutSeconds();

            Log::warning('Login attempt on locked account', [
                'user_id' => $user->record_id,
                'username' => $user->username,
                'remaining_lockout_seconds' => $remainingSeconds,
                'ip' => $request->ip(),
            ]);

            return $this->errorResponse('Account temporarily locked. Please try again later.', 429, [
                'retry_after' => $remainingSeconds,
                'locked' => true,
            ]);
        }

        // Check exponential backoff (progressive delay between attempts)
        if (! $user->canAttemptLogin()) {
            $delaySeconds = $user->getLoginDelaySeconds();
            $secondsSinceLastFail = $user->last_failed_login_at
                ? $user->last_failed_login_at->diffInSeconds(now())
                : 0;
            $remainingDelay = max(0, $delaySeconds - $secondsSinceLastFail);

            Log::info('Login attempt during backoff period', [
                'user_id' => $user->record_id,
                'username' => $user->username,
                'required_delay' => $delaySeconds,
                'remaining_delay' => $remainingDelay,
                'ip' => $request->ip(),
            ]);

            return $this->errorResponse('Too many attempts. Please wait before trying again.', 429, [
                'retry_after' => $remainingDelay,
            ]);
        }

        if (! $user->authenticate($validated['password'])) {
            // Record failed login attempt (handles lockout if threshold reached)
            $user->recordFailedLogin();

            Log::warning('Failed login attempt', [
                'user_id' => $user->record_id,
                'username' => $user->username,
                'failed_attempts' => $user->failed_login_attempts,
                'ip' => $request->ip(),
            ]);

            return $this->errorResponse('Invalid credentials', 401);
        }

        // Use consistent error message for security (prevents account enumeration)
        // Different messages for inactive accounts or partition access would reveal account existence
        if (! $user->is_active) {
            return $this->errorResponse('Invalid credentials', 401);
        }

        // Check if user needs to verify their email
        // This check is after password verification, so it's safe to reveal verification status
        if ($user->needsEmailVerification()) {
            // Check if unverified login is allowed
            $allowUnverifiedLogin = $this->getAllowUnverifiedLoginSetting();

            if (! $allowUnverifiedLogin) {
                Log::info('Login blocked - email verification required', [
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
            Log::info('Login allowed with unverified email - warning mode', [
                'user_id' => $user->record_id,
                'username' => $user->username,
                'ip' => $request->ip(),
            ]);
        }

        // Check if user has access to the specified partition
        // System admins can access any partition without explicit assignment
        // Use consistent error message to prevent partition enumeration
        if (! $user->is_system_user && ! $user->partitions()->where('partition_id', $validated['partition_id'])->exists()) {
            return $this->errorResponse('Invalid credentials', 401);
        }

        // Record successful login (resets failed attempt counters)
        $user->recordSuccessfulLogin();

        // Fire login event — listeners handle timezone auto-detect, analytics, etc.
        event(new \NewSolari\Core\Events\UserLoggedIn(
            $user,
            $validated['partition_id'],
            $validated['timezone'] ?? null,
            'password'
        ));

        // Generate a proper JWT token with signing and expiration
        $token = $this->generateJwtToken($user);

        // Load groups and permissions for admin partition check
        $user->load('groups.permissions');

        // Audit log: successful login
        Log::info('User logged in successfully', [
            'user_id' => $user->record_id,
            'username' => $user->username,
            'partition_id' => $user->partition_id,
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        $expiresIn = (int) config('jwt.expiration', ApiConstants::JWT_EXPIRATION_SECONDS);
        $maxRefreshAge = (int) config('jwt.max_refresh_age', ApiConstants::JWT_MAX_REFRESH_SECONDS);
        $slidingRefresh = (bool) config('jwt.sliding_refresh', false);

        // Build response - token in body for backward compatibility with API clients
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

        // FE-CRIT-001: Attach httpOnly cookie for browser clients
        // This prevents XSS attacks from stealing the token
        return $this->attachAuthCookies($response, $token, $expiresIn);
    }

    /**
     * Register a new user (public endpoint).
     *
     * Allows public user registration if enabled for the partition.
     * Registration must be enabled at both system level (system.registration.enabled)
     * and partition level (partition.registration.enabled).
     *
     * @unauthenticated
     */
    public function register(Request $request): JsonResponse
    {
        $this->logRequest($request, 'register', 'auth');

        // Check auth mode for password requirements
        $authMode = config('passkeys.mode', 'hybrid');
        $passwordRequired = $authMode !== 'passkeys_only';

        // Basic format validation only (existence checks done atomically in transaction)
        // SECURITY FIX (BE-MED-SEC-004): Removed 'exists:identity_partitions,record_id' rule
        // to prevent TOCTOU race condition - partition validity checked atomically in transaction
        try {
            $passwordRules = $passwordRequired
                ? ['required', 'string', Password::min(12)->mixedCase()->numbers()->symbols()]
                : ['nullable', 'string', Password::min(12)->mixedCase()->numbers()->symbols()];

            $passwordConfirmRules = $passwordRequired
                ? 'required|string|same:password'
                : 'nullable|string|same:password';

            $validated = $request->validate([
                'username' => ['required', 'string', 'max:'.ApiConstants::STRING_MAX_LENGTH, 'unique:identity_users', new ValidUsername()],
                'email' => 'nullable|email|max:'.ApiConstants::STRING_MAX_LENGTH.'|unique:identity_users',
                'password' => $passwordRules,
                'password_confirmation' => $passwordConfirmRules,
                'partition_id' => 'required|string|max:64',
                'first_name' => 'nullable|string|max:'.ApiConstants::STRING_MAX_LENGTH,
                'last_name' => 'nullable|string|max:'.ApiConstants::STRING_MAX_LENGTH,
            ]);
        } catch (ValidationException $e) {
            return $this->handleValidationException($e);
        }

        // In passkeys_only mode, email is required for account recovery
        if ($authMode === 'passkeys_only' && empty($validated['email'])) {
            return $this->errorResponse('Email is required for passkey-only accounts (needed for recovery)', 422);
        }

        $partitionId = $validated['partition_id'];

        // SECURITY FIX (BE-MED-SEC-004): Wrap ALL checks in a single transaction with
        // pessimistic locking to prevent TOCTOU race conditions where registration
        // settings could change between check and user creation
        try {
            $result = DB::transaction(function () use ($validated, $partitionId, $request, $passwordRequired) {
                // Use lockForUpdate only on databases that support it (not SQLite)
                // SQLite uses file-level locking which provides transaction isolation
                $useLocking = config('database.default') !== 'sqlite';

                // ATOMIC CHECK 1: Lock and verify partition exists AND is active
                $partitionQuery = IdentityPartition::where('record_id', $partitionId)
                    ->where('is_active', true);
                if ($useLocking) {
                    $partitionQuery->lockForUpdate();
                }
                $partition = $partitionQuery->first();

                if (! $partition) {
                    throw new \RuntimeException('Partition not found or not active', 404);
                }

                // ATOMIC CHECK 2: Lock and verify system registration is enabled
                $systemQuery = RegistrySetting::where('scope', 'system')
                    ->where('key', 'system.registration.enabled');
                if ($useLocking) {
                    $systemQuery->lockForUpdate();
                }
                $systemSetting = $systemQuery->first();

                $systemEnabled = false;
                if ($systemSetting) {
                    $systemValue = $systemSetting->value;
                    $systemEnabled = ($systemValue === 'true' || $systemValue === true);
                }

                if (! $systemEnabled) {
                    Log::warning('Registration blocked by system setting (atomic check)', [
                        'partition_id' => $partitionId,
                        'system_setting_exists' => $systemSetting !== null,
                        'ip' => $request->ip(),
                    ]);
                    throw new \RuntimeException('Registration is currently disabled', 403);
                }

                // ATOMIC CHECK 3: Lock and verify partition registration is enabled
                $partitionSettingQuery = RegistrySetting::where('scope', 'partition')
                    ->where('key', 'partition.registration.enabled')
                    ->where('partition_id', $partitionId);
                if ($useLocking) {
                    $partitionSettingQuery->lockForUpdate();
                }
                $partitionSetting = $partitionSettingQuery->first();

                $partitionEnabled = false;
                if ($partitionSetting) {
                    $partitionValue = $partitionSetting->value;
                    $partitionEnabled = ($partitionValue === 'true' || $partitionValue === true);
                }

                if (! $partitionEnabled) {
                    Log::info('Registration not enabled for partition (atomic check)', [
                        'partition_id' => $partitionId,
                        'ip' => $request->ip(),
                    ]);
                    throw new \RuntimeException('Registration is not enabled for this partition', 403);
                }

                // ATOMIC CHECK 4: Check if email is required (read within transaction for consistency)
                $requireEmailSetting = RegistrySetting::where('scope', 'system')
                    ->where('key', 'system.registration.require_email')
                    ->first();

                $requireEmail = false;
                if ($requireEmailSetting) {
                    $requireEmail = ($requireEmailSetting->value === 'true' || $requireEmailSetting->value === true);
                }

                if ($requireEmail && empty($validated['email'])) {
                    throw new \RuntimeException('Email is required for registration', 422);
                }

                // ATOMIC CHECK 5: Check if email verification is enabled
                $emailVerificationEnabled = false;
                $emailVerificationSetting = RegistrySetting::where('scope', 'system')
                    ->where('key', 'system.account.email_verification.enabled')
                    ->first();

                if ($emailVerificationSetting) {
                    $emailVerificationEnabled = ($emailVerificationSetting->value === 'true' || $emailVerificationSetting->value === true);
                }

                $requiresEmailVerification = $emailVerificationEnabled && ! empty($validated['email']);

                // ALL CHECKS PASSED - Create user within same transaction
                // password_required is true if password was provided (for hybrid mode)
                // or if passwords are required (not passkeys_only mode)
                $hasPassword = !empty($validated['password']);

                // In passkeys_only mode with no password, use a random unusable hash
                // This satisfies the NOT NULL constraint while being cryptographically unusable
                $passwordValue = $validated['password'] ?? null;
                if ($passwordValue === null && !$passwordRequired) {
                    // Generate random bytes and hash them - this hash will never match any password
                    $passwordValue = bin2hex(random_bytes(32));
                }

                $user = IdentityUser::createWithValidation([
                    'username' => $validated['username'],
                    'email' => $validated['email'] ?? null,
                    'password_hash' => $passwordValue,
                    'partition_id' => $partitionId,
                    'first_name' => $validated['first_name'] ?? null,
                    'last_name' => $validated['last_name'] ?? null,
                    'is_active' => true,
                    'requires_email_verification' => $requiresEmailVerification,
                    'password_required' => $hasPassword,
                ]);

                // Add user to the partition
                $user->partitions()->attach($partitionId);

                // Check for default group setting (supports multiple groups as JSON array)
                $defaultGroupSetting = RegistrySetting::where('scope', 'partition')
                    ->where('key', 'partition.registration.default_group')
                    ->where('partition_id', $partitionId)
                    ->first();

                if ($defaultGroupSetting && ! empty($defaultGroupSetting->value)) {
                    try {
                        // Parse as JSON array, fallback to single value for backward compatibility
                        $groupIds = json_decode($defaultGroupSetting->value, true);
                        if (! is_array($groupIds)) {
                            $groupIds = [$defaultGroupSetting->value];
                        }

                        foreach ($groupIds as $groupId) {
                            if (empty($groupId)) {
                                continue;
                            }
                            $group = Group::find($groupId);
                            if ($group && $group->partition_id === $partitionId) {
                                $user->groups()->attach($group->record_id);
                            }
                        }
                    } catch (\Exception $e) {
                        Log::warning('Failed to add user to default groups', [
                            'user_id' => $user->record_id,
                            'group_ids' => $defaultGroupSetting->value,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }

                return [
                    'user' => $user,
                    'requires_email_verification' => $requiresEmailVerification,
                    'has_password' => $hasPassword,
                ];
            });

            $user = $result['user'];
            $requiresEmailVerification = $result['requires_email_verification'];
            $hasPassword = $result['has_password'];

            // In passkeys_only mode without a password, user must register a passkey
            $requiresPasskeyRegistration = !$hasPassword && $authMode === 'passkeys_only';

            Log::info('New user registered', [
                'user_id' => $user->record_id,
                'username' => $user->username,
                'partition_id' => $partitionId,
                'requires_email_verification' => $requiresEmailVerification,
                'requires_passkey_registration' => $requiresPasskeyRegistration,
                'ip' => $request->ip(),
            ]);

            // Check if unverified login is allowed (skip auto-sending email in that case)
            $allowUnverifiedLogin = $this->getAllowUnverifiedLoginSetting();

            // Send verification email if required (outside transaction - email is best effort)
            // BUT defer it if:
            // 1. Passkey registration is required - email will be sent after passkey setup
            // 2. Allow unverified login is enabled - user will request email via the warning banner
            if ($requiresEmailVerification && ! $requiresPasskeyRegistration && ! $allowUnverifiedLogin) {
                try {
                    $emailService = app(\NewSolari\Core\Services\EmailSecurityService::class);
                    $emailService->sendVerificationEmail($user);
                } catch (\Exception $e) {
                    Log::error('Failed to send verification email during registration', [
                        'user_id' => $user->record_id,
                        'error' => $e->getMessage(),
                    ]);
                    // Don't fail registration if email fails - user can request resend
                }
            }

            // Return success message based on registration requirements
            $message = $requiresPasskeyRegistration
                ? 'Registration successful. Please register a passkey to complete your account setup.'
                : ($requiresEmailVerification
                    ? ($allowUnverifiedLogin
                        ? 'Registration successful. You can login now, but please verify your email to ensure full account access.'
                        : 'Registration successful. Please check your email to verify your account before logging in.')
                    : 'Registration successful. Please login with your credentials.');

            $response = [
                'message' => $message,
                'user' => [
                    'username' => $user->username,
                    'email' => $user->email,
                ],
                'requires_email_verification' => $requiresEmailVerification,
                'requires_passkey_registration' => $requiresPasskeyRegistration,
            ];

            // If passkey registration is required, generate a temporary token
            if ($requiresPasskeyRegistration) {
                $currentTime = time();
                $expiresAt = $currentTime + 600; // 10 minute temporary token

                $payload = [
                    'iss' => config('jwt.issuer', config('app.url', 'webos')),
                    'sub' => $user->username,
                    'user_id' => $user->record_id,
                    'partition_id' => $partitionId,
                    'is_system_user' => false,
                    'iat' => $currentTime,
                    'exp' => $expiresAt,
                    'jti' => Str::random(ApiConstants::JWT_JTI_LENGTH),
                    'purpose' => 'passkey_registration', // Limited purpose token
                ];

                $response['temporary_token'] = JWT::encode($payload, IdentityUser::getJwtSecret(), config('jwt.algorithm', 'HS256'));
            }

            return $this->successResponse($response, 201);

        } catch (\RuntimeException $e) {
            // Ensure we use a valid HTTP status code (not DB error codes like 23000)
            $code = $e->getCode();
            if ($code < 100 || $code >= 600) {
                $code = 400;
            }

            return $this->errorResponse($e->getMessage(), $code);
        } catch (\Exception $e) {
            Log::error('Registration failed with unexpected error', [
                'partition_id' => $partitionId,
                'error' => $e->getMessage(),
                'ip' => $request->ip(),
            ]);

            return $this->errorResponse('Registration failed. Please try again later.', 500);
        }
    }

    public function getCurrentUser(Request $request): JsonResponse
    {
        $this->logRequest($request, 'get_current_user', 'auth');

        $user = $this->getAuthenticatedUser($request);

        if (! $user) {
            return $this->errorResponse('Unauthenticated', 401);
        }

        // Load groups with permissions for admin partition check
        $user->load('groups.permissions');

        $expiresIn = (int) config('jwt.expiration', ApiConstants::JWT_EXPIRATION_SECONDS);

        return $this->successResponse([
            'user' => $user,
            'permissions' => $user->permissions,
            'groups' => $user->groups,
            'admin_partition_ids' => $user->getAdminPartitionIds(),
            'email_verification_pending' => $user->needsEmailVerification(),
            'expires_in' => $expiresIn,
        ]);
    }

    /**
     * Debug endpoint to trace partition admin permissions.
     * Shows exactly what permissions the user has and why partition admin check passes/fails.
     *
     * SECURITY: This endpoint is restricted to non-production environments only
     * to prevent exposure of detailed permission structure.
     */
    public function debugPermissions(Request $request): JsonResponse
    {
        // Security: Only allow in non-production environments
        if (app()->environment('production')) {
            return $this->errorResponse('Debug endpoints are not available in production', 403);
        }

        $this->logRequest($request, 'debug_permissions', 'auth');

        $user = $this->getAuthenticatedUser($request);
        $partitionId = $this->getPartitionId($request);

        if (! $user) {
            return $this->errorResponse('Unauthenticated', 401);
        }

        // Load groups with permissions
        $user->load('groups.permissions');

        // Collect all permission data for debugging
        $groupsDebug = [];
        foreach ($user->groups as $group) {
            $permissionsDebug = [];
            foreach ($group->permissions as $permission) {
                $permissionsDebug[] = [
                    'record_id' => $permission->record_id,
                    'name' => $permission->name,
                    'permission_type' => $permission->permission_type,
                    'entity_type' => $permission->entity_type,
                    'partition_id' => $permission->partition_id,
                    'is_partition_admin_permission' => (
                        $permission->permission_type === 'Admin' &&
                        $permission->entity_type === 'Partition' &&
                        $permission->partition_id === $partitionId
                    ),
                    'type_check' => $permission->permission_type === 'Admin' ? 'PASS' : 'FAIL (expected: Admin, got: '.$permission->permission_type.')',
                    'entity_check' => $permission->entity_type === 'Partition' ? 'PASS' : 'FAIL (expected: Partition, got: '.$permission->entity_type.')',
                    'partition_check' => $permission->partition_id === $partitionId ? 'PASS' : 'FAIL (expected: '.$partitionId.', got: '.($permission->partition_id ?? 'null').')',
                ];
            }
            $groupsDebug[] = [
                'group_id' => $group->record_id,
                'group_name' => $group->name,
                'partition_id' => $group->partition_id,
                'permissions_count' => count($permissionsDebug),
                'permissions' => $permissionsDebug,
            ];
        }

        return $this->successResponse([
            'user_id' => $user->record_id,
            'username' => $user->username,
            'user_partition_id' => $user->partition_id,
            'request_partition_id' => $partitionId,
            'is_system_user' => $user->is_system_user,
            'is_partition_admin' => $user->isPartitionAdmin($partitionId),
            'admin_partition_ids' => $user->getAdminPartitionIds(),
            'groups_count' => $user->groups->count(),
            'groups' => $groupsDebug,
            'requirements' => [
                'permission_type' => 'Admin (case-sensitive PascalCase)',
                'entity_type' => 'Partition (case-sensitive PascalCase)',
                'partition_id' => 'Must match the partition ID from X-Partition-ID header: '.$partitionId,
            ],
        ]);
    }

    /**
     * Logout the current user by invalidating their token.
     *
     * Invalidates the current JWT token by adding it to a blacklist cache.
     * After logout, the token cannot be used for further authentication.
     *
     * @unauthenticated
     *
     * @response array{value: bool, result: array{message: string}, code: int}
     */
    public function logout(Request $request): JsonResponse
    {
        $this->logRequest($request, 'logout', 'auth');

        $userId = null;

        // Prefer Bearer over cookie (Android WebView may retain stale cookies)
        $cookieName = config('jwt.cookie.name', 'solari_access_token');
        $token = $request->bearerToken() ?? $request->cookie($cookieName);

        if ($token) {
            try {
                // Decode token to get jti claim
                $decoded = JWT::decode($token, new Key(IdentityUser::getJwtSecret(), config('jwt.algorithm', 'HS256')));

                // Get user ID from token for push cleanup
                if (isset($decoded->user_id)) {
                    $userId = $decoded->user_id;
                }

                if (isset($decoded->jti)) {
                    // Add token to blacklist cache (expires when token would expire)
                    $ttl = isset($decoded->exp) ? max(0, $decoded->exp - time()) : 3600;
                    Cache::put('jwt_blacklist_'.$decoded->jti, true, $ttl);

                    // Audit log: successful logout
                    Log::info('User logged out successfully', [
                        'user_id' => $decoded->sub ?? 'unknown',
                        'token_jti' => $decoded->jti,
                        'ip' => $request->ip(),
                    ]);
                }
            } catch (\Exception $e) {
                // Token is already invalid, just proceed
            }
        }

        // Fallback: get user from request attributes (for API key auth or test middleware)
        if (! $userId) {
            $authenticatedUser = $request->attributes->get('authenticated_user');
            if ($authenticatedUser instanceof IdentityUser) {
                $userId = $authenticatedUser->record_id;
            }
        }

        // Clean up push notification tokens
        if ($userId) {
            $this->cleanupPushTokensForUser($userId);
        }

        // FE-CRIT-001: Clear auth cookies
        $response = $this->successResponse(['message' => 'Logged out successfully']);

        return $this->clearAuthCookies($response);
    }

    /**
     * Clean up all push notification tokens for a user.
     *
     * Called during logout to ensure tokens don't receive notifications after logout.
     * This handles both web push subscriptions and native FCM/APNs tokens.
     *
     * @param  string  $userId  The user's record_id
     */
    private function cleanupPushTokensForUser(string $userId): void
    {
        try {
            // Delete web push subscriptions
            $webDeleted = PushSubscription::where('user_id', $userId)->delete();

            // Delete native push tokens (FCM/APNs)
            $nativeDeleted = NativePushToken::where('user_id', $userId)->delete();

            if ($webDeleted > 0 || $nativeDeleted > 0) {
                Log::info('Push tokens cleaned up on logout', [
                    'user_id' => $userId,
                    'web_subscriptions_deleted' => $webDeleted,
                    'native_tokens_deleted' => $nativeDeleted,
                ]);
            }
        } catch (\Exception $e) {
            // Log but don't fail logout if cleanup fails
            // The scheduled cleanup job will handle any remaining tokens
            Log::warning('Failed to cleanup push tokens on logout', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Refresh the current user's token.
     *
     * Generates a new JWT token even if the old token is expired, as long as
     * the token is within the max_refresh_age window from the original login.
     * This allows users to refresh their session after periods of inactivity.
     *
     * @response array{value: bool, result: array{token: string, token_type: string, expires_in: int}, code: int}
     * @response 401 array{value: bool, result: string, code: int}
     */
    public function refreshToken(Request $request): JsonResponse
    {
        $this->logRequest($request, 'refresh_token', 'auth');

        // Prefer Bearer over cookie (Android WebView may retain stale cookies)
        $cookieName = config('jwt.cookie.name', 'solari_access_token');
        $oldToken = $request->bearerToken() ?? $request->cookie($cookieName);

        if (! $oldToken) {
            return $this->errorResponse('No token provided', 401);
        }

        $decoded = null;
        $tokenExpired = false;

        // Try to decode the token - it may be expired but still valid for refresh
        try {
            $decoded = JWT::decode($oldToken, new Key(IdentityUser::getJwtSecret(), config('jwt.algorithm', 'HS256')));
        } catch (ExpiredException $e) {
            // Token is expired but signature was valid - we can still refresh it
            // Extract the payload from the expired token
            $tokenExpired = true;
            $decoded = $this->decodeExpiredToken($oldToken);

            if (! $decoded) {
                $errorResponse = $this->errorResponse('Invalid token', 401);

                return $this->clearAuthCookies($errorResponse);
            }
        } catch (\Exception $e) {
            // Token is completely invalid (bad signature, malformed, etc.)
            Log::warning('Token refresh failed: invalid token', ['error' => $e->getMessage()]);
            $errorResponse = $this->errorResponse('Invalid token', 401);

            return $this->clearAuthCookies($errorResponse);
        }

        // SECURITY: Reject tokens with a 'purpose' claim
        // These are limited-purpose tokens (recovery, passkey_registration) that should not be refreshable
        if (isset($decoded->purpose)) {
            Log::warning('Token refresh rejected: limited-purpose token', ['purpose' => $decoded->purpose]);
            $errorResponse = $this->errorResponse('Limited-purpose tokens cannot be refreshed', 401);

            return $this->clearAuthCookies($errorResponse);
        }

        // Check if token is blacklisted
        if (isset($decoded->jti)) {
            $blacklistKey = 'jwt_blacklist_'.$decoded->jti;
            if (Cache::has($blacklistKey)) {
                Log::info('Token refresh rejected: token is blacklisted', ['jti' => $decoded->jti]);
                $errorResponse = $this->errorResponse('Token has been revoked', 401);

                return $this->clearAuthCookies($errorResponse);
            }
        }

        // Check maximum refresh age
        $maxRefreshAge = (int) config('jwt.max_refresh_age', ApiConstants::JWT_MAX_REFRESH_SECONDS);
        $slidingRefresh = (bool) config('jwt.sliding_refresh', false);

        if (! isset($decoded->iat)) {
            Log::warning('Token refresh rejected: missing iat claim');
            $errorResponse = $this->errorResponse('Invalid token', 401);

            return $this->clearAuthCookies($errorResponse);
        }

        $tokenAge = time() - $decoded->iat;

        if ($slidingRefresh) {
            // Sliding mode: iat represents last refresh time, check inactivity
            if ($tokenAge > $maxRefreshAge) {
                Log::info('Token refresh rejected: inactivity timeout exceeded (sliding mode)', [
                    'inactivity_age' => $tokenAge,
                    'max_refresh_age' => $maxRefreshAge,
                ]);

                // Session is truly over — clean up push tokens asynchronously
                if (isset($decoded->sub)) {
                    dispatch(new CleanupUserPushSubscriptions($decoded->sub));
                }

                $errorResponse = $this->errorResponse('Session expired due to inactivity. Please login again.', 401);

                return $this->clearAuthCookies($errorResponse);
            }

            // Absolute cap: check time since original login
            $absoluteMaxAge = (int) config('jwt.absolute_max_age', 31536000);
            $originalIat = $decoded->original_iat ?? $decoded->iat;
            $absoluteAge = time() - $originalIat;
            if ($absoluteAge > $absoluteMaxAge) {
                Log::info('Token refresh rejected: absolute max age exceeded (sliding mode)', [
                    'absolute_session_age' => $absoluteAge,
                    'absolute_max_age' => $absoluteMaxAge,
                ]);

                // Session is truly over — clean up push tokens asynchronously
                if (isset($decoded->sub)) {
                    dispatch(new CleanupUserPushSubscriptions($decoded->sub));
                }

                $errorResponse = $this->errorResponse('Session expired. Please login again.', 401);

                return $this->clearAuthCookies($errorResponse);
            }
        } else {
            // Fixed mode: iat is preserved from original login
            if ($tokenAge > $maxRefreshAge) {
                Log::info('Token refresh rejected: max refresh age exceeded', [
                    'token_age' => $tokenAge,
                    'max_refresh_age' => $maxRefreshAge,
                ]);

                // Session is truly over — clean up push tokens asynchronously
                if (isset($decoded->sub)) {
                    dispatch(new CleanupUserPushSubscriptions($decoded->sub));
                }

                $errorResponse = $this->errorResponse('Session expired. Please login again.', 401);

                return $this->clearAuthCookies($errorResponse);
            }
        }

        // Look up the user from the token's subject claim
        if (! isset($decoded->sub)) {
            $errorResponse = $this->errorResponse('Invalid token: missing subject', 401);

            return $this->clearAuthCookies($errorResponse);
        }

        $user = IdentityUser::withoutGlobalScope('partition')
            ->where('username', $decoded->sub)
            ->first();

        if (! $user) {
            Log::warning('Token refresh failed: user not found', ['username' => $decoded->sub]);
            $errorResponse = $this->errorResponse('User not found', 401);

            return $this->clearAuthCookies($errorResponse);
        }

        // Check if user is still active
        if (! $user->is_active) {
            Log::warning('Token refresh failed: user inactive', ['user_id' => $user->record_id]);
            $errorResponse = $this->errorResponse('Account is inactive', 403);

            return $this->clearAuthCookies($errorResponse);
        }

        // Atomically blacklist the old token to prevent reuse and guard against
        // concurrent refresh requests (e.g., two tabs or interceptor + proactiveRefresh racing).
        // Cache::add returns false if the key already exists, so only the first request wins.
        if (isset($decoded->jti)) {
            $ttl = isset($decoded->exp) ? max(3600, $decoded->exp - time() + 3600) : 3600;
            if (! Cache::add('jwt_blacklist_'.$decoded->jti, true, $ttl)) {
                Log::info('Token refresh rejected: concurrent refresh detected', ['jti' => $decoded->jti]);

                return $this->errorResponse('Token already refreshed', 401);
            }
        }

        // Generate new token (preserves original iat for refresh chain tracking)
        $token = $this->generateJwtToken($user, $decoded);
        $expiresIn = (int) config('jwt.expiration', ApiConstants::JWT_EXPIRATION_SECONDS);

        Log::info('Token refreshed successfully', [
            'user_id' => $user->record_id,
            'was_expired' => $tokenExpired,
        ]);

        // Build response with token in body for backward compatibility
        $response = $this->successResponse([
            'token' => $token,
            'token_type' => 'bearer',
            'expires_in' => $expiresIn,
            'max_refresh_age' => $maxRefreshAge,
            'sliding_refresh' => $slidingRefresh,
            'absolute_max_age' => $slidingRefresh ? (int) config('jwt.absolute_max_age', 31536000) : null,
        ]);

        // FE-CRIT-001: Attach new httpOnly cookie for browser clients
        return $this->attachAuthCookies($response, $token, $expiresIn);
    }

    /**
     * Decode an expired JWT token to extract its payload.
     *
     * This manually verifies the signature and decodes the payload without
     * checking the expiration claim. Used for token refresh where we allow
     * expired tokens within the max_refresh_age window.
     *
     * @param  string  $token  The expired JWT token
     * @return object|null The decoded payload or null if invalid
     */
    private function decodeExpiredToken(string $token): ?object
    {
        try {
            $parts = explode('.', $token);
            if (count($parts) !== 3) {
                return null;
            }

            [$header64, $payload64, $signature64] = $parts;

            // Verify the signature manually
            $secret = IdentityUser::getJwtSecret();
            $algorithm = config('jwt.algorithm', 'HS256');

            // Map algorithm to hash function
            $hashAlgorithm = match ($algorithm) {
                'HS256' => 'sha256',
                'HS384' => 'sha384',
                'HS512' => 'sha512',
                default => 'sha256',
            };

            $expectedSignature = hash_hmac(
                $hashAlgorithm,
                $header64.'.'.$payload64,
                $secret,
                true
            );

            $actualSignature = JWT::urlsafeB64Decode($signature64);

            // Constant-time comparison to prevent timing attacks
            if (! hash_equals($expectedSignature, $actualSignature)) {
                Log::warning('Expired token signature verification failed');

                return null;
            }

            // Decode the payload
            $payload = json_decode(JWT::urlsafeB64Decode($payload64));

            if (! $payload || ! is_object($payload)) {
                return null;
            }

            return $payload;
        } catch (\Exception $e) {
            Log::error('Failed to decode expired token', ['error' => $e->getMessage()]);

            return null;
        }
    }

    // =========================================================================
    // PASSWORD RESET
    // =========================================================================

    /**
     * Request a password reset email.
     *
     * Always returns success to prevent email enumeration.
     */
    public function forgotPassword(Request $request): JsonResponse
    {
        $this->logRequest($request, 'forgot_password', 'auth');

        try {
            $validated = $request->validate([
                'email' => 'required|email|max:'.ApiConstants::STRING_MAX_LENGTH,
            ]);
        } catch (ValidationException $e) {
            return $this->handleValidationException($e);
        }

        $emailService = app(\NewSolari\Core\Services\EmailSecurityService::class);

        // Send the email (or pretend to, if user doesn't exist)
        $emailService->sendPasswordResetEmail($validated['email']);

        // Always return success to prevent email enumeration
        return $this->successResponse([
            'message' => 'If an account with that email exists, a password reset link has been sent.',
        ]);
    }

    /**
     * Reset password using a token.
     */
    public function resetPassword(Request $request): JsonResponse
    {
        $this->logRequest($request, 'reset_password', 'auth');

        try {
            $validated = $request->validate([
                'email' => 'required|email|max:'.ApiConstants::STRING_MAX_LENGTH,
                'token' => 'required|string|size:64', // 32 bytes = 64 hex chars
                'password' => ['required', 'string', Password::min(12)->mixedCase()->numbers()->symbols()],
                'password_confirmation' => 'required|string|same:password',
            ]);
        } catch (ValidationException $e) {
            return $this->handleValidationException($e);
        }

        $emailService = app(\NewSolari\Core\Services\EmailSecurityService::class);

        // Validate the token
        $user = $emailService->validatePasswordResetToken($validated['email'], $validated['token']);

        if (! $user) {
            return $this->errorResponse('Invalid or expired password reset token.', 400);
        }

        // Update the password
        $user->password_hash = $validated['password'];
        $user->save();

        // Consume the token
        $emailService->consumePasswordResetToken($validated['email']);

        Log::info('Password reset successfully', [
            'user_id' => $user->record_id,
            'ip' => $request->ip(),
        ]);

        return $this->successResponse([
            'message' => 'Password has been reset successfully. You can now log in with your new password.',
        ]);
    }

    // =========================================================================
    // EMAIL VERIFICATION
    // =========================================================================

    /**
     * Verify email using a token.
     */
    public function verifyEmail(Request $request): JsonResponse
    {
        $this->logRequest($request, 'verify_email', 'auth');

        try {
            $validated = $request->validate([
                'token' => 'required|string|size:64', // 32 bytes = 64 hex chars
            ]);
        } catch (ValidationException $e) {
            return $this->handleValidationException($e);
        }

        $emailService = app(\NewSolari\Core\Services\EmailSecurityService::class);

        // Consume the token and verify the user
        $user = $emailService->consumeEmailVerificationToken($validated['token']);

        if (! $user) {
            return $this->errorResponse('Invalid or expired verification token.', 400);
        }

        Log::info('Email verified successfully', [
            'user_id' => $user->record_id,
            'ip' => $request->ip(),
        ]);

        return $this->successResponse([
            'message' => 'Email verified successfully. You can now log in.',
            'verified' => true,
        ]);
    }

    /**
     * Resend verification email.
     *
     * Always returns success to prevent email enumeration.
     */
    public function resendVerificationEmail(Request $request): JsonResponse
    {
        $this->logRequest($request, 'resend_verification', 'auth');

        try {
            $validated = $request->validate([
                'email' => 'required|email|max:'.ApiConstants::STRING_MAX_LENGTH,
            ]);
        } catch (ValidationException $e) {
            return $this->handleValidationException($e);
        }

        $emailService = app(\NewSolari\Core\Services\EmailSecurityService::class);

        // Resend the email (or pretend to, if user doesn't exist)
        $emailService->resendVerificationEmail($validated['email']);

        // Always return success to prevent email enumeration
        return $this->successResponse([
            'message' => 'If an account with that email exists and requires verification, a new verification link has been sent.',
        ]);
    }

    /**
     * Send verification email for the currently authenticated user.
     *
     * Used when allow_unverified_login is enabled and user clicks the warning banner.
     * This is a convenience endpoint for logged-in users to request their own
     * verification email without having to provide their email address.
     *
     * @authenticated
     */
    public function sendMyVerificationEmail(Request $request): JsonResponse
    {
        $this->logRequest($request, 'send_my_verification_email', 'auth');

        $user = $this->getAuthenticatedUser($request);

        if (! $user) {
            return $this->errorResponse('Unauthenticated', 401);
        }

        // Check if already verified
        if (! $user->needsEmailVerification()) {
            return $this->successResponse([
                'message' => 'Email is already verified.',
                'already_verified' => true,
            ]);
        }

        // Check if user has an email address
        if (! $user->email) {
            return $this->errorResponse('No email address on file. Please add an email address to your account.', 400);
        }

        try {
            $emailService = app(\NewSolari\Core\Services\EmailSecurityService::class);
            $emailService->sendVerificationEmail($user);

            Log::info('Verification email sent via authenticated request', [
                'user_id' => $user->record_id,
                'ip' => $request->ip(),
            ]);

            return $this->successResponse([
                'message' => 'Verification email sent. Please check your inbox.',
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to send verification email', [
                'user_id' => $user->record_id,
                'error' => $e->getMessage(),
            ]);

            return $this->errorResponse('Failed to send verification email. Please try again later.', 500);
        }
    }

    // =========================================================================
    // AUTH CONFIGURATION
    // =========================================================================

    /**
     * Get authentication configuration.
     *
     * Returns available authentication methods based on AUTH_MODE.
     *
     * @unauthenticated
     */
    public function getAuthConfig(): JsonResponse
    {
        $mode = config('passkeys.mode', 'hybrid');

        return $this->successResponse([
            'mode' => $mode,
            'passkeys_enabled' => $mode !== 'passwords_only',
            'passwords_enabled' => $mode !== 'passkeys_only',
        ]);
    }

    // =========================================================================
    // ACCOUNT RECOVERY (FOR PASSKEY USERS)
    // =========================================================================

    /**
     * Request account recovery email.
     *
     * Sends a magic link to reset passkeys/regain account access.
     * Always returns success to prevent email enumeration.
     *
     * @unauthenticated
     */
    public function requestRecovery(Request $request): JsonResponse
    {
        $this->logRequest($request, 'request_recovery', 'auth');

        try {
            $validated = $request->validate([
                'email' => 'required|email|max:'.ApiConstants::STRING_MAX_LENGTH,
            ]);
        } catch (ValidationException $e) {
            return $this->handleValidationException($e);
        }

        $emailService = app(\NewSolari\Core\Services\EmailSecurityService::class);

        // Send recovery email (or pretend to, if user doesn't exist)
        $emailService->sendAccountRecoveryEmail($validated['email']);

        // Always return success to prevent email enumeration
        return $this->successResponse([
            'message' => 'If an account with that email exists, a recovery link has been sent.',
        ]);
    }

    /**
     * Verify recovery token and return temporary auth token.
     *
     * The temporary token allows the user to register a new passkey ONLY.
     * It cannot be used for general API access (has purpose: 'recovery' claim).
     *
     * @unauthenticated
     */
    public function verifyRecovery(Request $request): JsonResponse
    {
        $this->logRequest($request, 'verify_recovery', 'auth');

        try {
            $validated = $request->validate([
                'email' => 'required|email|max:'.ApiConstants::STRING_MAX_LENGTH,
                'token' => 'required|string|size:64', // 32 bytes = 64 hex chars
            ]);
        } catch (ValidationException $e) {
            return $this->handleValidationException($e);
        }

        $emailService = app(\NewSolari\Core\Services\EmailSecurityService::class);

        // Validate the recovery token
        $user = $emailService->validateAccountRecoveryToken($validated['email'], $validated['token']);

        if (! $user) {
            return $this->errorResponse('Invalid or expired recovery link.', 400);
        }

        // Consume the token
        $emailService->consumeAccountRecoveryToken($validated['email']);

        // Generate a LIMITED PURPOSE token for passkey registration only
        // This token has 'purpose: recovery' and cannot be used for general API access
        $tempToken = $this->generateLimitedPurposeToken($user, 'recovery');
        $expiresIn = 600; // 10 minutes for recovery token

        Log::info('Account recovery verified', [
            'user_id' => $user->record_id,
            'ip' => $request->ip(),
        ]);

        // Return response with LIMITED token for passkey registration
        // Do NOT attach auth cookies - user is not fully authenticated yet
        return $this->successResponse([
            'message' => 'Recovery verified. Please set up a new passkey.',
            'token' => $tempToken,
            'token_type' => 'bearer',
            'expires_in' => $expiresIn,
            'user' => [
                'id' => $user->record_id,
                'username' => $user->username,
            ],
        ]);
    }

    /**
     * Clear authentication cookies on logout.
     *
     * @param  JsonResponse  $response  The response to clear cookies from
     * @return JsonResponse  The response with cookies cleared
     */
    private function clearAuthCookies(JsonResponse $response): JsonResponse
    {
        $cookieConfig = config('jwt.cookie');
        $csrfConfig = config('jwt.csrf_cookie');

        // Clear JWT cookie
        $response->cookie(
            $cookieConfig['name'],
            '',
            -1, // Expire immediately
            $cookieConfig['path'],
            $cookieConfig['domain'],
            $cookieConfig['secure'],
            $cookieConfig['http_only'],
            false,
            $cookieConfig['same_site']
        );

        // Clear CSRF cookie
        $response->cookie(
            $csrfConfig['name'],
            '',
            -1,
            $csrfConfig['path'],
            $csrfConfig['domain'],
            $csrfConfig['secure'],
            $csrfConfig['http_only'],
            false,
            $csrfConfig['same_site']
        );

        return $response;
    }

    /**
     * Generate a LIMITED PURPOSE JWT token.
     *
     * This token can ONLY be used for specific purposes (e.g., passkey registration during recovery).
     * It has a 'purpose' claim that must be checked by endpoints that accept it.
     * General API endpoints will reject tokens with a purpose claim.
     *
     * @param  IdentityUser  $user  The user to generate token for
     * @param  string  $purpose  The limited purpose (e.g., 'recovery', 'passkey_registration')
     * @param  int  $ttl  Token TTL in seconds (default 600 = 10 minutes)
     */
    private function generateLimitedPurposeToken(IdentityUser $user, string $purpose, int $ttl = 600): string
    {
        $currentTime = time();
        $expiresAt = $currentTime + $ttl;

        $payload = [
            'iss' => config('jwt.issuer', config('app.url', 'webos')),
            'sub' => $user->username,
            'user_id' => $user->record_id,
            'partition_id' => $user->partition_id,
            'is_system_user' => $user->is_system_user,
            'iat' => $currentTime,
            'exp' => $expiresAt,
            'jti' => Str::random(ApiConstants::JWT_JTI_LENGTH),
            'purpose' => $purpose, // LIMITED PURPOSE - this token cannot be used for general API access
        ];

        return JWT::encode($payload, IdentityUser::getJwtSecret(), config('jwt.algorithm', 'HS256'));
    }
}
