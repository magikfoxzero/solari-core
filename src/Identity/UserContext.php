<?php

namespace NewSolari\Core\Identity;

use NewSolari\Core\Identity\Contracts\AuthenticatedUserInterface;

/**
 * JWT-backed adapter implementing AuthenticatedUserInterface.
 *
 * UserContext is constructed from decoded JWT claims or identity service
 * API responses, providing the same interface as IdentityUser without
 * any database coupling.
 */
class UserContext implements AuthenticatedUserInterface
{
    public readonly string $record_id;
    public readonly string $partition_id;
    public readonly string $username;
    public readonly ?string $email;
    public readonly ?string $first_name;
    public readonly ?string $last_name;
    public readonly ?string $display_name;
    public readonly bool $is_system_user;
    public readonly bool $is_active;
    public readonly bool $is_partition_admin;
    public readonly array $permissions;
    public readonly array $groups;

    private function __construct(
        string $record_id,
        string $partition_id,
        string $username,
        ?string $email,
        ?string $first_name,
        ?string $last_name,
        ?string $display_name,
        bool $is_system_user,
        bool $is_active,
        bool $is_partition_admin,
        array $permissions,
        array $groups,
    ) {
        $this->record_id = $record_id;
        $this->partition_id = $partition_id;
        $this->username = $username;
        $this->email = $email;
        $this->first_name = $first_name;
        $this->last_name = $last_name;
        $this->display_name = $display_name;
        $this->is_system_user = $is_system_user;
        $this->is_active = $is_active;
        $this->is_partition_admin = $is_partition_admin;
        $this->permissions = $permissions;
        $this->groups = $groups;
    }

    /**
     * Create a UserContext from decoded JWT claims.
     *
     * Maps the OIDC `sub` claim to `record_id`. Computes `display_name`
     * from first_name + last_name, falling back to username.
     */
    public static function fromJwtClaims(object $claims): self
    {
        $firstName = $claims->first_name ?? null;
        $lastName = $claims->last_name ?? null;
        $displayName = trim(($firstName ?? '') . ' ' . ($lastName ?? '')) ?: ($claims->username ?? '');

        return new self(
            record_id: $claims->sub,
            partition_id: $claims->partition_id,
            username: $claims->username,
            email: $claims->email ?? null,
            first_name: $firstName,
            last_name: $lastName,
            display_name: $displayName ?: null,
            is_system_user: $claims->is_system_user ?? false,
            is_active: $claims->is_active ?? true,
            is_partition_admin: $claims->is_partition_admin ?? false,
            permissions: (array) ($claims->permissions ?? []),
            groups: (array) ($claims->groups ?? []),
        );
    }

    /**
     * Create a UserContext from an identity service API response.
     *
     * Unlike JWT claims, the API response uses `record_id` directly
     * (not `sub`). Computes `display_name` from first_name + last_name,
     * falling back to username.
     */
    public static function fromApiResponse(object $data): self
    {
        $firstName = $data->first_name ?? null;
        $lastName = $data->last_name ?? null;
        $displayName = trim(($firstName ?? '') . ' ' . ($lastName ?? '')) ?: ($data->username ?? '');

        return new self(
            record_id: $data->record_id,
            partition_id: $data->partition_id,
            username: $data->username,
            email: $data->email ?? null,
            first_name: $firstName,
            last_name: $lastName,
            display_name: $displayName ?: null,
            is_system_user: $data->is_system_user ?? false,
            is_active: $data->is_active ?? true,
            is_partition_admin: $data->is_partition_admin ?? false,
            permissions: (array) ($data->permissions ?? []),
            groups: (array) ($data->groups ?? []),
        );
    }

    public function getRecordId(): string
    {
        return $this->record_id;
    }

    public function getPartitionId(): string
    {
        return $this->partition_id;
    }

    public function getUsername(): string
    {
        return $this->username;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function getFirstName(): ?string
    {
        return $this->first_name;
    }

    public function getLastName(): ?string
    {
        return $this->last_name;
    }

    public function getDisplayName(): ?string
    {
        return $this->display_name;
    }

    public function isSystemUser(): bool
    {
        return $this->is_system_user;
    }

    public function isActive(): bool
    {
        return $this->is_active;
    }

    public function isPartitionAdmin(?string $partitionId = null): bool
    {
        if ($this->is_system_user) {
            return true;
        }

        $partitionId = $partitionId ?? $this->partition_id;

        return $this->is_partition_admin && $this->partition_id === $partitionId;
    }

    public function hasPermission(string $permission): bool
    {
        if ($this->is_system_user) {
            return true;
        }

        return in_array($permission, $this->permissions, true);
    }

    public function hasGroup(string $group): bool
    {
        return in_array($group, $this->groups, true);
    }
}
