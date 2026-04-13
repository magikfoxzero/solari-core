<?php

namespace NewSolari\Core\Identity\Controllers;

use NewSolari\Core\Http\BaseController;

use NewSolari\Core\Identity\Contracts\AuthenticatedUserInterface;
use NewSolari\Core\Identity\IdentityApiClient;
use NewSolari\Core\Identity\Models\RecordShare;
use NewSolari\Core\Services\RecordSharingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Controller for managing record shares.
 *
 * Provides endpoints for sharing records with specific users,
 * listing shares, and revoking shares.
 */
class RecordSharesController extends BaseController
{
    protected RecordSharingService $sharingService;

    protected IdentityApiClient $identityClient;

    /**
     * Model class map for resolving entity types to model classes.
     * Keys are the URL route parameter (plural table names).
     */
    protected array $modelMap = [
        'notes' => \NewSolari\Notes\Models\Note::class,
        'files' => \NewSolari\Files\Models\File::class,
        'tasks' => \NewSolari\Tasks\Models\Task::class,
        'events' => \NewSolari\Events\Models\Event::class,
        'folders' => \NewSolari\Folders\Models\Folder::class,
        'people' => \NewSolari\People\Models\Person::class,
        'entities' => \NewSolari\Entities\Models\Entity::class,
        'places' => \NewSolari\Places\Models\Place::class,
        'hypotheses' => \NewSolari\Hypotheses\Models\Hypothesis::class,
        'motives' => \NewSolari\Motives\Models\Motive::class,
        'reference_materials' => \NewSolari\ReferenceMaterials\Models\ReferenceMaterial::class,
        'inventory_objects' => \NewSolari\InventoryObjects\Models\InventoryObject::class,
        'investigations' => \NewSolari\Investigations\Models\Investigation::class,
        'tags' => \NewSolari\Tags\Models\Tag::class,
        'invoices' => \NewSolari\Invoices\Models\Invoice::class,
        'budgets' => \NewSolari\Budgets\Models\Budget::class,
    ];

    /**
     * Maps URL route parameters to morph aliases.
     */
    protected array $morphAliasMap = [
        'notes' => 'note',
        'files' => 'file',
        'tasks' => 'task',
        'events' => 'event',
        'folders' => 'folder',
        'people' => 'person',
        'entities' => 'entity',
        'places' => 'place',
        'hypotheses' => 'hypothesis',
        'motives' => 'motive',
        'reference_materials' => 'reference_material',
        'inventory_objects' => 'inventory_object',
        'investigations' => 'investigation',
        'tags' => 'tag',
        'invoices' => 'invoice',
        'budgets' => 'budget',
    ];

    public function __construct(RecordSharingService $sharingService, IdentityApiClient $identityClient)
    {
        $this->sharingService = $sharingService;
        $this->identityClient = $identityClient;
    }

    /**
     * Resolve route parameters correctly.
     * Handles cases where entity-specific routes use ->defaults() which can cause parameter issues,
     * or when route parameters come in wrong order.
     *
     * @return array{0: string, 1: string} [entityType, entityId]
     */
    protected function resolveRouteParameters(Request $request, string $entityType, string $entityId): array
    {
        $uuidPattern = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';
        $isEntityTypeUuid = preg_match($uuidPattern, $entityType);
        $isEntityIdType = isset($this->modelMap[$entityId]) || isset($this->morphAliasMap[$entityId]);

        // Case 1: Parameters are swapped (entityType is UUID, entityId is type name)
        if ($isEntityTypeUuid && $isEntityIdType) {
            return [$entityId, $entityType];
        }

        // Case 2: entityType is a UUID - check route defaults for the actual entity type
        if ($isEntityTypeUuid) {
            $route = $request->route();
            $defaults = $route ? $route->defaults : [];
            if (isset($defaults['entityType']) && isset($this->modelMap[$defaults['entityType']])) {
                // Use the default entity type from route definition, entityType param is actually the entityId
                return [$defaults['entityType'], $entityType];
            }
        }

        return [$entityType, $entityId];
    }

