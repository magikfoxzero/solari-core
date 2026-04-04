<?php

namespace NewSolari\Core\Entity\Traits;

use NewSolari\Core\Identity\Models\EntityRelationship;
use NewSolari\Core\Identity\Models\EntityTypeRegistry;
use NewSolari\Core\Identity\Models\RelationshipTypeRegistry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

trait HasUnifiedRelationships
{
    /**
     * Boot the HasUnifiedRelationships trait.
     * This registers a deleting event to clean up relationships when an entity is deleted.
     */
    public static function bootHasUnifiedRelationships(): void
    {
        static::deleting(function ($model) {
            // Soft delete all relationships involving this entity
            $model->softDeleteAllRelationships();
        });
    }

    /**
     * Soft delete all relationships involving this entity.
     * This is called automatically when the entity is deleted.
     *
     * Uses batch update to avoid N+1 query performance issues.
     * Note: entity_relationships table uses simple boolean soft delete (deleted column only),
     * not the full deleted_at/deleted_by audit columns used by BaseEntity.
     *
     * @return int Number of relationships deleted
     */
    public function softDeleteAllRelationships(): int
    {
        $entityType = $this->getEntityType();
        $entityId = $this->getKey();

        // Use batch update instead of individual delete() calls to avoid N+1
        // This directly updates the database without loading each model
        // Note: entity_relationships only has boolean 'deleted' column, not deleted_at/deleted_by
        return EntityRelationship::where(function ($q) use ($entityType, $entityId) {
            $q->where(function ($subQ) use ($entityType, $entityId) {
                $subQ->where('source_type', $entityType)
                    ->where('source_id', $entityId);
            })
                ->orWhere(function ($subQ) use ($entityType, $entityId) {
                    $subQ->where('target_type', $entityType)
                        ->where('target_id', $entityId);
                });
        })
            ->where('deleted', false) // Only update non-deleted records
            ->update([
                'deleted' => true,
            ]);
    }

    /**
     * Get all relationships where this entity is the source.
     */
    public function relationshipsAsSource(): MorphMany
    {
        return $this->morphMany(
            EntityRelationship::class,
            'source',
            'source_type',
            'source_id',
            $this->getKeyName()
        );
    }

    /**
     * Get all relationships where this entity is the target.
     */
    public function relationshipsAsTarget(): MorphMany
    {
        return $this->morphMany(
            EntityRelationship::class,
            'target',
            'target_type',
            'target_id',
            $this->getKeyName()
        );
    }

    /**
     * Get all relationships (both as source and target).
     */
    public function allRelationships(): Collection
    {
        // Load relationships if not already loaded to avoid N+1 queries
        if (! $this->relationLoaded('relationshipsAsSource') || ! $this->relationLoaded('relationshipsAsTarget')) {
            $this->load(['relationshipsAsSource', 'relationshipsAsTarget']);
        }

        return $this->relationshipsAsSource
            ->merge($this->relationshipsAsTarget);
    }

