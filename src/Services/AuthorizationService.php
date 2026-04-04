<?php

namespace NewSolari\Core\Services;

use NewSolari\Core\Identity\Models\IdentityUser;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Centralized authorization service implementing three-tier permission model:
 *
 * 1. System Admins - Can CRUD any resource in any partition
 * 2. Partition Admins - Can CRUD any resource in their respective partitions
 * 3. Regular Users - Can view public records + shared records in their partition, CRUD only their own records
 *                    (users with write share permission can also update shared records)
 */
class AuthorizationService
{
    /**
     * Check if user can perform an action on an entity.
     *
     * @param  IdentityUser  $user  The authenticated user
     * @param  string  $action  The action: 'view', 'create', 'update', 'delete'
     * @param  string  $entityPartitionId  The partition the entity belongs to
     * @param  string|null  $ownerId  The entity's owner (created_by field)
     * @param  bool  $isPublic  Whether the entity is marked as public
     * @param  Model|null  $entity  Optional entity for share-based access checks
     */
    public function authorize(
        IdentityUser $user,
        string $action,
        string $entityPartitionId,
        ?string $ownerId = null,
        bool $isPublic = false,
        ?Model $entity = null
    ): bool {
        // 1. System admins can do anything
        if ($user->is_system_user) {
            $this->logDecision($user, $action, $entityPartitionId, true, 'System admin');

            return true;
        }

        // 2. Check partition membership first
        if ($user->partition_id !== $entityPartitionId) {
            $this->logDecision($user, $action, $entityPartitionId, false, 'Cross-partition access denied');

            return false;
        }

        // Load groups if needed for partition admin check
        if (! $user->relationLoaded('groups')) {
            $user->load('groups.permissions');
        }

        // 3. Partition admins can CRUD anything in their partition
        if ($user->isPartitionAdmin($entityPartitionId)) {
            $this->logDecision($user, $action, $entityPartitionId, true, 'Partition admin');

            return true;
        }

        // 4. Regular users in same partition
        $isOwner = $ownerId !== null && $ownerId === $user->record_id;

        // View action: allowed if public OR owner OR shared
        if ($action === 'view') {
            if ($isPublic || $isOwner) {
                $reason = $isPublic ? 'Public record' : 'Owner';
                $this->logDecision($user, $action, $entityPartitionId, true, $reason);
                return true;
            }

            // Check share access
            if ($entity && $this->hasShareAccess($entity, $user, 'view')) {
                $this->logDecision($user, $action, $entityPartitionId, true, 'Shared with user');
                return true;
            }

            $this->logDecision($user, $action, $entityPartitionId, false, 'Private record, not owner, not shared');
            return false;
        }

        // Create: user is creating their own record
        if ($action === 'create') {
            $this->logDecision($user, $action, $entityPartitionId, true, 'Creating own record');
            return true;
        }

        // Update: owner OR has write share permission
        if ($action === 'update') {
            if ($isOwner) {
                $this->logDecision($user, $action, $entityPartitionId, true, 'Owner');
                return true;
            }

            // Check share access with write permission
            if ($entity && $this->hasShareAccess($entity, $user, 'update')) {
                $this->logDecision($user, $action, $entityPartitionId, true, 'Shared with user (write)');
                return true;
            }

            $this->logDecision($user, $action, $entityPartitionId, false, 'Not owner, no write share');
            return false;
        }

        // Delete: only owner can delete (shares never grant delete permission)
        if ($action === 'delete') {
            $this->logDecision($user, $action, $entityPartitionId, $isOwner,
                $isOwner ? 'Owner' : 'Not owner (delete requires ownership)');
            return $isOwner;
        }

        // Unknown action - deny by default
        $this->logDecision($user, $action, $entityPartitionId, false, 'Unknown action');

        return false;
    }

