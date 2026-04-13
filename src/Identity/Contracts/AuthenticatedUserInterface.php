<?php

namespace NewSolari\Core\Identity\Contracts;

/**
 * Interface for authenticated user objects.
 *
 * Both IdentityUser (Eloquent model) and UserContext (JWT-based adapter)
 * implement this interface, allowing modules to work with either backing
 * implementation without direct database coupling.
 */
interface AuthenticatedUserInterface
{
    public function getRecordId(): string;

    public function getPartitionId(): string;

    public function getUsername(): string;

    public function getEmail(): ?string;

    public function getFirstName(): ?string;

    public function getLastName(): ?string;

    public function getDisplayName(): ?string;

    public function isSystemUser(): bool;

    public function isActive(): bool;

    public function isPartitionAdmin(?string $partitionId = null): bool;

    public function hasPermission(string $permission): bool;

    public function hasGroup(string $group): bool;
}
