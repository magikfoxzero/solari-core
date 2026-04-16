<?php

namespace NewSolari\Core\Contracts;

/**
 * Contract for the identity user model.
 *
 * Core's middleware, BaseController, and plugin system type-hint this
 * interface. The identity package provides the concrete implementation
 * (IdentityUser) and registers it via container bindings.
 *
 * Eloquent properties still accessed via magic getters (not in contract):
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

    public function getRecordId(): string;

    public function getEmail(): ?string;

    public function getUsername(): string;

    public function getFirstName(): ?string;

    public function getLastName(): ?string;

    public function getDisplayName(): string;

    public function getPartitionId(): ?string;

    public function isSystemUser(): bool;

    public function isActive(): bool;
}
