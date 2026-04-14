<?php

namespace NewSolari\Core\Contracts;

/**
 * Contract for the authorization service.
 *
 * Used by BaseController, plugin bases, and permission middleware.
 * The identity package provides the concrete implementation.
 */
interface AuthorizationServiceContract
{
    public function authorize(IdentityUserContract $user, $entity, string $action): bool;

    public function authorizeEntity(IdentityUserContract $user, $entity, string $action): bool;

    public function canAccessPartition(IdentityUserContract $user, string $partitionId): bool;

    public function scopeAccessible($query, IdentityUserContract $user, ?string $partitionId = null);
}
