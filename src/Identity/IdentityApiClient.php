<?php

namespace NewSolari\Core\Identity;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use NewSolari\Core\Identity\Models\IdentityUser;
use NewSolari\Core\Identity\Models\IdentityPartition;

/**
 * HTTP client for the identity service.
 *
 * All reads are cached in Redis with a configurable TTL (default 5 minutes).
 * Used by modules to look up user/partition data without direct database access.
 */
class IdentityApiClient
{
    private string $baseUrl;

    private string $serviceToken;

    private int $cacheTtl;

    private bool $enabled;

    public function __construct()
    {
        $this->baseUrl = rtrim(config('services.identity.url', 'http://127.0.0.1:8170'), '/');
        $this->serviceToken = config('services.identity.service_token') ?? '';
        $this->cacheTtl = config('services.identity.cache_ttl', 300);
        $this->enabled = (bool) config('services.identity.enabled', false);
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Look up a user by ID, returning a UserContext.
     * Cached as identity_user:{userId} with configurable TTL.
     */
    public function getUser(string $userId): ?UserContext
    {
        if (! $this->enabled) {
            return $this->getUserFromDb($userId);
        }

        $cacheKey = "identity_user:{$userId}";

        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return UserContext::fromApiResponse((object) $cached);
        }

        try {
            $response = $this->http()->get("{$this->baseUrl}/service/users/{$userId}");

            if (! $response->successful()) {
                Log::warning('Identity service user lookup failed', [
                    'user_id' => $userId,
                    'status' => $response->status(),
                ]);

                return $this->getUserFromDb($userId);
            }

            $data = $response->json('result');
            if (! $data) {
                return null;
            }

            Cache::put($cacheKey, $data, $this->cacheTtl);

            return UserContext::fromApiResponse((object) $data);
        } catch (\Exception $e) {
            Log::error('Identity service user lookup error, falling back to DB', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);

            return $this->getUserFromDb($userId);
        }
    }

    private function getUserFromDb(string $userId): ?UserContext
    {
        $user = IdentityUser::withoutGlobalScope('partition')
            ->where('record_id', $userId)
            ->first();

        if (! $user) {
            return null;
        }

        $user->load(['permissions', 'groups.permissions']);

        return UserContext::fromApiResponse((object) [
            'record_id' => $user->record_id,
            'partition_id' => $user->partition_id,
            'username' => $user->username,
            'email' => $user->email,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'is_system_user' => $user->is_system_user,
            'is_active' => $user->is_active,
            'is_partition_admin' => $user->isPartitionAdmin($user->partition_id),
            'permissions' => $user->permissions->pluck('name')->values()->toArray(),
            'groups' => $user->groups->pluck('name')->values()->toArray(),
        ]);
    }