    /**
     * Attach an entity with a specific relationship type.
     *
     * BIDIRECTIONAL: Relationships are stored as a SINGLE record. When creating,
     * we check if a relationship already exists in EITHER direction (A->B or B->A)
     * with the same relationship_type. If found, we return/update that existing record.
     *
     * @param  Model|string  $entity  Entity model or ID
     * @param  array  $options  Additional options (priority, is_primary, etc.)
     */
    public function attachEntity(
        Model|string $entity,
        string $relationshipType,
        array $metadata = [],
        array $options = []
    ): EntityRelationship {
        // Resolve entity if it's an ID
        if (is_string($entity)) {
            $targetType = $options['target_type'] ?? null;
            if (! $targetType) {
                throw new \InvalidArgumentException('target_type must be provided when entity is an ID');
            }
            $targetId = $entity;
        } else {
            $targetType = $this->getEntityType($entity);
            $targetId = $entity->getKey();
        }

        // Get relationship type definition
        $typeDef = RelationshipTypeRegistry::findByTypeKey($relationshipType);

        // Validate metadata if required
        if ($typeDef && $typeDef->requiresMetadata() && empty($metadata)) {
            throw new \InvalidArgumentException("Metadata is required for relationship type: {$relationshipType}");
        }

        // Validate metadata schema
        if ($typeDef && ! empty($metadata)) {
            $typeDef->validateMetadata($metadata);
        }

        // Determine created_by value
        $createdBy = Auth::id() ?? ($options['created_by'] ?? null);
        if (! $createdBy) {
            throw new \InvalidArgumentException('created_by is required but could not be determined from Auth::id() or options');
        }

        $partitionId = $this->partition_id ?? ($options['partition_id'] ?? null);
        $sourceType = $this->getEntityType();
        $sourceId = $this->getKey();

        // Validate target entity belongs to the same partition (multi-tenancy isolation)
        if ($entity instanceof Model && isset($entity->partition_id) && $partitionId !== null) {
            if ($entity->partition_id !== $partitionId) {
                throw new \InvalidArgumentException('Cannot create relationship with entity from different partition');
            }
        }

        // Check if duplicates are allowed (only when typeDef exists and explicitly allows them)
        $allowDuplicates = $typeDef && $typeDef->allowsDuplicates();

        if ($allowDuplicates) {
            // Duplicates allowed - create new relationship
            $relationship = EntityRelationship::create([
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'target_type' => $targetType,
                'target_id' => $targetId,
                'relationship_type' => $relationshipType,
                'relationship_subtype' => $options['relationship_subtype'] ?? null,
                'metadata' => $metadata,
                'priority' => $options['priority'] ?? 0,
                'is_primary' => $options['is_primary'] ?? false,
                'partition_id' => $partitionId,
                'created_by' => $createdBy,
            ]);
        } else {
            // BIDIRECTIONAL: Check for existing relationship in EITHER direction atomically
            // This ensures we have only ONE record for a bidirectional relationship
            // Use DB transaction with locking to prevent race conditions (LOGIC-MED-004)
            $relationship = \Illuminate\Support\Facades\DB::transaction(function () use (
                $partitionId, $relationshipType, $sourceType, $sourceId, $targetType, $targetId,
                $options, $metadata, $createdBy
            ) {
                // Check forward direction first with lock
                $existingRelationship = EntityRelationship::where('partition_id', $partitionId)
                    ->where('relationship_type', $relationshipType)
                    ->where(function ($query) use ($sourceType, $sourceId, $targetType, $targetId) {
                        // Check forward direction: this entity -> target
                        $query->where(function ($q) use ($sourceType, $sourceId, $targetType, $targetId) {
                            $q->where('source_type', $sourceType)
                                ->where('source_id', $sourceId)
                                ->where('target_type', $targetType)
                                ->where('target_id', $targetId);
                        })
                        // OR check reverse direction: target -> this entity
                            ->orWhere(function ($q) use ($sourceType, $sourceId, $targetType, $targetId) {
                                $q->where('source_type', $targetType)
                                    ->where('source_id', $targetId)
                                    ->where('target_type', $sourceType)
                                    ->where('target_id', $sourceId);
                            });
                    })
                    ->lockForUpdate()
                    ->first();

                if ($existingRelationship) {
                    // Update existing relationship with new metadata/options
                    $existingRelationship->update([
                        'relationship_subtype' => $options['relationship_subtype'] ?? $existingRelationship->relationship_subtype,
                        'metadata' => $metadata ?: $existingRelationship->metadata,
                        'priority' => $options['priority'] ?? $existingRelationship->priority,
                        'is_primary' => $options['is_primary'] ?? $existingRelationship->is_primary,
                        'updated_by' => $createdBy,
                    ]);

                    return $existingRelationship->fresh();
                } else {
                    // Create new relationship (single record, no inverse needed)
                    return EntityRelationship::create([
                        'source_type' => $sourceType,
                        'source_id' => $sourceId,
                        'target_type' => $targetType,
                        'target_id' => $targetId,
                        'relationship_type' => $relationshipType,
                        'relationship_subtype' => $options['relationship_subtype'] ?? null,
                        'metadata' => $metadata,
                        'priority' => $options['priority'] ?? 0,
                        'is_primary' => $options['is_primary'] ?? false,
                        'partition_id' => $partitionId,
                        'created_by' => $createdBy,
                    ]);
                }
            });
        }

        // Note: create_inverse option is now ignored - relationships are truly bidirectional
        // with a single record. The option is kept for backwards compatibility but has no effect.

        return $relationship;
    }

