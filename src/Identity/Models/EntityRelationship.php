<?php

namespace NewSolari\Core\Identity\Models;

use NewSolari\Core\Entity\BaseEntity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

class EntityRelationship extends BaseEntity
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'entity_relationships';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'record_id',
        'partition_id',
        'source_type',
        'source_id',
        'target_type',
        'target_id',
        'relationship_type',
        'relationship_subtype',
        'metadata',
        'priority',
        'is_primary',
        'created_by',
        'updated_by',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'metadata' => 'array',
        'priority' => 'integer',
        'is_primary' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * The validation rules for the entity.
     *
     * @var array
     */
    protected $validations = [
        'source_type' => 'required|string|max:64',
        'source_id' => 'required|string|max:36',
        'target_type' => 'required|string|max:64',
        'target_id' => 'required|string|max:36',
        'relationship_type' => 'required|string|max:64',
        'relationship_subtype' => 'nullable|string|max:64',
        'metadata' => 'nullable|array',
        'priority' => 'nullable|integer|min:0',
        'is_primary' => 'nullable|boolean',
        'partition_id' => 'required|string|max:36|exists:identity_partitions,record_id',
        'created_by' => 'required|string|max:36|exists:identity_users,record_id',
        'updated_by' => 'nullable|string|max:36|exists:identity_users,record_id',
    ];

    /**
     * The event map for the model.
     *
     * @var array
     */
    protected $dispatchesEvents = [
        'creating' => \NewSolari\Core\Events\RelationshipCreating::class,
        'created' => \NewSolari\Core\Events\RelationshipCreated::class,
        'updating' => \NewSolari\Core\Events\RelationshipUpdating::class,
        'updated' => \NewSolari\Core\Events\RelationshipUpdated::class,
        'deleting' => \NewSolari\Core\Events\RelationshipDeleting::class,
        'deleted' => \NewSolari\Core\Events\RelationshipDeleted::class,
    ];

    /**
     * Get the source entity (polymorphic relation).
     */
    public function source(): \Illuminate\Database\Eloquent\Relations\MorphTo
    {
        return $this->morphTo('source', 'source_type', 'source_id', 'record_id');
    }

    /**
     * Get the target entity (polymorphic relation).
     */
    public function target(): \Illuminate\Database\Eloquent\Relations\MorphTo
    {
        return $this->morphTo('target', 'target_type', 'target_id', 'record_id');
    }

    /**
     * Get the partition this relationship belongs to.
     */
    public function partition(): BelongsTo
    {
        return $this->belongsTo(IdentityPartition::class, 'partition_id', 'record_id');
    }

    /**
     * Get the user who created this relationship.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(IdentityUser::class, 'created_by', 'record_id');
    }

    /**
     * Get the user who last updated this relationship.
     */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(IdentityUser::class, 'updated_by', 'record_id');
    }

    /**
     * Get the relationship type definition.
     */
    public function typeDefinition(): BelongsTo
    {
        return $this->belongsTo(RelationshipTypeRegistry::class, 'relationship_type', 'type_key');
    }

    /**
     * Scope a query to only include relationships with a specific source type.
     */
    public function scopeBySourceType(Builder $query, string $sourceType): Builder
    {
        return $query->where('source_type', $sourceType);
    }

    /**
     * Scope a query to only include relationships with a specific source.
     */
    public function scopeBySource(Builder $query, string $sourceType, string $sourceId): Builder
    {
        return $query->where('source_type', $sourceType)
            ->where('source_id', $sourceId);
    }

    /**
     * Scope a query to only include relationships with a specific target type.
     */
    public function scopeByTargetType(Builder $query, string $targetType): Builder
    {
        return $query->where('target_type', $targetType);
    }

    /**
     * Scope a query to only include relationships with a specific target.
     */
    public function scopeByTarget(Builder $query, string $targetType, string $targetId): Builder
    {
        return $query->where('target_type', $targetType)
            ->where('target_id', $targetId);
    }

    /**
     * Scope a query to only include relationships of a specific type.
     */
    public function scopeByRelationshipType(Builder $query, string $relationshipType): Builder
    {
        return $query->where('relationship_type', $relationshipType);
    }

    /**
     * Scope a query to only include primary relationships.
     */
    public function scopePrimary(Builder $query): Builder
    {
        return $query->where('is_primary', true);
    }

    /**
     * Scope a query to only include relationships in a specific partition.
     */
    public function scopeInPartition(Builder $query, string $partitionId): Builder
    {
        return $query->where('partition_id', $partitionId);
    }

    /**
     * Scope a query to order by priority.
     */
    public function scopeOrderByPriority(Builder $query, string $direction = 'desc'): Builder
    {
        return $query->orderBy('priority', $direction);
    }

    /**
     * Get a metadata value by key.
     */
    public function getMetadataValue(string $key, mixed $default = null): mixed
    {
        return data_get($this->metadata, $key, $default);
    }

    /**
     * Set a metadata value by key.
     *
     * @return $this
     */
    public function setMetadataValue(string $key, mixed $value): self
    {
        $metadata = $this->metadata ?? [];
        data_set($metadata, $key, $value);
        $this->metadata = $metadata;

        return $this;
    }

    /**
     * Merge metadata with existing metadata.
     *
     * @return $this
     */
    public function mergeMetadata(array $metadata): self
    {
        $this->metadata = array_merge($this->metadata ?? [], $metadata);

        return $this;
    }

    /**
     * Check if this relationship has the inverse relationship.
     */
    public function hasInverse(): bool
    {
        // Load typeDefinition once to avoid N+1 query if not already loaded
        $typeDefinition = $this->relationLoaded('typeDefinition')
            ? $this->typeDefinition
            : $this->load('typeDefinition')->typeDefinition;

        if (! $typeDefinition || ! $typeDefinition->inverse_type) {
            return false;
        }

        return static::where('source_type', $this->target_type)
            ->where('source_id', $this->target_id)
            ->where('target_type', $this->source_type)
            ->where('target_id', $this->source_id)
            ->where('relationship_type', $typeDefinition->inverse_type)
            ->where('partition_id', $this->partition_id)
            ->exists();
    }

    /**
     * Create the inverse relationship if it doesn't exist.
     */
    public function createInverse(): ?EntityRelationship
    {
        // Load typeDefinition once and reuse to avoid N+1 query
        $typeDefinition = $this->relationLoaded('typeDefinition')
            ? $this->typeDefinition
            : $this->load('typeDefinition')->typeDefinition;

        // Determine the inverse relationship type:
        // 1. If type definition exists with inverse_type, use that
        // 2. Otherwise, use the same relationship_type (self-inverse)
        $inverseType = ($typeDefinition && $typeDefinition->inverse_type)
            ? $typeDefinition->inverse_type
            : $this->relationship_type;

        // Check for existing inverse
        $inverseExists = static::where('source_type', $this->target_type)
            ->where('source_id', $this->target_id)
            ->where('target_type', $this->source_type)
            ->where('target_id', $this->source_id)
            ->where('relationship_type', $inverseType)
            ->where('partition_id', $this->partition_id)
            ->exists();

        if ($inverseExists) {
            return null;
        }

        return static::create([
            'source_type' => $this->target_type,
            'source_id' => $this->target_id,
            'target_type' => $this->source_type,
            'target_id' => $this->source_id,
            'relationship_type' => $inverseType,
            'relationship_subtype' => $this->relationship_subtype,
            'metadata' => $this->metadata,
            'priority' => $this->priority,
            'is_primary' => false,
            'partition_id' => $this->partition_id,
            'created_by' => $this->created_by,
        ]);
    }

    /**
     * Get a human-readable description of this relationship.
     */
    public function getDescriptionAttribute(): string
    {
        return sprintf(
            '%s (%s) %s %s (%s)',
            $this->source_type,
            $this->source_id,
            $this->relationship_type,
            $this->target_type,
            $this->target_id
        );
    }

}
