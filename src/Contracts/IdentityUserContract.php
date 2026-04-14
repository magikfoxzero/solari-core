<?php

namespace NewSolari\Core\Contracts;

/**
 * Contract for the identity user model.
 *
 * Core's middleware, BaseController, and plugin system type-hint this
 * interface. The identity package provides the concrete implementation
 * (IdentityUser) and registers it via container bindings.
 *
 * Eloquent properties accessed via magic getters:
 * - record_id, partition_id, username, email
 * - is_system_user, is_active
 * - first_name, last_name, display_name
 * - is_partition_admin
 */
interface IdentityUserContract
{
    public function hasPermission(string $permission): bool;

    public function isPartitionAdmin(?string $partitionId = null): bool;

    public function authenticate(string $password): bool;

    public function getAuthPassword(): string;

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    public function partitions(): mixed;

    public function getAdminPartitionIds(): array;

    public function needsEmailVerification(): bool;
}
