<?php

namespace NewSolari\Core\Contracts;

/**
 * Contract for the partition app service.
 *
 * Used by CheckPartitionAppEnabled middleware, BaseController, and MiniAppBase.
 * The identity package provides the concrete implementation.
 *
 * Only includes methods that core actually calls. The full service has
 * additional methods (enable, disable, bulkUpdate) used by identity controllers.
 */
interface PartitionAppServiceContract
{
    public function isEnabled(string $partitionId, string $pluginId): bool;

    public function isAdminOnly(string $partitionId, string $pluginId): bool;
}
