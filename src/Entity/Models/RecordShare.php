<?php

namespace NewSolari\Core\Entity\Models;

use NewSolari\Core\Entity\BaseEntity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * RecordShare model for managing record sharing between users.
 *
 * Allows users to share private records with specific users in their partition,
 * providing granular access control beyond just public/private settings.
 */
class RecordShare extends BaseEntity
{
    protected $table = 'record_shares';

    protected $fillable = [
        'record_id',
        'shareable_type',
        'shareable_id',
        'shared_with_user_id',
        'permission',
        'shared_by',
        'expires_at',
        'share_message',
        'partition_id',
        'created_by',
        'updated_by',
        'deleted',
        'deleted_by',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'deleted' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected $validations = [
        'shareable_type' => 'required|string|max:64',
        'shareable_id' => 'required|string|max:64',
        'shared_with_user_id' => 'required|string|max:64',
        'permission' => 'required|in:read,write',
        'shared_by' => 'required|string|max:64',
        'partition_id' => 'required|string|max:64',
        'created_by' => 'required|string|max:64',
        'expires_at' => 'nullable|date',
        'share_message' => 'nullable|string|max:500',
    ];

    // Relationships

    /**
     * Get the shareable entity (polymorphic).
     */
    public function shareable(): MorphTo
    {
        return $this->morphTo('shareable', 'shareable_type', 'shareable_id', 'record_id');
    }

    /**
     * Get the user this record is shared with.
     */
    public function sharedWithUser(): BelongsTo
    {
        return $this->belongsTo(app('identity.user_model'), 'shared_with_user_id', 'record_id');
    }

    /**
     * Get the user who created the share.
     */
    public function sharedByUser(): BelongsTo
    {
        return $this->belongsTo(app('identity.user_model'), 'shared_by', 'record_id');
    }

    /**
     * Get the partition this share belongs to.
     */
    public function partition(): BelongsTo
    {
        return $this->belongsTo(app('identity.partition_model'), 'partition_id', 'record_id');
    }

    // Scopes

    /**
     * Scope to filter shares for a specific entity.
     */
    public function scopeForEntity(Builder $query, string $type, string $id): Builder
    {
        return $query->where('shareable_type', $type)->where('shareable_id', $id);
    }

    /**
     * Scope to filter shares for a specific user.
     */
    public function scopeForUser(Builder $query, string $userId): Builder
    {
        return $query->where('shared_with_user_id', $userId);
    }

    /**
     * Scope to filter active (non-expired, non-deleted) shares.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('deleted', false)
            ->where(function ($q) {
                $q->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            });
    }

    /**
     * Scope to filter shares within a partition.
     */
    public function scopeInPartition(Builder $query, string $partitionId): Builder
    {
        return $query->where('partition_id', $partitionId);
    }

    /**
     * Scope to filter shares with a specific permission level.
     */
    public function scopeWithPermission(Builder $query, string $permission): Builder
    {
        if ($permission === 'write') {
            return $query->where('permission', 'write');
        }
        // 'read' includes both read and write permissions
        return $query->whereIn('permission', ['read', 'write']);
    }

    // Helper methods

    /**
     * Check if this share has expired.
     * Uses explicit now() comparison for consistency with scopeActive.
     */
    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at < now();
    }

    /**
     * Check if this share grants write permission.
     */
    public function hasWritePermission(): bool
    {
        return $this->permission === 'write';
    }

    /**
     * Check if a user can access the shared record for a given action.
     */
    public function canUserAccess(\NewSolari\Core\Contracts\IdentityUserContract $user, string $action): bool
    {
        if ($this->deleted || $this->isExpired()) {
            return false;
        }

        if ($this->shared_with_user_id !== $user->record_id) {
            return false;
        }

        // 'view' allowed for both read and write
        if ($action === 'view') {
            return true;
        }

        // 'update' allowed only for write permission
        if ($action === 'update') {
            return $this->hasWritePermission();
        }

        // 'delete' never allowed via share (only owner/admin can delete)
        return false;
    }
}
