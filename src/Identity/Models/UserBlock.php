<?php

namespace NewSolari\Core\Identity\Models;

use NewSolari\Core\Entity\BaseEntity;
use NewSolari\Core\Identity\Models\IdentityUser;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

/**
 * UserBlock model - Tracks users who have blocked other users.
 *
 * When User A blocks User B:
 * - User A won't see User B's bottles in the ocean
 * - User A can't send/receive pen pal requests with User B
 * - User A can't send/receive messages with User B
 * - User B also can't see User A's bottles (bidirectional)
 * - User B also can't message User A
 */
class UserBlock extends BaseEntity
{
    /**
     * The table associated with the model.
     */
    protected $table = 'user_blocks';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'record_id',
        'blocker_user_id',
        'blocked_user_id',
        'reason',
        'partition_id',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'record_id' => 'string',
        'blocker_user_id' => 'string',
        'blocked_user_id' => 'string',
        'reason' => 'string',
        'partition_id' => 'string',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Validation rules.
     */
    protected $validations = [
        'record_id' => 'nullable|string|max:36',
        'partition_id' => 'required|string|max:36|exists:identity_partitions,record_id',
        'blocker_user_id' => 'required|string|max:36|exists:identity_users,record_id',
        'blocked_user_id' => 'required|string|max:36|exists:identity_users,record_id',
        'reason' => 'nullable|string|max:500',
    ];

    /**
     * Get the user who initiated the block.
     */
    public function blocker(): BelongsTo
    {
        return $this->belongsTo(IdentityUser::class, 'blocker_user_id', 'record_id');
    }

    /**
     * Get the user who was blocked.
     */
    public function blocked(): BelongsTo
    {
        return $this->belongsTo(IdentityUser::class, 'blocked_user_id', 'record_id');
    }

    /**
     * Check if a user has blocked another user.
     *
     * @param string $blockerId The user who may have blocked
     * @param string $blockedId The user who may be blocked
     */
    public static function isBlocked(string $blockerId, string $blockedId): bool
    {
        return self::where('blocker_user_id', $blockerId)
            ->where('blocked_user_id', $blockedId)
            ->exists();
    }

    /**
     * Check if there is any block relationship between two users (in either direction).
     *
     * @param string $userId1 First user
     * @param string $userId2 Second user
     */
    public static function hasBlockBetween(string $userId1, string $userId2): bool
    {
        return self::where(function ($query) use ($userId1, $userId2) {
            $query->where('blocker_user_id', $userId1)
                ->where('blocked_user_id', $userId2);
        })->orWhere(function ($query) use ($userId1, $userId2) {
            $query->where('blocker_user_id', $userId2)
                ->where('blocked_user_id', $userId1);
        })->exists();
    }

    /**
     * Get all user IDs that a user has blocked.
     *
     * @param string $userId The user whose blocked list to retrieve
     * @return array Array of blocked user IDs
     */
    public static function getBlockedUserIds(string $userId): array
    {
        return self::where('blocker_user_id', $userId)
            ->pluck('blocked_user_id')
            ->toArray();
    }

    /**
     * Get all user IDs who have blocked a specific user.
     *
     * @param string $userId The user who may be blocked by others
     * @return array Array of user IDs who blocked this user
     */
    public static function getBlockerUserIds(string $userId): array
    {
        return self::where('blocked_user_id', $userId)
            ->pluck('blocker_user_id')
            ->toArray();
    }

    /**
     * Get all user IDs that should be excluded from interaction with a user.
     * This includes both users the user has blocked AND users who have blocked the user.
     *
     * @param string $userId The user to get exclusions for
     * @return array Array of user IDs to exclude
     */
    public static function getExcludedUserIds(string $userId): array
    {
        $blocked = self::getBlockedUserIds($userId);
        $blockers = self::getBlockerUserIds($userId);

        return array_unique(array_merge($blocked, $blockers));
    }

    /**
     * Bulk-load excluded user IDs for multiple users (2 queries instead of 2N).
     *
     * @param Collection $userIds Collection of user IDs to get exclusions for
     * @param string|null $partitionId Optional partition filter for defense-in-depth tenant isolation
     * @return Collection Keyed by user_id, each value is an array of excluded user IDs
     */
    public static function bulkGetExcludedUserIds(Collection $userIds, ?string $partitionId = null): Collection
    {
        $result = collect();

        // Initialize empty arrays for all users
        foreach ($userIds as $userId) {
            $result->put($userId, []);
        }

        // Get all blocks where these users are the blocker
        // Use withoutPartitionScope() since this is called from background jobs
        // Chunk whereIn to avoid exceeding MySQL placeholder limits at scale
        foreach ($userIds->chunk(500) as $chunk) {
            $blockedQuery = self::withoutPartitionScope()
                ->whereIn('blocker_user_id', $chunk);
            if ($partitionId) {
                $blockedQuery->where('partition_id', $partitionId);
            }
            foreach ($blockedQuery->cursor() as $record) {
                $current = $result->get($record->blocker_user_id, []);
                $current[] = $record->blocked_user_id;
                $result->put($record->blocker_user_id, $current);
            }
        }

        // Get all blocks where these users are the blocked
        foreach ($userIds->chunk(500) as $chunk) {
            $blockerQuery = self::withoutPartitionScope()
                ->whereIn('blocked_user_id', $chunk);
            if ($partitionId) {
                $blockerQuery->where('partition_id', $partitionId);
            }
            foreach ($blockerQuery->cursor() as $record) {
                $current = $result->get($record->blocked_user_id, []);
                $current[] = $record->blocker_user_id;
                $result->put($record->blocked_user_id, $current);
            }
        }

        // Deduplicate
        return $result->map(fn ($ids) => array_values(array_unique($ids)));
    }

    /**
     * Block a user.
     *
     * @param string $blockerId The user initiating the block
     * @param string $blockedId The user being blocked
     * @param string $partitionId The partition ID
     * @param string|null $reason Optional reason for blocking
     * @return self|null The created block record, or null if already blocked
     */
    public static function blockUser(
        string $blockerId,
        string $blockedId,
        string $partitionId,
        ?string $reason = null
    ): ?self {
        // Can't block yourself
        if ($blockerId === $blockedId) {
            return null;
        }

        // Check if already blocked
        if (self::isBlocked($blockerId, $blockedId)) {
            return null;
        }

        return self::create([
            'blocker_user_id' => $blockerId,
            'blocked_user_id' => $blockedId,
            'partition_id' => $partitionId,
            'reason' => $reason,
        ]);
    }

    /**
     * Unblock a user.
     *
     * @param string $blockerId The user who initiated the block
     * @param string $blockedId The user who was blocked
     * @return bool True if unblocked, false if no block existed
     */
    public static function unblockUser(string $blockerId, string $blockedId): bool
    {
        return self::where('blocker_user_id', $blockerId)
            ->where('blocked_user_id', $blockedId)
            ->delete() > 0;
    }

    /**
     * Get the block record between two users (if blocker blocked blockedId).
     *
     * @param string $blockerId The user who may have blocked
     * @param string $blockedId The user who may be blocked
     */
    public static function findBlock(string $blockerId, string $blockedId): ?self
    {
        return self::where('blocker_user_id', $blockerId)
            ->where('blocked_user_id', $blockedId)
            ->first();
    }
}
