<?php

namespace NewSolari\Core\Entity\Traits;

use NewSolari\Core\Identity\Models\EntityRelationship;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

trait BatchRelationshipOperations
{
    /**
     * Batch attach multiple entities with the same relationship type.
     *
     * @param  array  $entities  Array of entity models or IDs
     * @param  array  $metadata  Common metadata for all relationships
     * @param  array  $options  Common options for all relationships
     * @return Collection Collection of created EntityRelationship models
     */
    public function batchAttach(
        array $entities,
        string $relationshipType,
        array $metadata = [],
        array $options = []
    ): Collection {
        $relationships = collect();
        $batchSize = config('relationships.performance.batch_size', 1000);

        DB::transaction(function () use ($entities, $relationshipType, $metadata, $options, &$relationships, $batchSize) {
            $chunks = array_chunk($entities, $batchSize);

            foreach ($chunks as $chunk) {
                foreach ($chunk as $entity) {
                    try {
                        $relationship = $this->attachEntity(
                            $entity,
                            $relationshipType,
                            $metadata,
                            $options
                        );
                        $relationships->push($relationship);
                    } catch (\Exception $e) {
                        // Log error but continue with other entities
                        \Log::error('Failed to attach entity in batch: '.$e->getMessage(), [
                            'entity' => $entity instanceof Model ? $entity->getKey() : $entity,
                            'relationship_type' => $relationshipType,
                        ]);
                    }
                }
            }
        });

        return $relationships;
    }

    /**
     * Batch detach multiple entities.
     *
     * @param  array  $entities  Array of entity models or IDs
     * @param  string|null  $relationshipType  Specific type or null for all types
     * @return int Number of relationships deleted
     */
    public function batchDetach(array $entities, ?string $relationshipType = null): int
    {
        return DB::transaction(function () use ($entities, $relationshipType) {
            $count = 0;

            foreach ($entities as $entity) {
                try {
                    $count += $this->detachEntity($entity, $relationshipType);
                } catch (\Exception $e) {
                    \Log::error('Failed to detach entity in batch: '.$e->getMessage(), [
                        'entity' => $entity instanceof Model ? $entity->getKey() : $entity,
                        'relationship_type' => $relationshipType,
                    ]);
                }
            }

            return $count;
        });
    }

    /**
     * Batch sync multiple entity types with their respective entities.
     *
     * @param  array  $syncData  Array of [entity_type => [entities], ...]
     * @return array Results for each entity type
     */
    public function batchSync(
        array $syncData,
        string $relationshipType,
        array $options = []
    ): array {
        $results = [];

        DB::transaction(function () use ($syncData, $relationshipType, $options, &$results) {
            foreach ($syncData as $entityType => $entities) {
                try {
                    $results[$entityType] = $this->syncEntities(
                        $entityType,
                        $entities,
                        $relationshipType,
                        $options
                    );
                } catch (\Exception $e) {
                    \Log::error('Failed to sync entities in batch: '.$e->getMessage(), [
                        'entity_type' => $entityType,
                        'relationship_type' => $relationshipType,
                    ]);
                    $results[$entityType] = [
                        'error' => $e->getMessage(),
                        'attached' => [],
                        'detached' => [],
                        'updated' => [],
                    ];
                }
            }
        });

        return $results;
    }

    /**
     * Batch update metadata for multiple relationships.
     *
     * @param  array  $relationshipIds  Array of relationship record_ids
     * @param  array  $metadata  Metadata to update
     * @return int Number of relationships updated
     */
    public function batchUpdateMetadata(array $relationshipIds, array $metadata): int
    {
        return DB::transaction(function () use ($relationshipIds, $metadata) {
            return EntityRelationship::whereIn('record_id', $relationshipIds)
                ->where(function ($query) {
                    // Wrap OR conditions to ensure they are scoped within the whereIn
                    $query->where(function ($q) {
                        $q->where('source_type', $this->getEntityType())
                            ->where('source_id', $this->getKey());
                    })->orWhere(function ($q) {
                        $q->where('target_type', $this->getEntityType())
                            ->where('target_id', $this->getKey());
                    });
                })
                ->update([
                    'metadata' => $metadata,
                    'updated_by' => Auth::id(),
                    'updated_at' => now(),
                ]);
        });
    }

    /**
     * Batch update priority for multiple relationships.
     *
     * @param  array  $relationshipIds  Array of relationship record_ids
     * @param  int  $priority  New priority value
     * @return int Number of relationships updated
     */
    public function batchUpdatePriority(array $relationshipIds, int $priority): int
    {
        return DB::transaction(function () use ($relationshipIds, $priority) {
            return EntityRelationship::whereIn('record_id', $relationshipIds)
                ->where(function ($query) {
                    // Wrap OR conditions to ensure they are scoped within the whereIn
                    $query->where(function ($q) {
                        $q->where('source_type', $this->getEntityType())
                            ->where('source_id', $this->getKey());
                    })->orWhere(function ($q) {
                        $q->where('target_type', $this->getEntityType())
                            ->where('target_id', $this->getKey());
                    });
                })
                ->update([
                    'priority' => $priority,
                    'updated_by' => Auth::id(),
                    'updated_at' => now(),
                ]);
        });
    }

