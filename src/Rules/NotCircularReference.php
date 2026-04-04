<?php

namespace NewSolari\Core\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Model;

/**
 * Validates that setting a parent reference won't create a circular reference.
 *
 * Use for self-referential relationships (e.g., Task -> parent_task_id, Folder -> parent_folder_id)
 * to prevent infinite loops during traversal or cascade operations.
 */
class NotCircularReference implements ValidationRule
{
    protected string $modelClass;

    protected string $parentColumn;

    protected ?string $entityId;

    protected ?string $partitionId;

    protected int $maxDepth;

    /**
     * Create a new rule instance.
     *
     * @param  string  $modelClass  The Eloquent model class (e.g., Task::class)
     * @param  string  $parentColumn  The column name for the parent reference (e.g., 'parent_task_id')
     * @param  string|null  $entityId  The ID of the entity being updated (null for create)
     * @param  string|null  $partitionId  The partition ID to scope the query
     * @param  int  $maxDepth  Maximum traversal depth (prevents runaway queries)
     */
    public function __construct(
        string $modelClass,
        string $parentColumn,
        ?string $entityId = null,
        ?string $partitionId = null,
        int $maxDepth = 20
    ) {
        $this->modelClass = $modelClass;
        $this->parentColumn = $parentColumn;
        $this->entityId = $entityId;
        $this->partitionId = $partitionId;
        $this->maxDepth = $maxDepth;
    }

    /**
     * Run the validation rule.
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // Null parent is always valid (no cycle possible)
        if ($value === null || $value === '') {
            return;
        }

        // Self-reference check (entity pointing to itself)
        if ($this->entityId !== null && $value === $this->entityId) {
            $fail('The :attribute cannot reference itself (self-reference detected).');

            return;
        }

        // Check if setting this parent would create a cycle
        if ($this->wouldCreateCycle($value)) {
            $fail('The :attribute would create a circular reference chain.');
        }
    }

    /**
     * Check if setting the proposed parent would create a circular reference.
     */
    protected function wouldCreateCycle(string $proposedParentId): bool
    {
        // If no entity ID (create operation), can only check if parent exists
        // and doesn't already point back to something (which is valid on create)
        if ($this->entityId === null) {
            return false;
        }

        // Traverse up the parent chain starting from the proposed parent
        // If we encounter the entity we're updating, it would create a cycle
        $currentId = $proposedParentId;
        $visited = [];
        $depth = 0;

        while ($currentId !== null && $depth < $this->maxDepth) {
            // Found the entity we're updating in the ancestor chain = cycle
            if ($currentId === $this->entityId) {
                return true;
            }

            // Already visited this node = existing cycle in data
            if (isset($visited[$currentId])) {
                return true;
            }
            $visited[$currentId] = true;

            // Find the parent of the current node
            $query = $this->modelClass::where('record_id', $currentId);

            // Scope by partition if provided
            if ($this->partitionId !== null) {
                $query->where('partition_id', $this->partitionId);
            }

            $entity = $query->first();

            // End of chain (no more parents)
            if ($entity === null) {
                return false;
            }

            // Move up to the next parent
            $currentId = $entity->{$this->parentColumn};
            $depth++;
        }

        // If we hit max depth, assume there might be a cycle (defensive)
        // This protects against both real cycles and maliciously deep hierarchies
        return $depth >= $this->maxDepth;
    }
}