    /**
     * Detach an entity (archives to history before deleting).
     * BIDIRECTIONAL: Finds and deletes relationships in EITHER direction.
     *
     * @param  Model|string|null  $entity  Entity model, ID, or null for all
     * @param  string|null  $relationshipType  Specific type or null for all types
     * @param  string|null  $deletedBy  User ID who is deleting (defaults to Auth::id())
     * @return int Number of relationships deleted
     */
    public function detachEntity(Model|string|null $entity = null, ?string $relationshipType = null, ?string $deletedBy = null): int
    {
        $deletedBy = $deletedBy ?? Auth::id();
        if (! $deletedBy) {
            throw new \InvalidArgumentException('deletedBy is required for audit trail');
        }

        $sourceType = $this->getEntityType();
        $sourceId = $this->getKey();

        // Build query to find relationships in EITHER direction
        $query = EntityRelationship::where(function ($q) use ($sourceType, $sourceId, $entity) {
            if ($entity) {
                $targetId = is_string($entity) ? $entity : $entity->getKey();
                $targetType = is_string($entity) ? null : $this->getEntityType($entity);

                // Check forward direction: this entity -> target
                $q->where(function ($subQ) use ($sourceType, $sourceId, $targetId, $targetType) {
                    $subQ->where('source_type', $sourceType)
                        ->where('source_id', $sourceId)
                        ->where('target_id', $targetId);
                    if ($targetType) {
                        $subQ->where('target_type', $targetType);
                    }
                })
                // OR check reverse direction: target -> this entity
                    ->orWhere(function ($subQ) use ($sourceType, $sourceId, $targetId, $targetType) {
                        $subQ->where('target_type', $sourceType)
                            ->where('target_id', $sourceId)
                            ->where('source_id', $targetId);
                        if ($targetType) {
                            $subQ->where('source_type', $targetType);
                        }
                    });
            } else {
                // No specific entity - find all relationships involving this entity
                $q->where(function ($subQ) use ($sourceType, $sourceId) {
                    $subQ->where('source_type', $sourceType)
                        ->where('source_id', $sourceId);
                })
                    ->orWhere(function ($subQ) use ($sourceType, $sourceId) {
                        $subQ->where('target_type', $sourceType)
                            ->where('target_id', $sourceId);
                    });
            }
        });

        if ($relationshipType) {
            $query->where('relationship_type', $relationshipType);
        }

        // Soft delete each relationship
        $relationships = $query->get();
        $count = 0;

        foreach ($relationships as $relationship) {
            $relationship->delete();
            $count++;
        }

        return $count;
    }

    /**
     * Sync entities for a specific relationship type.
     * BIDIRECTIONAL: Checks both directions when looking for existing relationships.
     *
     * @param  array  $entities  Array of entity IDs or models
     * @return array ['attached' => [], 'detached' => [], 'updated' => []]
     */
    public function syncEntities(
        string $entityType,
        array $entities,
        string $relationshipType,
        array $options = []
    ): array {
        $results = [
            'attached' => [],
            'detached' => [],
            'updated' => [],
        ];

        $sourceType = $this->getEntityType();
        $sourceId = $this->getKey();

        // BIDIRECTIONAL: Get existing relationships in BOTH directions
        $existingRelationships = EntityRelationship::where('relationship_type', $relationshipType)
            ->where(function ($q) use ($sourceType, $sourceId, $entityType) {
                // Forward: this entity -> target of entityType
                $q->where(function ($subQ) use ($sourceType, $sourceId, $entityType) {
                    $subQ->where('source_type', $sourceType)
                        ->where('source_id', $sourceId)
                        ->where('target_type', $entityType);
                })
                // Reverse: entityType -> this entity
                    ->orWhere(function ($subQ) use ($sourceType, $sourceId, $entityType) {
                        $subQ->where('source_type', $entityType)
                            ->where('target_type', $sourceType)
                            ->where('target_id', $sourceId);
                    });
            })
            ->get();

        // Build a map of existing relationships by "the other entity's ID"
        $existing = collect();
        foreach ($existingRelationships as $rel) {
            // Determine the "other" entity ID (the one that's not this entity)
            $otherId = ($rel->source_id === $sourceId) ? $rel->target_id : $rel->source_id;
            $existing[$otherId] = $rel;
        }

        // Normalize entities to IDs
        $entityIds = collect($entities)->map(function ($entity) {
            return $entity instanceof Model ? $entity->getKey() : $entity;
        })->all();

        // Determine what to attach, update, and detach
        $existingIds = $existing->keys()->all();
        $toAttach = array_diff($entityIds, $existingIds);
        $toDetach = array_diff($existingIds, $entityIds);
        $toUpdate = array_intersect($entityIds, $existingIds);

        // Attach new relationships
        foreach ($toAttach as $entityId) {
            $relationship = $this->attachEntity(
                $entityId,
                $relationshipType,
                $options['metadata'] ?? [],
                array_merge($options, ['target_type' => $entityType])
            );
            $results['attached'][] = $relationship->record_id;
        }

        // Update existing relationships if metadata provided
        if (! empty($options['metadata'])) {
            foreach ($toUpdate as $entityId) {
                $relationship = $existing[$entityId];
                $relationship->update([
                    'metadata' => $options['metadata'],
                    'updated_by' => Auth::id() ?? $relationship->created_by,
                ]);
                $results['updated'][] = $relationship->record_id;
            }
        }

        // Soft delete removed relationships
        foreach ($toDetach as $entityId) {
            $relationship = $existing[$entityId];
            $relationship->delete();
            $results['detached'][] = $relationship->record_id;
        }

        return $results;
    }

