<?php

namespace NewSolari\Core\Entity\Traits;

use App\Broadcasting\Events\ChannelAccessRevoked;
use NewSolari\Core\Contracts\IdentityUserContract;
use NewSolari\Core\Entity\Models\RecordShare;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Shareable trait for entities that can be shared with specific users.
 *
 * This trait provides record sharing functionality, allowing users to share
 * private records with specific users in their partition without making
 * them fully public.
 *
 * Usage:
 * 1. Add the trait to your model: use Shareable;
 * 2. Ensure your model has partition_id and created_by fields
 */
trait Shareable
{
    /**
     * Boot the Shareable trait.
     * Cleans up shares when entity is deleted.
     */
    public static function bootShareable(): void
    {
        static::deleting(function ($model) {
            // Broadcast access revocation to all shared users before deleting shares
            $channelInfo = $model->getBroadcastChannelInfo();
            if ($channelInfo) {
                $sharedUserIds = $model->activeShares()->pluck('shared_with_user_id')->toArray();
                foreach ($sharedUserIds as $sharedUserId) {
                    event(new ChannelAccessRevoked(
                        $sharedUserId,
                        $channelInfo['type'],
                        $channelInfo['id'],
                        'resource_deleted'
                    ));
                }
            }

            // Soft delete all shares for this entity
            // In CLI context (artisan commands, queue jobs), Auth::id() returns null
            // Fall back to model's created_by, then to 'system' to maintain audit trail
            $userId = Auth::id();
            if ($userId === null) {
                // Log CLI context deletion for audit purposes
                $userId = $model->created_by ?? 'system';
                if (app()->runningInConsole()) {
                    \Log::info('Shareable: Deleting shares in CLI context', [
                        'model_type' => get_class($model),
                        'model_id' => $model->getKey(),
                        'deleted_by' => $userId,
                    ]);
                }
            }

            // Update shares - the operation is already within the model's transaction context
            // Avoid nesting DB::transaction() to prevent SQLite issues
            try {
                $model->shares()->update([
                    'deleted' => true,
                    'deleted_by' => $userId,
                ]);
            } catch (\Exception $e) {
                \Log::warning('Failed to soft delete shares during model deletion', [
                    'model_type' => get_class($model),
                    'model_id' => $model->getKey(),
                    'error' => $e->getMessage(),
                ]);
            }
        });
    }

    /**
     * Get all shares for this entity.
     */
    public function shares(): MorphMany
    {
        return $this->morphMany(
            RecordShare::class,
            'shareable',
            'shareable_type',
            'shareable_id',
            $this->getKeyName()
        );
    }

    /**
     * Get active (non-expired, non-deleted) shares.
     */
    public function activeShares(): MorphMany
    {
        return $this->shares()->active();
    }

    /**
     * Get the shareable type key for polymorphic queries.
     */
    public function getShareableType(): string
    {
        // Use the morph class (respects the morphMap in AppServiceProvider)
        return $this->getMorphClass();
    }

    /**
     * Share this record with a user.
     *
     * SECURITY: Uses transaction with pessimistic locking to prevent TOCTOU race conditions.
     * All checks (permission, existing share lookup, delete, create) happen atomically.
     *
     * @throws \InvalidArgumentException
     */
    public function shareWith(
        IdentityUserContract $recipient,
        IdentityUserContract $sharer,
        string $permission = 'read',
        ?string $message = null,
        ?\DateTimeInterface $expiresAt = null
    ): RecordShare {
        // Validate: Cannot share with self
        if ($recipient->record_id === $sharer->record_id) {
            throw new \InvalidArgumentException('Cannot share a record with yourself');
        }

        // Validate: Recipient must be in same partition
        if ($recipient->partition_id !== $this->partition_id) {
            throw new \InvalidArgumentException('Cannot share with users outside your partition');
        }

        // Validate: Sharer must have permission (owner or admin)
        if (!$this->canUserShare($sharer)) {
            throw new \InvalidArgumentException('You do not have permission to share this record');
        }

        // Use lockForUpdate only on databases that support it (not SQLite)
        $useLocking = config('database.default') !== 'sqlite';

        // ATOMIC OPERATION: Wrap all operations in a transaction with pessimistic locking
        return DB::transaction(function () use ($recipient, $sharer, $permission, $message, $expiresAt, $useLocking) {
            // Find existing shares with lock to prevent race conditions
            $existingSharesQuery = RecordShare::where('shareable_id', $this->getKey())
                ->where('shareable_type', $this->getShareableType())
                ->where('shared_with_user_id', $recipient->record_id)
                ->where('partition_id', $this->partition_id);

            if ($useLocking) {
                $existingSharesQuery->lockForUpdate();
            }

            $existingShares = $existingSharesQuery->get();

            // Soft delete any existing shares (now safe due to lock)
            foreach ($existingShares as $share) {
                $share->deleted = true;
                $share->deleted_by = $sharer->record_id;
                $share->save();
            }

            // Create new share within same transaction
            return RecordShare::createWithValidation([
                'shareable_type' => $this->getShareableType(),
                'shareable_id' => $this->getKey(),
                'shared_with_user_id' => $recipient->record_id,
                'permission' => $permission,
                'shared_by' => $sharer->record_id,
                'share_message' => $message,
                'expires_at' => $expiresAt,
                'partition_id' => $this->partition_id,
                'created_by' => $sharer->record_id,
            ]);
        });
    }