    /**
     * Share a record with users.
     * POST /api/{entityType}/{entityId}/shares
     */
    public function store(Request $request, string $entityType, string $entityId): JsonResponse
    {
        try {
            $user = $this->getAuthenticatedUser($request);

            $request->validate([
                'user_ids' => 'required|array|min:1|max:100',
                'user_ids.*' => 'required|string|max:64',
                'permission' => 'sometimes|in:read,write',
                'message' => 'nullable|string|max:500',
                'expires_at' => 'nullable|date|after:now',
            ]);

            // Get route parameters - prefer route() accessor for reliability with defaults()
            $routeEntityType = $request->route('entityType');
            $routeEntityId = $request->route('entityId');

            // Use route values if available, otherwise fall back to method params
            $actualEntityType = $routeEntityType ?? $entityType;
            $actualEntityId = $routeEntityId ?? $entityId;

            // Get route parameters correctly (handles both direct routes and routes using defaults)
            [$resolvedType, $resolvedId] = $this->resolveRouteParameters($request, $actualEntityType, $actualEntityId);

            // Resolve entity (filters by user's partition to prevent info leakage)
            $entity = $this->resolveEntity($resolvedType, $resolvedId, $user);
            if (!$entity) {
                return $this->errorResponse('Entity not found', 404);
            }

            // Check share permission
            if (!$entity->canUserShare($user)) {
                return $this->errorResponse('Permission denied', 403);
            }

            $permission = $request->input('permission', 'read');
            $message = $request->input('message');
            $expiresAt = $request->input('expires_at') ? new \DateTime($request->input('expires_at')) : null;

            $results = $this->sharingService->shareWithMultiple(
                $entity,
                $request->input('user_ids'),
                $user,
                $permission,
                $message,
                $expiresAt
            );

            return $this->successResponse([
                'shares' => $results['success'],
                'failed' => $results['failed'],
                'message' => count($results['success']) . ' share(s) created successfully',
            ], 201);

        } catch (ValidationException $e) {
            return $this->handleValidationException($e);
        } catch (\Exception $e) {
            \Log::error('Failed to create share', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return $this->errorResponse('Failed to create share', 500);
        }
    }

    /**
     * List shares for a record.
     * GET /api/{entityType}/{entityId}/shares
     */
    public function index(Request $request, string $entityType, string $entityId): JsonResponse
    {
        try {
            $user = $this->getAuthenticatedUser($request);

            // Get route parameters - prefer route() accessor for reliability with defaults()
            $routeEntityType = $request->route('entityType');
            $routeEntityId = $request->route('entityId');
            $actualEntityType = $routeEntityType ?? $entityType;
            $actualEntityId = $routeEntityId ?? $entityId;

            // Get route parameters correctly (handles both direct routes and routes using defaults)
            [$resolvedType, $resolvedId] = $this->resolveRouteParameters($request, $actualEntityType, $actualEntityId);

            // Resolve entity (filters by user's partition to prevent info leakage)
            $entity = $this->resolveEntity($resolvedType, $resolvedId, $user);
            if (!$entity) {
                return $this->errorResponse('Entity not found', 404);
            }

            // Only owner/admin can view shares
            if (!$entity->canUserShare($user)) {
                return $this->errorResponse('Permission denied', 403);
            }

            $shares = $this->sharingService->getSharesForEntity($entity);

            // Batch-fetch user display data via identity service instead of Eloquent relations
            $userIds = $shares->pluck('shared_with_user_id')->unique()->filter()->values()->all();
            $userMap = $this->batchFetchUsers($userIds);

            return $this->successResponse([
                'shares' => $shares->map(function ($share) use ($userMap) {
                    $sharedWithUser = $userMap[$share->shared_with_user_id] ?? null;
                    return [
                        'record_id' => $share->record_id,
                        'shared_with' => $sharedWithUser ? [
                            'record_id' => $sharedWithUser->record_id,
                            'username' => $sharedWithUser->username,
                            'first_name' => $sharedWithUser->first_name,
                            'last_name' => $sharedWithUser->last_name,
                        ] : null,
                        'permission' => $share->permission,
                        'message' => $share->share_message,
                        'expires_at' => $share->expires_at?->toIso8601String(),
                        'shared_by' => $share->shared_by,
                        'created_at' => $share->created_at->toIso8601String(),
                    ];
                }),
            ]);

        } catch (\Exception $e) {
            \Log::error('Failed to list shares', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return $this->errorResponse('Failed to list shares', 500);
        }
    }

    /**
     * Revoke a share.
     * DELETE /api/{entityType}/{entityId}/shares/{userId}
     *
     * SECURITY: Uses pessimistic locking to prevent TOCTOU race conditions.
     * All checks (entity exists, permission, share exists) happen atomically
     * within a single transaction with row-level locks.
     */
    public function destroy(Request $request, string $entityType, string $entityId, string $userId): JsonResponse
    {
        try {
            $user = $this->getAuthenticatedUser($request);

            // Get route parameters - prefer route() accessor for reliability with defaults()
            $routeEntityType = $request->route('entityType');
            $routeEntityId = $request->route('entityId');
            $actualEntityType = $routeEntityType ?? $entityType;
            $actualEntityId = $routeEntityId ?? $entityId;

            // Get route parameters correctly (handles both direct routes and routes using defaults)
            [$resolvedType, $resolvedId] = $this->resolveRouteParameters($request, $actualEntityType, $actualEntityId);

            // Get userId from route parameter directly - method injection can be unreliable with defaults()
            $resolvedUserId = $request->route('userId') ?? $userId;

            // Use lockForUpdate only on databases that support it (not SQLite)
            $useLocking = config('database.default') !== 'sqlite';

            // ATOMIC OPERATION: Wrap all checks and deletion in a single transaction
            return DB::transaction(function () use ($resolvedType, $resolvedId, $resolvedUserId, $user, $useLocking) {
                // Resolve entity with lock (filters by user's partition to prevent info leakage)
                $entity = $this->resolveEntityWithLock($resolvedType, $resolvedId, $user, $useLocking);
                if (!$entity) {
                    return $this->errorResponse('Entity not found', 404);
                }

                // Check permission (entity is now locked - state cannot change)
                if (!$entity->canUserShare($user)) {
                    return $this->errorResponse('Permission denied', 403);
                }

                // Find and lock share directly instead of looking up user first (prevents user enumeration)
                // Try both morph alias (e.g., 'entity') and route type (e.g., 'entities')
                $shareableType = $entity->getShareableType();
                $shareQuery = RecordShare::where('shareable_id', $resolvedId)
                    ->forUser($resolvedUserId)
                    ->where('deleted', false)
                    ->where(function ($query) use ($shareableType, $resolvedType) {
                        // Match either the morph alias or the route parameter type
                        $query->where('shareable_type', $shareableType);
                        if ($shareableType !== $resolvedType) {
                            $query->orWhere('shareable_type', $resolvedType);
                        }
                        // Also check for singular form of route type (e.g., 'entities' -> 'entity')
                        $singularType = $this->morphAliasMap[$resolvedType] ?? null;
                        if ($singularType && $singularType !== $shareableType) {
                            $query->orWhere('shareable_type', $singularType);
                        }
                    });

                // Apply pessimistic lock if supported
                if ($useLocking) {
                    $shareQuery->lockForUpdate();
                }

                $share = $shareQuery->first();

                if (!$share) {
                    // Log why share wasn't found (for debugging)
                    Log::info('Share not found for deletion', [
                        'shareable_type' => $shareableType,
                        'resolved_type' => $resolvedType,
                        'shareable_id' => $resolvedId,
                        'user_id' => $resolvedUserId,
                    ]);
                    // Generic error - don't reveal whether user exists or share exists
                    return $this->errorResponse('Share not found', 404);
                }

                // Perform the deletion atomically (share is locked)
                $share->deleted = true;
                $share->deleted_by = $user->record_id;
                $share->save();

                return $this->successResponse(['message' => 'Share revoked successfully']);
            });

        } catch (\Illuminate\Database\QueryException $e) {
            // Handle lock contention gracefully
            Log::warning('Share revocation failed due to lock contention', [
                'error' => $e->getMessage(),
            ]);
            return $this->errorResponse('Operation temporarily unavailable, please retry', 503);
        } catch (\InvalidArgumentException $e) {
            Log::warning('Invalid argument in revoke share', ['error' => $e->getMessage()]);
            return $this->errorResponse('Invalid share operation', 403);
        } catch (\Exception $e) {
            Log::error('Failed to revoke share', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return $this->errorResponse('Failed to revoke share', 500);
        }
    }

    /**
     * Update share permission.
     * PUT /api/{entityType}/{entityId}/shares/{userId}
     */
    public function update(Request $request, string $entityType, string $entityId, string $userId): JsonResponse
    {
        try {
            $user = $this->getAuthenticatedUser($request);

            $request->validate([
                'permission' => 'required|in:read,write',
            ]);

            // Get route parameters - prefer route() accessor for reliability with defaults()
            $routeEntityType = $request->route('entityType');
            $routeEntityId = $request->route('entityId');
            $actualEntityType = $routeEntityType ?? $entityType;
            $actualEntityId = $routeEntityId ?? $entityId;

            // Get route parameters correctly (handles both direct routes and routes using defaults)
            [$resolvedType, $resolvedId] = $this->resolveRouteParameters($request, $actualEntityType, $actualEntityId);

            // Get userId from route parameter directly - method injection can be unreliable with defaults()
            $resolvedUserId = $request->route('userId') ?? $userId;

            // Resolve entity (filters by user's partition to prevent info leakage)
            $entity = $this->resolveEntity($resolvedType, $resolvedId, $user);
            if (!$entity) {
                return $this->errorResponse('Entity not found', 404);
            }

            // Check share permission
            if (!$entity->canUserShare($user)) {
                return $this->errorResponse('Permission denied', 403);
            }

            // Try both morph alias and route type to handle any type mismatches
            $shareableType = $entity->getShareableType();
            $share = RecordShare::where('shareable_id', $resolvedId)
                ->forUser($resolvedUserId)
                ->active()
                ->where(function ($query) use ($shareableType, $resolvedType) {
                    $query->where('shareable_type', $shareableType);
                    if ($shareableType !== $resolvedType) {
                        $query->orWhere('shareable_type', $resolvedType);
                    }
                    $singularType = $this->morphAliasMap[$resolvedType] ?? null;
                    if ($singularType && $singularType !== $shareableType) {
                        $query->orWhere('shareable_type', $singularType);
                    }
                })
                ->first();

            if (!$share) {
                return $this->errorResponse('Share not found', 404);
            }

            $updatedShare = $this->sharingService->updatePermission(
                $share,
                $request->input('permission'),
                $user
            );

            return $this->successResponse([
                'share' => [
                    'record_id' => $updatedShare->record_id,
                    'permission' => $updatedShare->permission,
                    'updated_at' => $updatedShare->updated_at->toIso8601String(),
                ],
                'message' => 'Share updated successfully',
            ]);

        } catch (ValidationException $e) {
            return $this->handleValidationException($e);
        } catch (\Exception $e) {
            \Log::error('Failed to update share', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return $this->errorResponse('Failed to update share', 500);
        }
    }

    /**
     * Get records shared with the current user.
     * GET /api/shared-with-me
     */
    public function sharedWithMe(Request $request): JsonResponse
    {
        try {
            $user = $this->getAuthenticatedUser($request);

            $entityType = $request->query('type'); // Optional filter

            // Validate and convert entity type if provided
            if ($entityType) {
                // Convert plural route type to morph alias if it's a known plural form
                if (isset($this->morphAliasMap[$entityType])) {
                    $entityType = $this->morphAliasMap[$entityType];
                } elseif (!in_array($entityType, $this->morphAliasMap, true)) {
                    // Unknown entity type - return error
                    return $this->errorResponse('Invalid entity type', 400);
                }
            }

            $partitionId = $this->getPartitionId($request) ?? $user->partition_id;

            $shares = $this->sharingService->getSharedWithUser($user, $entityType, $partitionId);

            // Filter out shares where the entity is deleted or missing, then map
            $filteredShares = $shares->filter(function ($share) {
                // Exclude if shareable is null (entity was hard-deleted)
                $shareable = $share->shareable;
                if (!$shareable) {
                    return false;
                }
                // Check for soft delete flag using Eloquent attribute access
                // Use getAttribute() which works with Eloquent's dynamic attributes
                if ($shareable->getAttribute('deleted') === true) {
                    return false;
                }
                return true;
            });

            // Batch-fetch shared_by user display data via identity service
            $sharedByIds = $filteredShares->pluck('shared_by')->unique()->filter()->values()->all();
            $userMap = $this->batchFetchUsers($sharedByIds);

            return $this->successResponse([
                'shares' => $filteredShares->values()->map(function ($share) use ($userMap) {
                    // Return minimal entity data to prevent information disclosure
                    // Only include record_id and identifying fields (name/title/subject)
                    $entity = $share->shareable;
                    $entitySummary = [
                        'record_id' => $entity->record_id ?? $entity->getKey(),
                    ];

                    // Add common identifying fields if they exist
                    if (isset($entity->name)) {
                        $entitySummary['name'] = $entity->name;
                    }
                    if (isset($entity->title)) {
                        $entitySummary['title'] = $entity->title;
                    }
                    if (isset($entity->subject)) {
                        $entitySummary['subject'] = $entity->subject;
                    }

                    $sharedByUser = $userMap[$share->shared_by] ?? null;

                    return [
                        'record_id' => $share->record_id,
                        'entity_type' => $share->shareable_type,
                        'entity_id' => $share->shareable_id,
                        'entity' => $entitySummary,
                        'permission' => $share->permission,
                        'message' => $share->share_message,
                        'shared_by' => $sharedByUser ? [
                            'record_id' => $sharedByUser->record_id,
                            'username' => $sharedByUser->username,
                        ] : null,
                        'expires_at' => $share->expires_at?->toIso8601String(),
                        'created_at' => $share->created_at->toIso8601String(),
                    ];
                }),
            ]);

        } catch (\Exception $e) {
            \Log::error('Failed to get shared records', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return $this->errorResponse('Failed to get shared records', 500);
        }
    }

    /**
     * Batch-fetch user display data via IdentityApiClient.
     *
     * Falls back to direct Eloquent lookup when the identity service is
     * unavailable (e.g., monolith mode or test environment). This
     * transitional fallback will be removed once identity is fully extracted.
     *
     * @param  string[]  $userIds
     * @return array<string, \NewSolari\Core\Identity\UserContext|\NewSolari\Core\Identity\Models\IdentityUser> Keyed by user record_id
     */
    protected function batchFetchUsers(array $userIds): array
    {
        $userMap = [];
        $missingIds = [];

        // Try identity service first
        foreach ($userIds as $userId) {
            $userContext = $this->identityClient->getUser($userId);
            if ($userContext) {
                $userMap[$userId] = $userContext;
            } else {
                $missingIds[] = $userId;
            }
        }

        // Transitional fallback: direct Eloquent lookup for users not found via API
        // (covers monolith mode and test environments where identity service is unavailable)
        if ($missingIds && class_exists(\NewSolari\Core\Identity\Models\IdentityUser::class)) {
            try {
                $fallbackUsers = \NewSolari\Core\Identity\Models\IdentityUser::whereIn('record_id', $missingIds)->get();
                foreach ($fallbackUsers as $fallbackUser) {
                    $userMap[$fallbackUser->record_id] = $fallbackUser;
                }
            } catch (\Exception $e) {
                // Table may not exist in extracted service — silently skip
                Log::debug('IdentityUser Eloquent fallback unavailable', ['error' => $e->getMessage()]);
            }
        }

        return $userMap;
    }

    /**
     * Resolve entity from type and ID.
     * For security, filters by user's partition to prevent information leakage.
     */
    protected function resolveEntity(string $type, string $id, ?AuthenticatedUserInterface $user = null): ?object
    {
        return $this->resolveEntityWithLock($type, $id, $user, false);
    }

    /**
     * Resolve entity from type and ID with optional pessimistic locking.
     * For security, filters by user's partition to prevent information leakage.
     *
     * @param bool $useLocking Whether to apply lockForUpdate (should be false for SQLite)
     */
    protected function resolveEntityWithLock(string $type, string $id, ?AuthenticatedUserInterface $user = null, bool $useLocking = false): ?object
    {
        // First try to find in modelMap directly (plural forms)
        $modelClass = $this->modelMap[$type] ?? null;

        // If not found, check if it's a singular form and convert to plural
        if (!$modelClass) {
            $pluralType = array_search($type, $this->morphAliasMap, true);
            if ($pluralType !== false) {
                $modelClass = $this->modelMap[$pluralType] ?? null;
            }
        }

        if (!$modelClass) {
            Log::warning('Share resolveEntity: No model class found', ['type' => $type]);
            return null;
        }

        if (!class_exists($modelClass)) {
            Log::warning('Share resolveEntity: Model class does not exist', ['modelClass' => $modelClass]);
            return null;
        }

        $query = $modelClass::where('record_id', $id);

        // For non-system users, filter by partition to prevent information leakage
        // (attacker can't determine if entity exists in another partition)
        if ($user && !$user->is_system_user) {
            $query->where('partition_id', $user->partition_id);
        }

        // Apply pessimistic lock if requested (for TOCTOU protection)
        if ($useLocking) {
            $query->lockForUpdate();
        }

        $entity = $query->first();

        if (!$entity) {
            // Log entity not found without revealing cross-partition existence
            // Security: Do NOT check global existence - this would leak information about other partitions
            Log::info('Share resolveEntity: Entity not found in user partition', [
                'type' => $type,
                'id' => $id,
            ]);
        }

        return $entity;
    }
}
