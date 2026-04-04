<?php

namespace NewSolari\Core\Entity\Traits;

use Illuminate\Support\Facades\Log;

/**
 * Trait for cascading soft deletes to child relationships.
 *
 * This trait works with the SoftDeletes trait to automatically soft-delete
 * child records when a parent is soft-deleted, and optionally restore them
 * when the parent is restored.
 *
 * Usage:
 * 1. Add the trait to your model (after SoftDeletes): use SoftDeleteCascade;
 * 2. Define relationships to cascade: protected static array $cascadeOnDelete = ['children', 'items'];
 * 3. Optionally enable restore cascade: protected static bool $cascadeOnRestore = true;
 */
trait SoftDeleteCascade
{
    /**
     * Boot the soft delete cascade trait for a model.
     */
    public static function bootSoftDeleteCascade(): void
    {
        static::deleting(function ($model) {
            // Only cascade on soft delete, not force delete
            if (! $model->isForceDeleting()) {
                $model->cascadeSoftDelete();
            }
        });

        static::restoring(function ($model) {
            if (static::shouldCascadeOnRestore()) {
                $model->cascadeRestore();
            }
        });
    }

    /**
     * Get the relationships to cascade delete.
     * Override in model: protected static array $cascadeOnDelete = ['children'];
     */
    protected static function getCascadeOnDelete(): array
    {
        return property_exists(static::class, 'cascadeOnDelete')
            ? static::$cascadeOnDelete
            : [];
    }

    /**
     * Determine if restore should cascade to children.
     * Override in model: protected static bool $cascadeOnRestore = true;
     */
    protected static function shouldCascadeOnRestore(): bool
    {
        return property_exists(static::class, 'cascadeOnRestore')
            ? static::$cascadeOnRestore
            : false;
    }

    /**
     * Cascade soft delete to all defined relationships.
     */
    protected function cascadeSoftDelete(): void
    {
        $cascadeRelations = static::getCascadeOnDelete();

        if (empty($cascadeRelations)) {
            return;
        }

        // Get deleted_by value if available
        $deletedBy = $this->deleted_by ?? null;

        foreach ($cascadeRelations as $relation) {
            $this->cascadeDeleteRelation($relation, $deletedBy);
        }
    }

    /**
     * Cascade soft delete to a specific relationship.
     */
    protected function cascadeDeleteRelation(string $relation, ?string $deletedBy): void
    {
        if (! method_exists($this, $relation)) {
            Log::warning('SoftDeleteCascade: Relation not found', [
                'model' => static::class,
                'relation' => $relation,
                'record_id' => $this->getKey(),
            ]);

            return;
        }

        try {
            $this->$relation()->each(function ($child) use ($deletedBy) {
                // Skip if child is already deleted
                if (property_exists($child, 'deleted') && $child->deleted) {
                    return;
                }

                // Propagate deleted_by if the child supports it
                if ($deletedBy && in_array('deleted_by', $child->getFillable())) {
                    $child->deleted_by = $deletedBy;
                }

                // Perform soft delete (will trigger cascade on child if it has the trait)
                if (method_exists($child, 'delete')) {
                    $child->delete();
                }
            });
        } catch (\Exception $e) {
            Log::error('SoftDeleteCascade: Failed to cascade delete', [
                'model' => static::class,
                'relation' => $relation,
                'record_id' => $this->getKey(),
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Cascade restore to all soft-deleted children.
     */
    protected function cascadeRestore(): void
    {
        $cascadeRelations = static::getCascadeOnDelete();

        if (empty($cascadeRelations)) {
            return;
        }

        foreach ($cascadeRelations as $relation) {
            $this->cascadeRestoreRelation($relation);
        }
    }

    /**
     * Cascade restore to a specific relationship.
     */
    protected function cascadeRestoreRelation(string $relation): void
    {
        if (! method_exists($this, $relation)) {
            return;
        }

        try {
            // Get only soft-deleted children
            $this->$relation()->onlyDeleted()->each(function ($child) {
                // Clear deleted_by when restoring
                if (in_array('deleted_by', $child->getFillable())) {
                    $child->deleted_by = null;
                }

                // Perform restore (will trigger cascade on child if it has the trait)
                if (method_exists($child, 'restore')) {
                    $child->restore();
                }
            });
        } catch (\Exception $e) {
            Log::error('SoftDeleteCascade: Failed to cascade restore', [
                'model' => static::class,
                'relation' => $relation,
                'record_id' => $this->getKey(),
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