    /**
     * List users for a partition with optional filters and pagination.
     */
    public function listUsers(string $partitionId, array $filters = [], int $page = 1, int $perPage = 50): array
    {
        try {
            $query = array_merge($filters, [
                'partition_id' => $partitionId,
                'page' => $page,
                'per_page' => $perPage,
            ]);

            $response = $this->http()->get("{$this->baseUrl}/service/users", $query);

            if (! $response->successful()) {
                Log::warning('Identity service list users failed', [
                    'partition_id' => $partitionId,
                    'status' => $response->status(),
                ]);

                return [];
            }

            return $response->json('result') ?? [];
        } catch (\Exception $e) {
            Log::error('Identity service list users error', [
                'partition_id' => $partitionId,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Get permissions for a user.
     * Cached as identity_permissions:{userId} with configurable TTL.
     */
    public function getUserPermissions(string $userId): array
    {
        $cacheKey = "identity_permissions:{$userId}";

        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        try {
            $response = $this->http()->get("{$this->baseUrl}/service/users/{$userId}/permissions");

            if (! $response->successful()) {
                Log::warning('Identity service permissions lookup failed', [
                    'user_id' => $userId,
                    'status' => $response->status(),
                ]);

                return [];
            }

            $result = $response->json('result') ?? [];
            Cache::put($cacheKey, $result, $this->cacheTtl);

            return $result;
        } catch (\Exception $e) {
            Log::error('Identity service permissions lookup error', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Look up a partition by ID.
     * Cached as identity_partition:{partitionId} with configurable TTL.
     */
    public function getPartition(string $partitionId): ?array
    {
        if (! $this->enabled) {
            return $this->getPartitionFromDb($partitionId);
        }

        $cacheKey = "identity_partition:{$partitionId}";

        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        try {
            $response = $this->http()->get("{$this->baseUrl}/service/partitions/{$partitionId}");

            if (! $response->successful()) {
                Log::warning('Identity service partition lookup failed', [
                    'partition_id' => $partitionId,
                    'status' => $response->status(),
                ]);

                return $this->getPartitionFromDb($partitionId);
            }

            $result = $response->json('result');
            if ($result) {
                Cache::put($cacheKey, $result, $this->cacheTtl);
            }

            return $result;
        } catch (\Exception $e) {
            Log::error('Identity service partition lookup error, falling back to DB', [
                'partition_id' => $partitionId,
                'error' => $e->getMessage(),
            ]);

            return $this->getPartitionFromDb($partitionId);
        }
    }

    private function getPartitionFromDb(string $partitionId): ?array
    {
        $partition = IdentityPartition::withoutGlobalScope('partition')
            ->where('record_id', $partitionId)
            ->first();

        if (! $partition) {
            return null;
        }

        return $partition->toArray();
    }

    /**
     * List all partitions (e.g., for bottles RefreshLeaderboards).
     */
    public function listPartitions(): array
    {
        if (! $this->enabled) {
            return IdentityPartition::withoutGlobalScope('partition')
                ->where('is_active', true)
                ->get()
                ->map(fn ($p) => $p->toArray())
                ->toArray();
        }

        return Cache::remember('identity_partitions_all', $this->cacheTtl, function () {
            try {
                $response = $this->http()->get("{$this->baseUrl}/service/partitions");

                if (! $response->successful()) {
                    Log::warning('Identity service list partitions failed', [
                        'status' => $response->status(),
                    ]);

                    return [];
                }

                return $response->json('result') ?? [];
            } catch (\Exception $e) {
                Log::error('Identity service list partitions error', [
                    'error' => $e->getMessage(),
                ]);

                return [];
            }
        });
    }

    /**
     * List groups for a partition with pagination.
     */
    public function listGroups(string $partitionId, int $page = 1, int $perPage = 50): array
    {
        try {
            $response = $this->http()->get("{$this->baseUrl}/service/groups", [
                'partition_id' => $partitionId,
                'page' => $page,
                'per_page' => $perPage,
            ]);

            if (! $response->successful()) {
                Log::warning('Identity service list groups failed', [
                    'partition_id' => $partitionId,
                    'status' => $response->status(),
                ]);

                return [];
            }

            return $response->json('result') ?? [];
        } catch (\Exception $e) {
            Log::error('Identity service list groups error', [
                'partition_id' => $partitionId,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Get partition apps configuration.
     * Cached as identity_partition_apps:{partitionId} with configurable TTL.
     */
    public function getPartitionApps(string $partitionId): array
    {
        $cacheKey = "identity_partition_apps:{$partitionId}";
        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        try {
            $response = $this->http()->get("{$this->baseUrl}/service/partitions/{$partitionId}/apps");

            if (! $response->successful()) {
                Log::warning('Identity service partition apps lookup failed', [
                    'partition_id' => $partitionId,
                    'status' => $response->status(),
                ]);

                return [];
            }

            $result = $response->json('result') ?? [];
            Cache::put($cacheKey, $result, $this->cacheTtl);

            return $result;
        } catch (\Exception $e) {
            Log::error('Identity service partition apps lookup error', [
                'partition_id' => $partitionId,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Soft-ban a user.
     */
    public function banUser(string $userId, array $data): bool
    {
        try {
            $response = $this->http()->post("{$this->baseUrl}/service/users/{$userId}/soft-ban", $data);

            return $response->successful() && ($response->json('value') ?? false);
        } catch (\Exception $e) {
            Log::error('Identity service ban user error', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Remove a soft-ban from a user.
     */
    public function unbanUser(string $userId): bool
    {
        try {
            $response = $this->http()->delete("{$this->baseUrl}/service/users/{$userId}/soft-ban");

            return $response->successful() && ($response->json('value') ?? false);
        } catch (\Exception $e) {
            Log::error('Identity service unban user error', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Block a user.
     */
    public function blockUser(string $userId, string $blockedUserId): bool
    {
        try {
            $response = $this->http()->post("{$this->baseUrl}/service/users/{$userId}/block", [
                'blocked_user_id' => $blockedUserId,
            ]);

            return $response->successful() && ($response->json('value') ?? false);
        } catch (\Exception $e) {
            Log::error('Identity service block user error', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Unblock a user.
     */
    public function unblockUser(string $userId, string $blockedUserId): bool
    {
        try {
            $response = $this->http()->delete("{$this->baseUrl}/service/users/{$userId}/block/{$blockedUserId}");

            return $response->successful() && ($response->json('value') ?? false);
        } catch (\Exception $e) {
            Log::error('Identity service unblock user error', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Invalidate cached user and permissions data.
     */
    public function invalidateUser(string $userId): void
    {
        Cache::forget("identity_user:{$userId}");
        Cache::forget("identity_permissions:{$userId}");
    }

    /**
     * Invalidate cached partition data.
     */
    public function invalidatePartition(string $partitionId): void
    {
        Cache::forget("identity_partition:{$partitionId}");
        Cache::forget("identity_partition_apps:{$partitionId}");
        Cache::forget('identity_partitions_all');
    }

    /**
     * Create an HTTP client with service token auth, timeout, and retry.
     */
    private function http(): \Illuminate\Http\Client\PendingRequest
    {
        return Http::withToken($this->serviceToken)
            ->timeout(5)
            ->retry(2, 100)
            ->acceptJson();
    }
}
