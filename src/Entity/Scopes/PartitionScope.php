<?php

namespace NewSolari\Core\Entity\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;

/**
 * Global scope for partition-based multi-tenant data isolation.
 *
 * This scope automatically filters queries by partition_id to ensure
 * data isolation between tenants. It resolves the partition context from:
 * 1. Laravel Context (for background jobs)
 * 2. Request attributes (set by middleware)
 * 3. X-Partition-ID header
 * 4. Authenticated user's partition
 *
 * @see DB-HIGH-002 - Missing Global Partition Scopes
 */
class PartitionScope implements Scope
{
    public const SCOPE_NAME = 'partition';

    /**
     * Apply the partition scope to the query.
     *
     * Records are included if:
     * 1. partition_id matches the current partition context
     * 2. partition_id is NULL (system-wide records accessible to all partitions)
     *
     * System users (is_system_user = true) bypass partition filtering and can
     * access records from all partitions.
     */
    public function apply(Builder $builder, Model $model): void
    {
        // System users can access all partitions
        $user = Auth::user();
        if ($user && $user->is_system_user) {
            return;
        }

        $partitionId = $this->resolvePartitionId();

        if ($partitionId === null) {
            if ($user) {
                // SECURITY: Authenticated non-system user with no resolved partition_id
                // must NOT see data from any partition. Apply impossible condition.
                Log::warning('PartitionScope: No partition context for authenticated user, denying all data', [
                    'model' => get_class($model),
                    'table' => $model->getTable(),
                    'user_id' => $user->id ?? $user->record_id ?? 'unknown',
                ]);

                $builder->whereRaw('1 = 0');

                return;
            }

            // Unauthenticated requests (login, registration, public endpoints) are
            // expected to have no partition context — allow query without filtering.
            Log::debug('PartitionScope: No partition context for unauthenticated request', [
                'model' => get_class($model),
                'table' => $model->getTable(),
            ]);

            return;
        }

        $table = $model->getTable();

        // Include records that match the partition OR have NULL partition_id (system-wide)
        $builder->where(function ($query) use ($table, $partitionId) {
            $query->where($table.'.partition_id', $partitionId)
                  ->orWhereNull($table.'.partition_id');
        });
    }

    /**
     * Resolve the partition ID from available contexts.
     */
    protected function resolvePartitionId(): ?string
    {
        // 1. Check Laravel Context (for background jobs and queued work)
        if (Context::has('partition_id')) {
            return Context::get('partition_id');
        }

        // 2. Check request attributes (set by middleware - most secure source)
        $request = request();
        if ($request && $request->attributes->has('partition_id')) {
            return $request->attributes->get('partition_id');
        }

        // 3. Check request header (for partition switching)
        if ($request && $request->hasHeader('X-Partition-ID')) {
            return $request->header('X-Partition-ID');
        }

        // Also check lowercase/alternate variants
        if ($request && ($request->hasHeader('X-Partition') || $request->hasHeader('x-partition'))) {
            return $request->header('X-Partition') ?? $request->header('x-partition');
        }

        // 4. Fall back to authenticated user's partition
        $user = Auth::user();
        if ($user && isset($user->partition_id)) {
            return $user->partition_id;
        }

        return null;
    }

    /**
     * Extend the query builder with partition-related macros.
     */
    public function extend(Builder $builder): void
    {
        // Query a specific partition (bypasses automatic filtering)
        $builder->macro('forPartition', function (Builder $builder, string $partitionId) {
            return $builder->withoutGlobalScope(PartitionScope::SCOPE_NAME)
                ->where($builder->getModel()->getTable().'.partition_id', $partitionId);
        });

        // Query all partitions (bypasses partition filtering entirely)
        $builder->macro('allPartitions', function (Builder $builder) {
            return $builder->withoutGlobalScope(PartitionScope::SCOPE_NAME);
        });

        // Alias for clarity in admin contexts
        $builder->macro('withoutPartitionScope', function (Builder $builder) {
            return $builder->withoutGlobalScope(PartitionScope::SCOPE_NAME);
        });
    }
}
