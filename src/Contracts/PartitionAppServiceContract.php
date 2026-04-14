<?php

namespace NewSolari\Core\Contracts;

/**
 * Contract for the partition app service.
 *
 * Used by CheckPartitionAppEnabled middleware, BaseController, and MiniAppBase.
 * The identity package provides the concrete implementation.
 */
interface PartitionAppServiceContract
{
    public function enable(string $partitionId, string $pluginSlug, IdentityUserContract $user): array;

    public function disable(string $partitionId, string $pluginSlug, IdentityUserContract $user): array;

    public function bulkUpdate(string $partitionId, array $updates, IdentityUserContract $user): array;
}
