<?php

namespace NewSolari\Core\Identity\Models;

use NewSolari\Core\Entity\BaseEntity;
use NewSolari\Core\Identity\Models\IdentityUser;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Cache;

/**
 * UserSoftBan model - Tracks users who have been soft-banned by admins.
 *
 * Soft-banned users can still use the app normally from their perspective,
 * but their content (bottles, messages, etc.) is silently hidden from other users.
 */
class UserSoftBan extends BaseEntity
{
    /**
     * The table associated with the model.
     */
    protected $table = 'user_soft_bans';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'record_id',
        'user_id',
        'banned_by',
        'reason',
        'banned_until',
        'partition_id',
        'deleted',
        'deleted_by',
        'deleted_at',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'record_id' => 'string',
        'user_id' => 'string',
        'banned_by' => 'string',
        'reason' => 'string',
        'banned_until' => 'datetime',
        'partition_id' => 'string',
        'deleted' => 'boolean',
        'deleted_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Cache TTL for soft ban lookups (5 minutes).
     */
    private const BAN_CACHE_TTL = 300;

    /**
     * Get the cache key for a user's soft ban status.
     */
    private static function banCacheKey(string $userId): string
    {
        return "soft_ban:{$userId}";
    }

    /**
     * Get the user who was soft-banned.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(IdentityUser::class, 'user_id', 'record_id');
    }

    /**
     * Get the admin who issued the soft ban.
     */
    public function bannedByUser(): BelongsTo
    {
        return $this->belongsTo(IdentityUser::class, 'banned_by', 'record_id');
    }

    /**
     * Check if a user is currently soft-banned.
     * Result is cached for 5 minutes.
     */
    public static function isUserSoftBanned(string $userId): bool
    {
        return Cache::remember(self::banCacheKey($userId), self::BAN_CACHE_TTL, function () use ($userId) {
            return self::where('user_id', $userId)
                ->where('deleted', false)
                ->where(function ($query) {
                    $query->whereNull('banned_until')
                        ->orWhere('banned_until', '>', now());
                })
                ->exists();
        });
    }

    /**
     * Get the active soft ban for a user, or null if not banned.
     */
    public static function getActiveBan(string $userId): ?self
    {
        return self::where('user_id', $userId)
            ->where('deleted', false)
            ->where(function ($query) {
                $query->whereNull('banned_until')
                    ->orWhere('banned_until', '>', now());
            })
            ->first();
    }

    /**
     * Clear the cached soft ban status for a user.
     */
    public static function clearBanCache(string $userId): void
    {
        Cache::forget(self::banCacheKey($userId));
    }
}