    /**
     * Check if this entity has a relationship with another entity.
     * BIDIRECTIONAL: Checks both directions (this->target OR target->this)
     */
    public function hasEntity(Model|string $entity, ?string $relationshipType = null): bool
    {
        $sourceType = $this->getEntityType();
        $sourceId = $this->getKey();

        if (is_string($entity)) {
            $targetId = $entity;
            $targetType = null; // Unknown when passed as string
        } else {
            $targetType = $this->getEntityType($entity);
            $targetId = $entity->getKey();
        }

        $query = EntityRelationship::where(function ($q) use ($sourceType, $sourceId, $targetId, $targetType) {
            // Check forward direction: this entity -> target
            $q->where(function ($subQ) use ($sourceType, $sourceId, $targetId, $targetType) {
                $subQ->where('source_type', $sourceType)
                    ->where('source_id', $sourceId)
                    ->where('target_id', $targetId);
                if ($targetType) {
                    $subQ->where('target_type', $targetType);
                }
            })
            // OR check reverse direction: target -> this entity
                ->orWhere(function ($subQ) use ($sourceType, $sourceId, $targetId, $targetType) {
                    $subQ->where('target_type', $sourceType)
                        ->where('target_id', $sourceId)
                        ->where('source_id', $targetId);
                    if ($targetType) {
                        $subQ->where('source_type', $targetType);
                    }
                });
        });

        if ($relationshipType) {
            $query->where('relationship_type', $relationshipType);
        }

        return $query->exists();
    }

    /**
     * Get all related entities of a specific type.
     */
    public function getRelatedEntities(string $entityType, ?string $relationshipType = null): Collection
    {
        $query = $this->relationshipsAsSource()
            ->where('target_type', $entityType);

        if ($relationshipType) {
            $query->where('relationship_type', $relationshipType);
        }

        $relationships = $query->with('target')->get();

        return $relationships->map(fn ($rel) => $rel->target)->filter();
    }

    /**
     * Get relationship metadata for a specific entity.
     */
    public function getRelationshipMetadata(Model|string $entity, string $relationshipType): ?array
    {
        $query = $this->relationshipsAsSource()
            ->where('relationship_type', $relationshipType);

        if (is_string($entity)) {
            $query->where('target_id', $entity);
        } else {
            $query->where('target_type', $this->getEntityType($entity))
                ->where('target_id', $entity->getKey());
        }

        $relationship = $query->first();

        return $relationship ? $relationship->metadata : null;
    }

    /**
     * Update relationship metadata.
     */
    public function updateRelationshipMetadata(Model|string $entity, string $relationshipType, array $metadata): bool
    {
        $query = $this->relationshipsAsSource()
            ->where('relationship_type', $relationshipType);

        if (is_string($entity)) {
            $query->where('target_id', $entity);
        } else {
            $query->where('target_type', $this->getEntityType($entity))
                ->where('target_id', $entity->getKey());
        }

        $relationship = $query->first();

        if (! $relationship) {
            return false;
        }

        // Validate metadata
        $typeDef = RelationshipTypeRegistry::findByTypeKey($relationshipType);
        if ($typeDef) {
            $typeDef->validateMetadata($metadata);
        }

        return $relationship->update([
            'metadata' => $metadata,
            'updated_by' => Auth::id() ?? $relationship->created_by,
        ]);
    }

    /**
     * Get the entity type key for a model.
     *
     * This method determines the entity type identifier used for polymorphic relationships.
     * It prioritizes the EntityTypeRegistry, then falls back to Laravel's morph map alias,
     * which ensures consistency with MorphMany/MorphTo queries.
     */
    protected function getEntityType(?Model $model = null): string
    {
        $model = $model ?? $this;
        $class = get_class($model);

        // Try to find in registry first
        $registry = EntityTypeRegistry::findByModelClass($class);

        if ($registry) {
            return $registry->type_key;
        }

        // Fallback: use Laravel's morph class (respects morphMap in AppServiceProvider)
        // This ensures consistency between attachEntity() and MorphMany queries
        return $model->getMorphClass();
    }

    /**
     * Get relationships grouped by type.
     */
    public function getRelationshipsByType(): Collection
    {
        // Load relationship if not already loaded to avoid N+1 query
        if (! $this->relationLoaded('relationshipsAsSource')) {
            $this->load('relationshipsAsSource');
        }

        return $this->relationshipsAsSource->groupBy('relationship_type');
    }

    /**
     * Get count of relationships by type.
     */
    public function getRelationshipCounts(): Collection
    {
        return $this->relationshipsAsSource()
            ->select('relationship_type', DB::raw('count(*) as count'))
            ->groupBy('relationship_type')
            ->pluck('count', 'relationship_type');
    }
}
