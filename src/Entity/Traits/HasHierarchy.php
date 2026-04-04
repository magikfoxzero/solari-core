<?php

namespace NewSolari\Core\Entity\Traits;

/**
 * Trait for models with self-referential parent-child hierarchies.
 *
 * Provides cycle detection and safe deletion for hierarchical data structures.
 * Use this trait when a model has a parent reference to itself (e.g., Task -> parent_task_id).
 *
 * Usage:
 * 1. Add the trait to your model: use HasHierarchy;
 * 2. Override getParentColumn() to return your parent column name
 * 3. Override getMaxHierarchyDepth() if needed (default: 20)
 *
 * @example
 * class Task extends BaseEntity
 * {
 *     use HasHierarchy;
 *
 *     protected function getParentColumn(): string { return 'parent_task_id'; }
 *     protected function getChildRelation(): string { return 'childTasks'; }
 * }
 */
trait HasHierarchy
{
    /**
     * Maximum hierarchy depth allowed.
     */
    protected static int $maxHierarchyDepth = 20;

    /**
     * Boot the HasHierarchy trait.
     * Adds validation before saving to prevent circular references.
     */
    public static function bootHasHierarchy(): void
    {
        static::saving(function ($model) {
            $parentColumn = $model->getParentColumn();
            $proposedParentId = $model->{$parentColumn};

            // Skip if no parent is set
            if ($proposedParentId === null || $proposedParentId === '') {
                return true;
            }

            // Check for circular reference
            if ($model->wouldCreateCircularReference($proposedParentId)) {
                throw new \InvalidArgumentException(
                    "Cannot set {$parentColumn}: would create a circular reference"
                );
            }

            return true;
        });
    }

    /**
     * Get the column name for the parent reference.
     * Override in child classes.
     */
    abstract protected function getParentColumn(): string;

    /**
     * Get the relationship name for child records.
     * Override in child classes to enable safe cascade deletion.
     */
    protected function getChildRelation(): ?string
    {
        return null;
    }

    /**
     * Get the maximum allowed hierarchy depth.
     */
    protected function getMaxHierarchyDepth(): int
    {
        return static::$maxHierarchyDepth;
    }

    /**
     * Check if setting the proposed parent would create a circular reference.
     */
    public function wouldCreateCircularReference(string $proposedParentId): bool
    {
        // Self-reference check
        if ($this->exists && $proposedParentId === $this->getKey()) {
            return true;
        }

        // For new records, no cycle is possible
        if (!$this->exists) {
            return false;
        }

        $parentColumn = $this->getParentColumn();
        $currentId = $proposedParentId;
        $visited = [];
        $depth = 0;
        $maxDepth = $this->getMaxHierarchyDepth();

        while ($currentId !== null && $depth < $maxDepth) {
            // Found the current entity in the ancestor chain = cycle
            if ($currentId === $this->getKey()) {
                return true;
            }

            // Already visited this node = existing cycle in data
            if (isset($visited[$currentId])) {
                return true;
            }
            $visited[$currentId] = true;

            // Find the parent of the current node
            $query = static::where('record_id', $currentId);

            // Scope by partition if available
            if (property_exists($this, 'partition_id') && $this->partition_id) {
                $query->where('partition_id', $this->partition_id);
            }

            $ancestor = $query->first();

            if ($ancestor === null) {
                return false; // End of chain
            }

            $currentId = $ancestor->{$parentColumn};
            $depth++;
        }

        // Hit max depth - defensive: treat as potential cycle
        return $depth >= $maxDepth;
    }

    /**
     * Get all ancestor IDs for this entity (up the hierarchy).
     */
    public function getAncestorIds(int $maxDepth = null): array
    {
        $maxDepth = $maxDepth ?? $this->getMaxHierarchyDepth();
        $parentColumn = $this->getParentColumn();
        $ancestors = [];
        $currentId = $this->{$parentColumn};
        $depth = 0;

        while ($currentId !== null && $depth < $maxDepth) {
            if (in_array($currentId, $ancestors)) {
                break; // Cycle detected
            }
            $ancestors[] = $currentId;

            $query = static::where('record_id', $currentId);
            if (property_exists($this, 'partition_id') && $this->partition_id) {
                $query->where('partition_id', $this->partition_id);
            }

            $ancestor = $query->first();
            if ($ancestor === null) {
                break;
            }

            $currentId = $ancestor->{$parentColumn};
            $depth++;
        }

        return $ancestors;
    }

    /**
     * Get the hierarchy depth of this entity (0 = root, 1 = direct child, etc).
     */
    public function getHierarchyDepth(): int
    {
        return count($this->getAncestorIds());
    }

    /**
     * Check if this entity is a descendant of another entity.
     */
    public function isDescendantOf(string $ancestorId): bool
    {
        return in_array($ancestorId, $this->getAncestorIds());
    }

    /**
     * Check if this entity is an ancestor of another entity.
     */
    public function isAncestorOf(string $descendantId): bool
    {
        $descendant = static::find($descendantId);
        if (!$descendant || !method_exists($descendant, 'getAncestorIds')) {
            return false;
        }

        return in_array($this->getKey(), $descendant->getAncestorIds());
    }

    /**
     * Safe recursive deletion that prevents infinite loops.
     * Use instead of naive recursive deletion.
     *
     * @param  string|null  $deletedBy  The user ID performing the deletion
     * @param  array  $visited  Internal: tracks visited nodes to prevent cycles
     * @param  int  $depth  Internal: current recursion depth
     */
    public function safeDeleteWithChildren(?string $deletedBy = null, array &$visited = [], int $depth = 0): int
    {
        $maxDepth = $this->getMaxHierarchyDepth();

        // Prevent infinite recursion
        if ($depth >= $maxDepth || isset($visited[$this->getKey()])) {
            return 0;
        }

        $visited[$this->getKey()] = true;
        $deletedCount = 0;

        // Delete children first (if child relation is defined)
        $childRelation = $this->getChildRelation();
        if ($childRelation && method_exists($this, $childRelation)) {
            $children = $this->{$childRelation}()->get();
            foreach ($children as $child) {
                if (method_exists($child, 'safeDeleteWithChildren')) {
                    $deletedCount += $child->safeDeleteWithChildren($deletedBy, $visited, $depth + 1);
                }
            }
        }

        // Set deleted_by if model supports it
        if ($deletedBy && property_exists($this, 'deleted_by')) {
            $this->deleted_by = $deletedBy;
            $this->save();
        }

        // Delete self
        $this->delete();
        $deletedCount++;

        return $deletedCount;
    }
}