    /**
     * Check if user has share-based access to an entity.
     */
    protected function hasShareAccess(Model $entity, IdentityUser $user, string $action): bool
    {
        // Check if entity uses Shareable trait
        if (!method_exists($entity, 'userHasShareAccess')) {
            return false;
        }

        return $entity->userHasShareAccess($user, $action);
    }

    /**
     * Authorize action on an Eloquent model.
     * Automatically extracts partition_id, created_by, and is_public from the model.
     * Also checks share-based access for entities that use the Shareable trait.
     */
    public function authorizeEntity(IdentityUser $user, string $action, Model $entity): bool
    {
        $partitionId = $entity->partition_id ?? null;
        $ownerId = $entity->created_by ?? null;
        $isPublic = $entity->is_public ?? false;

        if (! $partitionId) {
            Log::warning('AuthorizationService: Entity missing partition_id', [
                'entity_class' => get_class($entity),
                'entity_id' => $entity->getKey(),
            ]);

            return false;
        }

        // Pass entity for share-based access checks
        return $this->authorize($user, $action, $partitionId, $ownerId, $isPublic, $entity);
    }

    /**
     * Check if user can access a partition (without specific entity context).
     */
    public function canAccessPartition(IdentityUser $user, string $partitionId): bool
    {
        // System admins can access any partition
        if ($user->is_system_user) {
            return true;
        }

        // Users can only access their own partition
        return $user->partition_id === $partitionId;
    }

    /**
     * Filter a query to only include records the user can access.
     * Adds partition filter and optionally public/owner/shared filter for regular users.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  bool  $includePublic  Whether to include public records (for view queries)
     * @param  bool  $includeShared  Whether to include shared records (default true)
     */
    public function scopeAccessible($query, IdentityUser $user, bool $includePublic = true, bool $includeShared = true): Builder
    {
        // System admins see everything
        if ($user->is_system_user) {
            return $query;
        }

        // Always filter by partition
        $query->where('partition_id', $user->partition_id);

        // Load groups if needed for partition admin check
        if (! $user->relationLoaded('groups')) {
            $user->load('groups.permissions');
        }

        // Partition admins see everything in their partition
        if ($user->isPartitionAdmin($user->partition_id)) {
            return $query;
        }

        // Get the table name from the query's model
        $model = $query->getModel();
        $tableName = $model->getTable();

        // Regular users see: their own records + public records (if includePublic) + shared records (if includeShared)
        $query->where(function ($q) use ($user, $includePublic, $includeShared, $tableName) {
            // Always include own records
            $q->where('created_by', $user->record_id);

            // Include public records
            if ($includePublic) {
                $q->orWhere('is_public', true);
            }

            // Include shared records
            if ($includeShared) {
                // Get the morph class (respects morphMap in AppServiceProvider)
                $morphType = $model->getMorphClass();
                $q->orWhereExists(function ($subQuery) use ($user, $tableName, $morphType) {
                    $subQuery->select(DB::raw(1))
                        ->from('record_shares')
                        ->whereColumn('record_shares.shareable_id', "{$tableName}.record_id")
                        ->where('record_shares.shareable_type', $morphType)
                        ->where('record_shares.shared_with_user_id', $user->record_id)
                        ->where('record_shares.deleted', false)
                        ->where(function ($expQ) {
                            $expQ->whereNull('record_shares.expires_at')
                                ->orWhere('record_shares.expires_at', '>', now());
                        });
                });
            }
        });

        return $query;
    }

    /**
     * Log authorization decision for audit purposes.
     */
    protected function logDecision(
        IdentityUser $user,
        string $action,
        string $partitionId,
        bool $allowed,
        string $reason
    ): void {
        // Denials should be logged at warning level for security monitoring
        $level = $allowed ? 'debug' : 'warning';

        Log::$level('Authorization decision', [
            'user_id' => $user->record_id,
            'user_partition' => $user->partition_id,
            'target_partition' => $partitionId,
            'action' => $action,
            'allowed' => $allowed,
            'reason' => $reason,
        ]);
    }
}
