<?php

namespace NewSolari\Core\Contracts;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Contract for the authorization service.
 *
 * Used by BaseController and plugin bases.
 * The identity package provides the concrete implementation.
 *
 * Only includes methods that core actually calls.
 */
interface AuthorizationServiceContract
{
    public function authorize(
        IdentityUserContract $user,
        string $action,
        string $entityPartitionId,
        ?string $ownerId = null,
        bool $isPublic = false,
        ?Model $entity = null
    ): bool;

    public function authorizeEntity(IdentityUserContract $user, string $action, Model $entity): bool;

    public function scopeAccessible($query, IdentityUserContract $user, bool $includePublic = true, bool $includeShared = true): Builder;
}
