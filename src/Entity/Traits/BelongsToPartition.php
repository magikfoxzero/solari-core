<?php

namespace NewSolari\Core\Entity\Traits;

use NewSolari\Core\Contracts\IdentityPartitionContract;
use NewSolari\Core\Entity\Scopes\PartitionScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Trait for models that belong to a partition (multi-tenant).
 *
 * This trait adds:
 * - Global scope for automatic partition filtering
 * - Relationship to the partition
 * - Helper scopes for querying across partitions
 *
 * To opt out of partition scope (for child tables that inherit partition
 * from their parent), set the property in your model:
 *
 *     protected $hasPartitionScope = false;
 *
 * @see DB-HIGH-002 - Missing Global Partition Scopes
 */
trait BelongsToPartition
{
    /**
     * Boot the BelongsToPartition trait.
     *
     * Only adds the partition scope if the model has $hasPartitionScope = true
     * (which is the default) or if the property is not defined.
     */
    public static function bootBelongsToPartition(): void
    {
        $instance = new static;

        // Allow models to opt out of partition scope by setting $hasPartitionScope = false
        // This is useful for child tables that don't have their own partition_id column
        // (e.g., FileVersion, EntityAddress, TaskChecklist)
        if (property_exists($instance, 'hasPartitionScope') && $instance->hasPartitionScope === false) {
            return;
        }

        // Check if model actually has partition_id in its fillable or table schema
        // If not, skip adding the scope to prevent SQL errors
        if (! in_array('partition_id', $instance->getFillable()) &&
            ! property_exists($instance, 'hasPartitionScope')) {
            return;
        }

        static::addGlobalScope(PartitionScope::SCOPE_NAME, new PartitionScope);
    }

    /**
     * Get the partition this model belongs to.
     *
     * Note: This method is only defined if the model doesn't already have it.
     * Most models already define their own partition() relationship.
     *
     * To use: If your model doesn't have partition(), you can call:
     *   $this->getPartitionRelation()
     */
    public function getPartitionRelation(): BelongsTo
    {
        $partitionModel = app('identity.partition_model');
        return $this->belongsTo(get_class($partitionModel), 'partition_id', 'record_id');
    }

    /**
     * Scope to query records in a specific partition.
     * Removes the automatic partition scope and filters by the given partition.
     */
    public function scopeInPartition(Builder $query, string $partitionId): Builder
    {
        return $query->withoutGlobalScope(PartitionScope::SCOPE_NAME)
            ->where($this->getTable().'.partition_id', $partitionId);
    }

    /**
     * Scope to query records without partition filtering.
     * Use with caution - only for admin/system operations.
     */
    public function scopeWithoutPartitionScope(Builder $query): Builder
    {
        return $query->withoutGlobalScope(PartitionScope::SCOPE_NAME);
    }

    /**
     * Check if this model belongs to the given partition.
     */
    public function belongsToPartitionId(string $partitionId): bool
    {
        return $this->partition_id === $partitionId;
    }
}
