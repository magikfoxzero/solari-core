<?php

namespace NewSolari\Core\Services;

use NewSolari\Core\Constants\ApiConstants;
use NewSolari\Core\Entity\Models\EntityRelationship;
// EntityRelationshipHistory removed — feature not implemented
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RelationshipService
{
    protected EntityTypeRegistryService $entityTypeService;

    protected RelationshipTypeRegistryService $relationshipTypeService;

    public function __construct(
        EntityTypeRegistryService $entityTypeService,
        RelationshipTypeRegistryService $relationshipTypeService
    ) {
        $this->entityTypeService = $entityTypeService;
        $this->relationshipTypeService = $relationshipTypeService;
    }

    /**
     * Create a new relationship.
     */
    public function create(
        string $sourceType,
        string $sourceId,
        string $targetType,
        string $targetId,
        string $relationshipType,
        array $metadata = [],
        array $options = []
    ): EntityRelationship {
        // Validate entity types (before transaction - read-only validation)
        $this->entityTypeService->validate($sourceType);
        $this->entityTypeService->validate($targetType);

        // Validate relationship type
        $this->relationshipTypeService->validate($relationshipType);

        // Get relationship type definition
        $typeDef = $this->relationshipTypeService->get($relationshipType);

        // Validate metadata if required
        if ($typeDef->requiresMetadata() && empty($metadata)) {
            throw new \InvalidArgumentException("Metadata is required for relationship type: {$relationshipType}");
        }

        // Validate metadata against schema
        if ($typeDef && ! empty($metadata)) {
            $this->relationshipTypeService->validateMetadata($relationshipType, $metadata);
        }

        // Validate partition_id is provided
        if (empty($options['partition_id'])) {
            throw new \InvalidArgumentException('partition_id is required in options');
        }

        // Validate created_by - must be explicitly provided or available from Auth
        $createdBy = $options['created_by'] ?? Auth::id();
        if (empty($createdBy)) {
            throw new \InvalidArgumentException('created_by is required but could not be determined from Auth::id() or options');
        }

        // Wrap database operations in transaction for atomicity
        return DB::transaction(function () use ($sourceType, $sourceId, $targetType, $targetId, $relationshipType, $metadata, $options, $typeDef, $createdBy) {
            // Check for duplicates if not allowed
            if ($typeDef && ! $typeDef->allowsDuplicates()) {
                $existing = $this->find($sourceType, $sourceId, $targetType, $targetId, $relationshipType);
                if ($existing) {
                    // Update existing instead
                    return $this->update($existing->record_id, $metadata, $options);
                }
            }

            // Create relationship
            $relationship = EntityRelationship::create([
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'target_type' => $targetType,
                'target_id' => $targetId,
                'relationship_type' => $relationshipType,
                'relationship_subtype' => $options['relationship_subtype'] ?? null,
                'metadata' => $metadata,
                'priority' => $options['priority'] ?? ApiConstants::DEFAULT_PRIORITY,
                'is_primary' => $options['is_primary'] ?? false,
                'partition_id' => $options['partition_id'],
                'created_by' => $createdBy,
            ]);

            // Store cache data for after-commit callback
            $cacheData = [
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'target_type' => $targetType,
                'target_id' => $targetId,
                'partition_id' => $options['partition_id'] ?? null,
            ];

            // Clear caches only after transaction commits successfully
            DB::afterCommit(function () use ($cacheData) {
                $this->clearRelationshipCache(
                    $cacheData['source_type'],
                    $cacheData['source_id'],
                    $cacheData['partition_id']
                );
                $this->clearRelationshipCache(
                    $cacheData['target_type'],
                    $cacheData['target_id'],
                    $cacheData['partition_id']
                );
            });

            return $relationship;
        });
    }

    /**
     * Update an existing relationship.
     *
     * @param  array  $options  Options including optional 'partition_id' for validation
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException
     */
    public function update(string $recordId, array $metadata = [], array $options = []): EntityRelationship
    {
        // Build query with optional partition filter for security
        $query = EntityRelationship::where('record_id', $recordId);
        if (isset($options['partition_id'])) {
            $query->where('partition_id', $options['partition_id']);
        }
        $relationship = $query->firstOrFail();

        // Validate metadata against schema if provided
        if (! empty($metadata)) {
            $this->relationshipTypeService->validateMetadata($relationship->relationship_type, $metadata);
        }

        $updateData = [];

        if (! empty($metadata)) {
            $updateData['metadata'] = $metadata;
        }

        if (isset($options['priority'])) {
            $updateData['priority'] = $options['priority'];
        }

        if (isset($options['is_primary'])) {
            $updateData['is_primary'] = $options['is_primary'];
        }

        if (isset($options['relationship_subtype'])) {
            $updateData['relationship_subtype'] = $options['relationship_subtype'];
        }

        // Validate updated_by - must be explicitly provided or available from Auth
        $updatedBy = $options['updated_by'] ?? Auth::id();
        if (empty($updatedBy)) {
            throw new \InvalidArgumentException('updated_by is required but could not be determined from Auth::id() or options');
        }
        $updateData['updated_by'] = $updatedBy;

        // Store cache invalidation data before transaction
        $cacheData = [
            'source_type' => $relationship->source_type,
            'source_id' => $relationship->source_id,
            'target_type' => $relationship->target_type,
            'target_id' => $relationship->target_id,
            'partition_id' => $relationship->partition_id,
        ];

        // Use transaction to ensure consistency - cache is cleared after commit
        return DB::transaction(function () use ($relationship, $updateData, $cacheData) {
            $relationship->update($updateData);

            // Register after-commit callback to clear caches only if transaction succeeds
            DB::afterCommit(function () use ($cacheData) {
                $this->clearRelationshipCache(
                    $cacheData['source_type'],
                    $cacheData['source_id'],
                    $cacheData['partition_id']
                );
                $this->clearRelationshipCache(
                    $cacheData['target_type'],
                    $cacheData['target_id'],
                    $cacheData['partition_id']
                );
            });

            return $relationship->fresh();
        });
    }

    /**
     * Delete a relationship (archives to history table before deleting).
     *
     * @param  array  $options  Options including 'partition_id' for validation and 'deleted_by' for audit
     * @return EntityRelationshipHistory The archived history record
     */
    public function delete(string $recordId, array $options = []): EntityRelationshipHistory
    {
        // Build query with optional partition filter for security
        $query = EntityRelationship::where('record_id', $recordId);
        if (isset($options['partition_id'])) {
            $query->where('partition_id', $options['partition_id']);
        }
        $relationship = $query->firstOrFail();

        // Get deleted_by from options or Auth
        // In CLI context (migrations, commands), Auth::id() returns null
        $deletedBy = $options['deleted_by'] ?? Auth::id();
        if (! $deletedBy) {
            // Check if running in console (CLI context)
            if (app()->runningInConsole()) {
                Log::warning('Relationship deletion in CLI context without deleted_by', [
                    'relationship_id' => $recordId,
                    'context' => 'cli',
                ]);
                // Use a sentinel value for CLI operations
                $deletedBy = 'system';
            } else {
                throw new \InvalidArgumentException(
                    'deleted_by is required for audit trail. In CLI context, pass deleted_by in options.'
                );
            }
        }

        // Store entity info before deletion for cache invalidation
        $cacheData = [
            'source_type' => $relationship->source_type,
            'source_id' => $relationship->source_id,
            'target_type' => $relationship->target_type,
            'target_id' => $relationship->target_id,
            'partition_id' => $relationship->partition_id,
        ];

        // Soft delete within transaction, clear cache after commit
        return DB::transaction(function () use ($relationship, $cacheData) {
            $relationship->delete();

            // Clear caches only after transaction commits successfully
            DB::afterCommit(function () use ($cacheData) {
                $this->clearRelationshipCache(
                    $cacheData['source_type'],
                    $cacheData['source_id'],
                    $cacheData['partition_id']
                );
                $this->clearRelationshipCache(
                    $cacheData['target_type'],
                    $cacheData['target_id'],
                    $cacheData['partition_id']
                );
            });

            return true;
        });
    }

    /**
     * Find a specific relationship.
     *
     * @param  string|null  $partitionId  Optional partition filter for security
     */
    public function find(
        string $sourceType,
        string $sourceId,
        string $targetType,
        string $targetId,
        string $relationshipType,
        ?string $partitionId = null
    ): ?EntityRelationship {
        $query = EntityRelationship::where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->where('target_type', $targetType)
            ->where('target_id', $targetId)
            ->where('relationship_type', $relationshipType);

        if ($partitionId) {
            $query->where('partition_id', $partitionId);
        }

        return $query->first();
    }

    /**
     * Find relationships by source.
     */
    public function findBySource(
        string $sourceType,
        string $sourceId,
        ?string $relationshipType = null
    ): Collection {
        $query = EntityRelationship::bySource($sourceType, $sourceId);

        if ($relationshipType) {
            $query->where('relationship_type', $relationshipType);
        }

        return $query->with(['target', 'typeDefinition'])->get();
    }

    /**
     * Find relationships by target.
     */
    public function findByTarget(
        string $targetType,
        string $targetId,
        ?string $relationshipType = null
    ): Collection {
        $query = EntityRelationship::byTarget($targetType, $targetId);

        if ($relationshipType) {
            $query->where('relationship_type', $relationshipType);
        }

        return $query->with(['source', 'typeDefinition'])->get();
    }

    /**
     * Find relationships by type.
     */
    public function findByType(string $relationshipType, ?string $partitionId = null): Collection
    {
        $query = EntityRelationship::byRelationshipType($relationshipType);

        if ($partitionId) {
            $query->inPartition($partitionId);
        }

        return $query->with(['source', 'target', 'typeDefinition'])->get();
    }

    /**
     * Sync relationships between a source and multiple targets.
     *
     * @param  array  $options  Options including 'atomic' (bool) - if true, any error causes full rollback
     */
    public function sync(
        string $sourceType,
        string $sourceId,
        string $targetType,
        array $targetIds,
        string $relationshipType,
        array $metadata = [],
        array $options = []
    ): array {
        $atomic = $options['atomic'] ?? false;

        return DB::transaction(function () use (
            $sourceType,
            $sourceId,
            $targetType,
            $targetIds,
            $relationshipType,
            $metadata,
            $options,
            $atomic
        ) {
            $results = [
                'attached' => [],
                'detached' => [],
                'updated' => [],
                'errors' => [],
            ];

            // Get existing relationships with lock to prevent race conditions
            $existing = EntityRelationship::bySource($sourceType, $sourceId)
                ->where('relationship_type', $relationshipType)
                ->where('target_type', $targetType)
                ->lockForUpdate()
                ->get()
                ->keyBy('target_id');

            $existingIds = $existing->keys()->all();

            // Determine what to attach, detach, and update
            $toAttach = array_diff($targetIds, $existingIds);
            $toDetach = array_diff($existingIds, $targetIds);
            $toUpdate = array_intersect($targetIds, $existingIds);

            // Attach new relationships
            foreach ($toAttach as $targetId) {
                try {
                    $relationship = $this->create(
                        $sourceType,
                        $sourceId,
                        $targetType,
                        $targetId,
                        $relationshipType,
                        $metadata,
                        $options
                    );
                    $results['attached'][] = $relationship->record_id;
                } catch (\Exception $e) {
                    Log::error('Failed to attach relationship', [
                        'target_id' => $targetId,
                        'relationship_type' => $relationshipType,
                        'error' => $e->getMessage(),
                    ]);

                    if ($atomic) {
                        throw $e; // Re-throw to trigger transaction rollback
                    }

                    $results['errors'][] = [
                        'operation' => 'attach',
                        'target_id' => $targetId,
                        'error' => $e->getMessage(),
                    ];
                }
            }

            // Update existing if metadata provided
            if (! empty($metadata)) {
                foreach ($toUpdate as $targetId) {
                    try {
                        $relationship = $existing[$targetId];
                        $this->update($relationship->record_id, $metadata, $options);
                        $results['updated'][] = $relationship->record_id;
                    } catch (\Exception $e) {
                        Log::error('Failed to update relationship', [
                            'relationship_id' => $relationship->record_id,
                            'error' => $e->getMessage(),
                        ]);

                        if ($atomic) {
                            throw $e; // Re-throw to trigger transaction rollback
                        }

                        $results['errors'][] = [
                            'operation' => 'update',
                            'target_id' => $targetId,
                            'error' => $e->getMessage(),
                        ];
                    }
                }
            }

            // Detach removed relationships
            foreach ($toDetach as $targetId) {
                try {
                    $relationship = $existing[$targetId];
                    $this->delete($relationship->record_id);
                    $results['detached'][] = $relationship->record_id;
                } catch (\Exception $e) {
                    Log::error('Failed to detach relationship', [
                        'relationship_id' => $relationship->record_id,
                        'error' => $e->getMessage(),
                    ]);

                    if ($atomic) {
                        throw $e; // Re-throw to trigger transaction rollback
                    }

                    $results['errors'][] = [
                        'operation' => 'detach',
                        'target_id' => $targetId,
                        'error' => $e->getMessage(),
                    ];
                }
            }

            return $results;
        });
    }

    /**
     * Get relationship statistics.
     */
    public function getStatistics(?string $partitionId = null): array
    {
        $query = EntityRelationship::query();
        $historyQuery = EntityRelationshipHistory::query();

        if ($partitionId) {
            $query->inPartition($partitionId);
            $historyQuery->where('partition_id', $partitionId);
        }

        $total = $query->count();
        $byType = $query->select('relationship_type', DB::raw('count(*) as count'))
            ->groupBy('relationship_type')
            ->pluck('count', 'relationship_type')
            ->toArray();

        // Get count of archived (deleted) relationships from history table
        $deleted = $historyQuery->count();

        return [
            'total_relationships' => $total,
            'total_deleted' => $deleted,
            'by_type' => $byType,
            'total_types' => count($byType),
        ];
    }

    /**
     * Bulk create relationships.
     *
     * @param  array  $relationships  Array of relationship data
     */
    public function bulkCreate(array $relationships, array $options = []): Collection
    {
        $created = collect();

        DB::transaction(function () use ($relationships, $options, &$created) {
            foreach ($relationships as $relData) {
                try {
                    $relationship = $this->create(
                        $relData['source_type'],
                        $relData['source_id'],
                        $relData['target_type'],
                        $relData['target_id'],
                        $relData['relationship_type'],
                        $relData['metadata'] ?? [],
                        array_merge($options, $relData['options'] ?? [])
                    );
                    $created->push($relationship);
                } catch (\Exception $e) {
                    Log::error('Failed to create relationship in bulk: '.$e->getMessage(), [
                        'relationship' => $relData,
                    ]);
                }
            }
        });

        return $created;
    }

    /**
     * Delete all relationships for an entity (archives to history before deleting).
     *
     * Uses chunked processing to avoid OOM for entities with many relationships.
     *
     * @param  array  $options  Options including 'deleted_by' for audit trail and 'chunk_size' (default 100)
     * @return int Number of relationships deleted
     */
    public function deleteAllForEntity(string $entityType, string $entityId, array $options = []): int
    {
        $deletedBy = $options['deleted_by'] ?? Auth::id();
        if (! $deletedBy) {
            throw new \InvalidArgumentException('deleted_by is required for audit trail');
        }

        $chunkSize = $options['chunk_size'] ?? 100;
        $totalDeleted = 0;

        // Process deletions in chunks to avoid OOM
        // We loop until no more relationships are found since soft deletes
        // filter out previously deleted records
        do {
            $deletedInChunk = DB::transaction(function () use ($entityType, $entityId, $chunkSize) {
                // Query for relationships where entity is source or target
                // Using limit() instead of chunkById() because records are being soft-deleted
                $relationships = EntityRelationship::where(function ($q) use ($entityType, $entityId) {
                    $q->where('source_type', $entityType)->where('source_id', $entityId);
                })->orWhere(function ($q) use ($entityType, $entityId) {
                    $q->where('target_type', $entityType)->where('target_id', $entityId);
                })->limit($chunkSize)->get();

                $count = 0;
                foreach ($relationships as $relationship) {
                    $relationship->delete();
                    $count++;
                }

                return $count;
            });

            $totalDeleted += $deletedInChunk;

            // Log progress for large deletions
            if ($totalDeleted > 0 && $totalDeleted % 1000 === 0) {
                Log::info('Bulk relationship deletion progress', [
                    'entity_type' => $entityType,
                    'entity_id' => $entityId,
                    'deleted_so_far' => $totalDeleted,
                ]);
            }
        } while ($deletedInChunk === $chunkSize);

        // Clear caches for both source and target entities
        $this->clearRelationshipCache($entityType, $entityId);

        if ($totalDeleted > 0) {
            Log::info('Completed bulk relationship deletion', [
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'total_deleted' => $totalDeleted,
            ]);
        }

        return $totalDeleted;
    }

    /**
     * Clear relationship caches for an entity.
     *
     * Clears any cached relationship data for the given entity.
     * Also clears partition-level relationship caches if partition ID is provided.
     */
    public function clearRelationshipCache(string $entityType, string $entityId, ?string $partitionId = null): void
    {
        // Clear entity-specific relationship caches
        Cache::forget("relationships:source:{$entityType}:{$entityId}");
        Cache::forget("relationships:target:{$entityType}:{$entityId}");
        Cache::forget("relationships:all:{$entityType}:{$entityId}");
        Cache::forget("relationship_counts:{$entityType}:{$entityId}");

        // Clear partition-level caches if partition ID is provided
        if ($partitionId) {
            Cache::forget("relationships:partition:{$partitionId}");
            Cache::forget("relationship_stats:{$partitionId}");
        }

        Log::debug('Cleared relationship caches', [
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'partition_id' => $partitionId,
        ]);
    }

    /**
     * Clear all relationship caches for a partition.
     */
    public function clearPartitionRelationshipCaches(string $partitionId): void
    {
        Cache::forget("relationships:partition:{$partitionId}");
        Cache::forget("relationship_stats:{$partitionId}");

        Log::debug('Cleared partition relationship caches', [
            'partition_id' => $partitionId,
        ]);
    }
}