    /**
     * Revoke a share.
     * Broadcasts access revocation if the model supports broadcast channels.
     *
     * SECURITY: Uses transaction with pessimistic locking to prevent TOCTOU race conditions.
     */
    public function unshareWith(IdentityUserContract $recipient, IdentityUserContract $revoker): bool
    {
        // Validate: Revoker must have permission
        if (!$this->canUserShare($revoker)) {
            throw new \InvalidArgumentException('You do not have permission to revoke shares on this record');
        }

        // Use lockForUpdate only on databases that support it (not SQLite)
        $useLocking = config('database.default') !== 'sqlite';

        // ATOMIC OPERATION: Wrap all operations in a transaction with pessimistic locking
        return DB::transaction(function () use ($recipient, $revoker, $useLocking) {
            $shareQuery = $this->shares()
                ->where('shared_with_user_id', $recipient->record_id)
                ->where('deleted', false);

            if ($useLocking) {
                $shareQuery->lockForUpdate();
            }

            $share = $shareQuery->first();

            if (!$share) {
                return false;
            }

            $share->deleted = true;
            $share->deleted_by = $revoker->record_id;
            $saved = $share->save();

            // Broadcast access revocation if model supports it
            if ($saved) {
                $this->broadcastAccessRevocation($recipient->record_id, 'share_revoked');
            }

            return $saved;
        });
    }

    /**
     * Get broadcast channel info for this model.
     * Override in child classes to enable channel access revocation broadcasts.
     *
     * @return array|null Array with 'type' and 'id' keys, or null if no broadcast
     */
    protected function getBroadcastChannelInfo(): ?array
    {
        return null;
    }

    /**
     * Broadcast access revocation for this record.
     * Only broadcasts if getBroadcastChannelInfo() returns channel info.
     */
    protected function broadcastAccessRevocation(string $userId, string $reason = 'access_revoked'): void
    {
        $channelInfo = $this->getBroadcastChannelInfo();
        if ($channelInfo) {
            event(new ChannelAccessRevoked(
                $userId,
                $channelInfo['type'],
                $channelInfo['id'],
                $reason
            ));
        }
    }

    /**
     * Check if this record is shared with a specific user.
     */
    public function isSharedWith(IdentityUserContract $user): bool
    {
        return $this->activeShares()
            ->where('shared_with_user_id', $user->record_id)
            ->exists();
    }

    /**
     * Get the share permission for a user.
     */
    public function getSharePermission(IdentityUserContract $user): ?string
    {
        $share = $this->activeShares()
            ->where('shared_with_user_id', $user->record_id)
            ->first();

        return $share?->permission;
    }

    /**
     * Get all users this record is shared with.
     */
    public function getSharedWithUsers(): Collection
    {
        return $this->activeShares()
            ->with('sharedWithUser')
            ->get()
            ->pluck('sharedWithUser')
            ->filter();
    }

    /**
     * Check if a user can share this record.
     * Allowed: owner, partition admin, system admin
     */
    public function canUserShare(IdentityUserContract $user): bool
    {
        // System admin
        if ($user->is_system_user) {
            return true;
        }

        // Must be in same partition
        if ($user->partition_id !== $this->partition_id) {
            return false;
        }

        // Partition admin
        if ($user->isPartitionAdmin($this->partition_id)) {
            return true;
        }

        // Owner
        return $this->created_by === $user->record_id;
    }

    /**
     * Check if user can access via share (for authorization checks).
     */
    public function userHasShareAccess(IdentityUser $user, string $action = 'view'): bool
    {
        // Query directly with both shareable_id and shareable_type for proper polymorphic matching
        $share = RecordShare::where('shareable_id', $this->getKey())
            ->where('shareable_type', $this->getShareableType())
            ->where('shared_with_user_id', $user->record_id)
            ->where('deleted', false)
            ->where(function ($q) {
                $q->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->first();

        if (!$share) {
            return false;
        }

        return $share->canUserAccess($user, $action);
    }
}