    /**
     * Batch delete relationships by IDs (archives to history before deleting).
     *
     * @param  array  $relationshipIds  Array of relationship record_ids
     * @param  string|null  $deletedBy  User ID who is deleting (defaults to Auth::id())
     * @return int Number of relationships deleted
     */
    public function batchDeleteRelationships(array $relationshipIds, ?string $deletedBy = null): int
    {
        $deletedBy = $deletedBy ?? Auth::id();
        if (! $deletedBy) {
            throw new \InvalidArgumentException('deletedBy is required for audit trail');
        }

        return DB::transaction(function () use ($relationshipIds, $deletedBy) {
            $relationships = EntityRelationship::whereIn('record_id', $relationshipIds)
                ->where(function ($query) {
                    // Wrap OR conditions to ensure they are scoped within the whereIn
                    $query->where(function ($q) {
                        $q->where('source_type', $this->getEntityType())
                            ->where('source_id', $this->getKey());
                    })->orWhere(function ($q) {
                        $q->where('target_type', $this->getEntityType())
                            ->where('target_id', $this->getKey());
                    });
                })
                ->get();

            $count = 0;
            foreach ($relationships as $relationship) {
                $relationship->delete();
                $count++;
            }

            return $count;
        });
    }

    /**
     * Copy all relationships from another entity to this entity.
     *
     * @param  Model  $sourceEntity  Entity to copy relationships from
     * @param  array  $relationshipTypes  Specific types to copy, or empty for all
     * @param  bool  $asSource  Copy relationships where source is the source entity
     * @param  bool  $asTarget  Copy relationships where source is the target entity
     * @return Collection Collection of created relationships
     */
    public function copyRelationshipsFrom(
        Model $sourceEntity,
        array $relationshipTypes = [],
        bool $asSource = true,
        bool $asTarget = false
    ): Collection {
        $copiedRelationships = collect();

        DB::transaction(function () use (
            $sourceEntity,
            $relationshipTypes,
            $asSource,
            $asTarget,
            &$copiedRelationships
        ) {
            // Get relationships to copy
            $relationships = collect();

            if ($asSource) {
                $query = $sourceEntity->relationshipsAsSource();
                if (! empty($relationshipTypes)) {
                    $query->whereIn('relationship_type', $relationshipTypes);
                }
                $relationships = $relationships->merge($query->get());
            }

            if ($asTarget) {
                $query = $sourceEntity->relationshipsAsTarget();
                if (! empty($relationshipTypes)) {
                    $query->whereIn('relationship_type', $relationshipTypes);
                }
                $relationships = $relationships->merge($query->get());
            }

            // Copy each relationship, preserving direction
            foreach ($relationships as $relationship) {
                try {
                    // Determine if $sourceEntity was the source or target in this relationship
                    $sourceEntityKey = $sourceEntity->getKey();
                    $wasSource = $relationship->source_id === $sourceEntityKey;

                    if ($wasSource) {
                        // Original entity was SOURCE: this entity becomes the new source
                        $newSourceType = $this->getEntityType();
                        $newSourceId = $this->getKey();
                        $newTargetType = $relationship->target_type;
                        $newTargetId = $relationship->target_id;
                    } else {
                        // Original entity was TARGET: this entity becomes the new target
                        $newSourceType = $relationship->source_type;
                        $newSourceId = $relationship->source_id;
                        $newTargetType = $this->getEntityType();
                        $newTargetId = $this->getKey();
                    }

                    $copiedRelationship = EntityRelationship::create([
                        'source_type' => $newSourceType,
                        'source_id' => $newSourceId,
                        'target_type' => $newTargetType,
                        'target_id' => $newTargetId,
                        'relationship_type' => $relationship->relationship_type,
                        'relationship_subtype' => $relationship->relationship_subtype,
                        'metadata' => $relationship->metadata,
                        'priority' => $relationship->priority,
                        'is_primary' => false, // Don't copy primary designation
                        'partition_id' => $this->partition_id ?? $relationship->partition_id,
                        'created_by' => Auth::id() ?? $relationship->created_by,
                    ]);

                    $copiedRelationships->push($copiedRelationship);
                } catch (\Exception $e) {
                    \Log::error('Failed to copy relationship: '.$e->getMessage(), [
                        'relationship_id' => $relationship->record_id,
                    ]);
                }
            }
        });

        return $copiedRelationships;
    }

    /**
     * Move all relationships from another entity to this entity.
     *
     * @param  Model  $sourceEntity  Entity to move relationships from
     * @param  array  $relationshipTypes  Specific types to move, or empty for all
     * @return int Number of relationships moved
     */
    public function moveRelationshipsFrom(
        Model $sourceEntity,
        array $relationshipTypes = []
    ): int {
        return DB::transaction(function () use ($sourceEntity, $relationshipTypes) {
            $movedCount = 0;

            // Update relationships where the old entity is the SOURCE
            $sourceQuery = EntityRelationship::where('source_type', $sourceEntity->getEntityType())
                ->where('source_id', $sourceEntity->getKey());

            if (! empty($relationshipTypes)) {
                $sourceQuery->whereIn('relationship_type', $relationshipTypes);
            }

            $movedCount += $sourceQuery->update([
                'source_type' => $this->getEntityType(),
                'source_id' => $this->getKey(),
                'updated_by' => Auth::id(),
                'updated_at' => now(),
            ]);

            // Update relationships where the old entity is the TARGET
            $targetQuery = EntityRelationship::where('target_type', $sourceEntity->getEntityType())
                ->where('target_id', $sourceEntity->getKey());

            if (! empty($relationshipTypes)) {
                $targetQuery->whereIn('relationship_type', $relationshipTypes);
            }

            $movedCount += $targetQuery->update([
                'target_type' => $this->getEntityType(),
                'target_id' => $this->getKey(),
                'updated_by' => Auth::id(),
                'updated_at' => now(),
            ]);

            return $movedCount;
        });
    }

    /**
     * Get the entity type key for the model.
     * This method should be provided by HasUnifiedRelationships trait.
     */
    abstract protected function getEntityType(?\Illuminate\Database\Eloquent\Model $model = null): string;
}
