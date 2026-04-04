<?php

namespace NewSolari\Core\Entity\Traits;

use Illuminate\Database\Eloquent\Builder;

/**
 * Soft delete trait using a boolean 'deleted' column.
 *
 * This trait provides soft delete functionality by setting a 'deleted' column to true
 * instead of actually removing records from the database.
 *
 * Usage:
 * 1. Add the trait to your model: use SoftDeletes;
 * 2. Add 'deleted' to your model's $fillable array
 * 3. Ensure your table has a 'deleted' boolean column (default false)
 */
trait SoftDeletes
{
    /**
     * Indicates if the model is currently force deleting.
     *
     * @var bool
     */
    protected $forceDeleting = false;

    /**
     * Boot the soft delete trait for a model.
     */
    public static function bootSoftDeletes(): void
    {
        // Add a global scope to exclude deleted records by default
        static::addGlobalScope('notDeleted', function (Builder $builder) {
            $builder->where($builder->getModel()->getTable() . '.deleted', false);
        });
    }

    /**
     * Initialize the soft delete trait.
     */
    public function initializeSoftDeletes(): void
    {
        // Ensure deleted is cast to boolean
        if (! isset($this->casts['deleted'])) {
            $this->casts['deleted'] = 'boolean';
        }

        // Automatically add 'deleted' to fillable if not already present
        if (! in_array('deleted', $this->fillable)) {
            $this->fillable[] = 'deleted';
        }
    }

    /**
     * Perform the soft delete operation.
     * Sets deleted = true instead of removing the record.
     *
     * @return bool
     */
    public function delete(): bool
    {
        return $this->softDelete();
    }

    /**
     * Explicit soft delete method.
     * Sets deleted = true instead of removing the record.
     *
     * @return bool
     */
    public function softDelete(): bool
    {
        // Fire deleting event
        if ($this->fireModelEvent('deleting') === false) {
            return false;
        }

        $this->deleted = true;
        $result = $this->save();

        // Fire deleted event
        $this->fireModelEvent('deleted', false);

        return $result;
    }

    /**
     * Force delete the model from the database.
     * This performs an actual hard delete.
     *
     * @return bool|null
     */
    public function forceDelete(): ?bool
    {
        $this->forceDeleting = true;

        return tap(parent::delete(), function () {
            $this->forceDeleting = false;
        });
    }

    /**
     * Determine if the model is currently force deleting.
     *
     * @return bool
     */
    public function isForceDeleting(): bool
    {
        return $this->forceDeleting;
    }

    /**
     * Restore a soft-deleted model.
     *
     * @return bool
     */
    public function restore(): bool
    {
        // Fire restoring event
        if ($this->fireModelEvent('restoring') === false) {
            return false;
        }

        $this->deleted = false;
        $result = $this->save();

        // Fire restored event
        $this->fireModelEvent('restored', false);

        return $result;
    }

    /**
     * Check if the model is soft deleted.
     *
     * @return bool
     */
    public function trashed(): bool
    {
        return $this->deleted === true;
    }

    /**
     * Include soft deleted records in the query.
     *
     * @param  Builder  $query
     * @return Builder
     */
    public function scopeWithDeleted(Builder $query): Builder
    {
        return $query->withoutGlobalScope('notDeleted');
    }

    /**
     * Only include soft deleted records in the query.
     *
     * @param  Builder  $query
     * @return Builder
     */
    public function scopeOnlyDeleted(Builder $query): Builder
    {
        return $query->withoutGlobalScope('notDeleted')
            ->where($this->getTable() . '.deleted', true);
    }

    /**
     * Register a "restoring" model event callback.
     *
     * @param  \Closure|string  $callback
     * @return void
     */
    public static function restoring($callback): void
    {
        static::registerModelEvent('restoring', $callback);
    }

    /**
     * Register a "restored" model event callback.
     *
     * @param  \Closure|string  $callback
     * @return void
     */
    public static function restored($callback): void
    {
        static::registerModelEvent('restored', $callback);
    }
}
