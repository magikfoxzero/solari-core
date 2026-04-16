<?php

namespace NewSolari\Core\Http\Traits;

use NewSolari\Folders\Models\Folder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use NewSolari\Core\Entity\Models\EntityRelationship;

trait RelationshipControllerTrait
{
    /**
     * List all relationships for an entity.
     *
     * BIDIRECTIONAL: By default, fetches relationships where the entity is either
     * the source OR the target. Each relationship is returned only once (deduplicated
     * by record_id). The response includes both source and target entities for proper
     * bidirectional display.
     *
     * GET /api/v1/{entity-type}/{id}/relationships
     *
     * @param  string  $id  Entity ID
     */
    public function listRelationships(Request $request, string $id): JsonResponse
    {
        try {
            $entity = $this->findEntity($id);

            if (! $entity) {
                return response()->json([
                    'value' => false,
                    'result' => 'Entity not found',
                    'code' => 404,
                ], 404);
            }

            // Check permissions
            // SECURITY: Get user from attributes only (set by middleware) to prevent POST body injection
            $authenticatedUser = $this->getAuthenticatedUser($request);

            // SECURITY: Validate entity belongs to user's accessible partitions
            // This prevents IDOR attacks where users access entities from other partitions by guessing IDs
            if (! $this->canAccessEntityPartition($entity, $authenticatedUser)) {
                return response()->json([
                    'value' => false,
                    'result' => 'Entity not found',
                    'code' => 404,
                ], 404);
            }

            // Security: Check folder read access when listing relationships FOR a folder
            // Private folders can only have their relationships viewed by owner/admins
            if ($entity instanceof Folder) {
                $canReadFolder = $authenticatedUser->is_system_user
                    || $authenticatedUser->isPartitionAdmin($entity->partition_id)
                    || $entity->created_by === $authenticatedUser->getKey()
                    || $entity->is_public;

                if (! $canReadFolder) {
                    return response()->json([
                        'value' => false,
                        'result' => 'Permission denied: cannot view this folder',
                        'code' => 403,
                    ], 403);
                }
            }

            // Entity-level view permission is already checked by canAccessEntityPartition above.
            // The RelationshipPermissions::canViewRelationship method requires a specific
            // relationship record, so it's checked per-relationship below, not at entity level.

            // Get query parameters with bounds validation
            $perPage = min($request->input('per_page', 50), config('relationships.api.max_per_page', 200));
            $perPage = max($perPage, 1); // Ensure minimum of 1 to prevent off-by-one errors
            $relationshipType = $request->input('relationship_type');
            // BIDIRECTIONAL: Default both to true for truly bidirectional relationships
            $asSource = $request->boolean('as_source', true);
            $asTarget = $request->boolean('as_target', true);
            $includeDeleted = $request->boolean('include_deleted', false);

            // Build query - use a Set to deduplicate by record_id
            $relationshipsById = collect();

            if ($asSource) {
                $query = $entity->relationshipsAsSource();

                if ($relationshipType) {
                    $query->where('relationship_type', $relationshipType);
                }

                // Load both source and target for bidirectional display
                $sourceRelationships = $query->with(['source', 'target', 'typeDefinition'])->get();
                foreach ($sourceRelationships as $rel) {
                    $relationshipsById[$rel->record_id] = $rel;
                }
            }

            if ($asTarget) {
                $query = $entity->relationshipsAsTarget();

                if ($relationshipType) {
                    $query->where('relationship_type', $relationshipType);
                }

                // Load both source and target for bidirectional display
                $targetRelationships = $query->with(['source', 'target', 'typeDefinition'])->get();
                foreach ($targetRelationships as $rel) {
                    // Only add if not already present (deduplication by record_id)
                    if (! isset($relationshipsById[$rel->record_id])) {
                        $relationshipsById[$rel->record_id] = $rel;
                    }
                }
            }

            $relationships = $relationshipsById->values();

            // If include_deleted is requested, also fetch from history table
            if ($includeDeleted) {
                // EntityRelationshipHistory not implemented yet — skip
                // TODO: Implement when history table is available
            }

            // API-MED-010: Filter cross-partition source/target entities from response
            $relationships = $this->filterCrossPartitionRelationships($relationships, $entity, $authenticatedUser);

            return response()->json([
                'value' => true,
                'result' => [
                    'relationships' => $relationships,
                    'total' => $relationships->count(),
                    'as_source' => $asSource,
                    'as_target' => $asTarget,
                ],
                'code' => 200,
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'value' => false,
                'result' => 'Entity not found',
                'code' => 404,
            ], 404);
        } catch (\Exception $e) {
            \Log::error('Failed to retrieve relationships', ['entity_id' => $id, 'error' => $e->getMessage()]);

            return response()->json([
                'value' => false,
                'result' => 'Failed to retrieve relationships',
                'code' => 500,
            ], 500);
        }
    }

    /**
     * List relationships grouped by type.
     *
     * BIDIRECTIONAL: By default, fetches relationships where the entity is either
     * the source OR the target. Each relationship is returned only once (deduplicated
     * by record_id).
     *
     * GET /api/v1/{entity-type}/{id}/relationships/by-type
     *
     * @param  string  $id  Entity ID
     */
    public function listRelationshipsByType(Request $request, string $id): JsonResponse
    {
        try {
            $entity = $this->findEntity($id);

            if (! $entity) {
                return response()->json([
                    'value' => false,
                    'result' => 'Entity not found',
                    'code' => 404,
                ], 404);
            }

            // SECURITY: Get user from attributes only (set by middleware) to prevent POST body injection
            $authenticatedUser = $this->getAuthenticatedUser($request);

            // SECURITY: Validate entity belongs to user's accessible partitions
            // This prevents IDOR attacks where users access entities from other partitions by guessing IDs
            if (! $this->canAccessEntityPartition($entity, $authenticatedUser)) {
                return response()->json([
                    'value' => false,
                    'result' => 'Entity not found',
                    'code' => 404,
                ], 404);
            }

            // Security: Check folder read access when listing relationships FOR a folder
            // Private folders can only have their relationships viewed by owner/admins
            if ($entity instanceof Folder) {
                $canReadFolder = $authenticatedUser->is_system_user
                    || $authenticatedUser->isPartitionAdmin($entity->partition_id)
                    || $entity->created_by === $authenticatedUser->getKey()
                    || $entity->is_public;

                if (! $canReadFolder) {
                    return response()->json([
                        'value' => false,
                        'result' => 'Permission denied: cannot view this folder',
                        'code' => 403,
                    ], 403);
                }
            }

            // BIDIRECTIONAL: Default both to true
            $asSource = $request->boolean('as_source', true);
            $asTarget = $request->boolean('as_target', true);
            $includeDeleted = $request->boolean('include_deleted', false);

            // Use a map to deduplicate by record_id
            $relationshipsById = collect();

            if ($asSource) {
                $query = $entity->relationshipsAsSource();
                $sourceRelationships = $query->with(['target', 'source', 'typeDefinition'])->get();
                foreach ($sourceRelationships as $rel) {
                    $relationshipsById[$rel->record_id] = $rel;
                }
            }

            if ($asTarget) {
                $query = $entity->relationshipsAsTarget();
                $targetRelationships = $query->with(['target', 'source', 'typeDefinition'])->get();
                foreach ($targetRelationships as $rel) {
                    // Only add if not already present (deduplication)
                    if (! isset($relationshipsById[$rel->record_id])) {
                        $relationshipsById[$rel->record_id] = $rel;
                    }
                }
            }

            $relationships = $relationshipsById->values();

            // If include_deleted is requested, also fetch from history table
            if ($includeDeleted) {
                // EntityRelationshipHistory not implemented yet — skip
                // TODO: Implement when history table is available
            }

            // API-MED-010: Filter cross-partition source/target entities from response
            $relationships = $this->filterCrossPartitionRelationships($relationships, $entity, $authenticatedUser);

            $grouped = $relationships->groupBy('relationship_type');

            return response()->json([
                'value' => true,
                'result' => [
                    'relationships_by_type' => $grouped,
                    'total_types' => $grouped->count(),
                    'total_relationships' => $relationships->count(),
                ],
                'code' => 200,
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'value' => false,
                'result' => 'Entity not found',
                'code' => 404,
            ], 404);
        } catch (\Exception $e) {
            \Log::error('Failed to retrieve relationships by type', ['entity_id' => $id, 'error' => $e->getMessage()]);

            return response()->json([
                'value' => false,
                'result' => 'Failed to retrieve relationships by type',
                'code' => 500,
            ], 500);
        }
    }

    /**
     * Create a new relationship.
     *
     * POST /api/v1/{entity-type}/{id}/relationships
     *
     * @param  string  $id  Entity ID
     */
    public function createRelationship(Request $request, string $id): JsonResponse
    {
        try {
            $entity = $this->findEntity($id);

            if (! $entity) {
                return response()->json([
                    'value' => false,
                    'result' => 'Entity not found',
                    'code' => 404,
                ], 404);
            }

            // Validate request
            $validator = Validator::make($request->all(), [
                'target_type' => 'required|string|max:64',
                'target_id' => 'required|string|max:36',
                'relationship_type' => 'required|string|max:64',
                'relationship_subtype' => 'nullable|string|max:64',
                'metadata' => 'nullable|array',
                'priority' => 'nullable|integer|min:0',
                'is_primary' => 'nullable|boolean',
                'create_inverse' => 'nullable|boolean',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'value' => false,
                    'result' => 'Validation failed: '.$validator->errors()->first(),
                    'code' => 422,
                ], 422);
            }

            // SECURITY: Get user from attributes only (set by middleware) to prevent POST body injection
            $authenticatedUser = $this->getAuthenticatedUser($request);

            // SECURITY: Validate entity belongs to user's accessible partitions
            // This prevents IDOR attacks where users access entities from other partitions by guessing IDs
            if (! $this->canAccessEntityPartition($entity, $authenticatedUser)) {
                return response()->json([
                    'value' => false,
                    'result' => 'Entity not found',
                    'code' => 404,
                ], 404);
            }

            // Security: Check folder access when the source entity IS a folder
            // This prevents users from adding relationships via /folders/{id}/relationships
            // to folders they don't have write access to
            if ($entity instanceof Folder) {
                $canWriteToSourceFolder = $authenticatedUser->is_system_user
                    || $authenticatedUser->isPartitionAdmin($entity->partition_id)
                    || $entity->created_by === $authenticatedUser->getKey();

                if (! $canWriteToSourceFolder) {
                    return response()->json([
                        'value' => false,
                        'result' => 'Permission denied: cannot modify this folder',
                        'code' => 403,
                    ], 403);
                }
            }

            // Security: Check folder access when adding items TO a folder (target_type === 'folders')
            if ($request->target_type === 'folders') {
                $folderQuery = Folder::where('record_id', $request->target_id);
                // System admins bypass partition filtering
                if (!$authenticatedUser->is_system_user) {
                    $folderQuery->where('partition_id', $entity->partition_id);
                }
                $folder = $folderQuery->first();

                if (! $folder) {
                    return response()->json([
                        'value' => false,
                        'result' => 'Target folder not found',
                        'code' => 404,
                    ], 404);
                }

                // Check if user can write to this folder (owned by user, or admin)
                $canWriteToFolder = $authenticatedUser->is_system_user
                    || $authenticatedUser->isPartitionAdmin($entity->partition_id)
                    || $folder->created_by === $authenticatedUser->getKey();

                if (! $canWriteToFolder) {
                    return response()->json([
                        'value' => false,
                        'result' => 'Permission denied: cannot add items to this folder',
                        'code' => 403,
                    ], 403);
                }
            }

            // Check permissions
            if (method_exists($entity, 'canAttachEntity')) {
                if (! $entity->canAttachEntity($authenticatedUser, $request->target_id, $request->relationship_type)) {
                    return response()->json([
                        'value' => false,
                        'result' => 'Permission denied',
                        'code' => 403,
                    ], 403);
                }
            }

            // Get created_by from the authenticated user (already retrieved above)
            $createdBy = $authenticatedUser ? $authenticatedUser->getKey() : null;

            // Create relationship
            $relationship = $entity->attachEntity(
                $request->target_id,
                $request->relationship_type,
                $request->input('metadata', []),
                [
                    'target_type' => $request->target_type,
                    'relationship_subtype' => $request->relationship_subtype,
                    'priority' => $request->input('priority', 0),
                    'is_primary' => $request->boolean('is_primary', false),
                    'create_inverse' => $request->boolean('create_inverse', false),
                    'created_by' => $createdBy,
                ]
            );

            return response()->json([
                'value' => true,
                'result' => $relationship->load(['target', 'typeDefinition']),
                'code' => 201,
            ], 201);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'value' => false,
                'result' => 'Entity not found',
                'code' => 404,
            ], 404);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'value' => false,
                'result' => 'Validation failed: '.collect($e->errors())->flatten()->first(),
                'code' => 422,
            ], 422);
        } catch (\Exception $e) {
            \Log::error('Failed to create relationship', [
                'entity_id' => $id,
                'target_type' => $request->target_type ?? null,
                'target_id' => $request->target_id ?? null,
                'relationship_type' => $request->relationship_type ?? null,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'value' => false,
                'result' => 'Failed to create relationship',
                'code' => 500,
            ], 500);
        }
    }

    /**
     * Update an existing relationship.
     *
     * PUT/PATCH /api/v1/{entity-type}/{id}/relationships/{relationship_id}
     *
     * @param  string  $id  Entity ID
     * @param  string  $relationshipId  Relationship ID
     */
    public function updateRelationship(Request $request, string $id, string $relationshipId): JsonResponse
    {
        try {
            $entity = $this->findEntity($id);

            if (! $entity) {
                return response()->json([
                    'value' => false,
                    'result' => 'Entity not found',
                    'code' => 404,
                ], 404);
            }

            // SECURITY: Get user from attributes only (set by middleware) to prevent POST body injection
            $authenticatedUser = $this->getAuthenticatedUser($request);

            // Get relationship with partition isolation - only find relationships in the entity's partition
            $relationshipQuery = EntityRelationship::where('record_id', $relationshipId);
            // System admins bypass partition filtering
            if (!$authenticatedUser->is_system_user) {
                $relationshipQuery->where('partition_id', $entity->partition_id);
            }
            $relationship = $relationshipQuery->first();

            if (! $relationship) {
                return response()->json([
                    'value' => false,
                    'result' => 'Relationship not found',
                    'code' => 404,
                ], 404);
            }

            // Verify that the relationship belongs to this entity (either as source or target)
            // We check by entity ID since record_ids are UUIDs and unique across the system
            $entityId = $entity->getKey();
            $isOwner = $relationship->source_id === $entityId || $relationship->target_id === $entityId;

            if (! $isOwner) {
                return response()->json([
                    'value' => false,
                    'result' => 'Relationship does not belong to this entity',
                    'code' => 404,
                ], 404);
            }

            // SECURITY: Validate entity belongs to user's accessible partitions
            // This prevents IDOR attacks where users access entities from other partitions by guessing IDs
            if (! $this->canAccessEntityPartition($entity, $authenticatedUser)) {
                return response()->json([
                    'value' => false,
                    'result' => 'Entity not found',
                    'code' => 404,
                ], 404);
            }

            // Security: Check folder access when the source entity IS a folder
            if ($entity instanceof Folder) {
                $canWriteToSourceFolder = $authenticatedUser->is_system_user
                    || $authenticatedUser->isPartitionAdmin($entity->partition_id)
                    || $entity->created_by === $authenticatedUser->getKey();

                if (! $canWriteToSourceFolder) {
                    return response()->json([
                        'value' => false,
                        'result' => 'Permission denied: cannot modify this folder',
                        'code' => 403,
                    ], 403);
                }
            }

            if (method_exists($entity, 'canUpdateRelationship') && $authenticatedUser) {
                if (! $entity->canUpdateRelationship($authenticatedUser, $relationship)) {
                    return response()->json([
                        'value' => false,
                        'result' => 'Permission denied',
                        'code' => 403,
                    ], 403);
                }
            }

            // Validate request
            $validator = Validator::make($request->all(), [
                'metadata' => 'nullable|array',
                'priority' => 'nullable|integer|min:0',
                'is_primary' => 'nullable|boolean',
                'relationship_subtype' => 'nullable|string|max:64',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'value' => false,
                    'result' => 'Validation failed: '.$validator->errors()->first(),
                    'code' => 422,
                ], 422);
            }

            // Update relationship
            $updateData = [];

            if ($request->has('metadata')) {
                $updateData['metadata'] = $request->metadata;
            }

            if ($request->has('priority')) {
                $updateData['priority'] = $request->priority;
            }

            if ($request->has('is_primary')) {
                $updateData['is_primary'] = $request->boolean('is_primary');
            }

            if ($request->has('relationship_subtype')) {
                $updateData['relationship_subtype'] = $request->relationship_subtype;
            }

            $updateData['updated_by'] = $authenticatedUser ? $authenticatedUser->getKey() : null;

            $relationship->update($updateData);

            return response()->json([
                'value' => true,
                'result' => $relationship->fresh(['target', 'typeDefinition']),
                'code' => 200,
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'value' => false,
                'result' => 'Entity not found',
                'code' => 404,
            ], 404);
        } catch (\Exception $e) {
            \Log::error('Failed to update relationship', ['entity_id' => $id, 'relationship_id' => $relationshipId, 'error' => $e->getMessage()]);

            return response()->json([
                'value' => false,
                'result' => 'Failed to update relationship',
                'code' => 500,
            ], 500);
        }
    }

    /**
     * Delete a relationship.
     *
     * DELETE /api/v1/{entity-type}/{id}/relationships/{relationship_id}
     *
     * @param  string  $id  Entity ID
     * @param  string  $relationshipId  Relationship ID
     */
    public function deleteRelationship(Request $request, string $id, string $relationshipId): JsonResponse
    {
        try {
            $entity = $this->findEntity($id);

            if (! $entity) {
                return response()->json([
                    'value' => false,
                    'result' => 'Entity not found',
                    'code' => 404,
                ], 404);
            }

            // SECURITY: Get user from attributes only (set by middleware) to prevent POST body injection
            $authenticatedUser = $this->getAuthenticatedUser($request);

            // Get relationship with partition isolation - only find relationships in the entity's partition
            $relationshipQuery = EntityRelationship::where('record_id', $relationshipId);
            // System admins bypass partition filtering
            if (!$authenticatedUser->is_system_user) {
                $relationshipQuery->where('partition_id', $entity->partition_id);
            }
            $relationship = $relationshipQuery->first();

            if (! $relationship) {
                return response()->json([
                    'value' => false,
                    'result' => 'Relationship not found',
                    'code' => 404,
                ], 404);
            }

            // Verify that the relationship belongs to this entity (either as source or target)
            // We check by entity ID since record_ids are UUIDs and unique across the system
            $entityId = $entity->getKey();
            $isOwner = $relationship->source_id === $entityId || $relationship->target_id === $entityId;

            if (! $isOwner) {
                return response()->json([
                    'value' => false,
                    'result' => 'Relationship does not belong to this entity',
                    'code' => 404,
                ], 404);
            }

            // SECURITY: Validate entity belongs to user's accessible partitions
            // This prevents IDOR attacks where users access entities from other partitions by guessing IDs
            if (! $this->canAccessEntityPartition($entity, $authenticatedUser)) {
                return response()->json([
                    'value' => false,
                    'result' => 'Entity not found',
                    'code' => 404,
                ], 404);
            }

            // Security: Check folder access when the source entity IS a folder
            if ($entity instanceof Folder) {
                $canWriteToSourceFolder = $authenticatedUser->is_system_user
                    || $authenticatedUser->isPartitionAdmin($entity->partition_id)
                    || $entity->created_by === $authenticatedUser->getKey();

                if (! $canWriteToSourceFolder) {
                    return response()->json([
                        'value' => false,
                        'result' => 'Permission denied: cannot modify this folder',
                        'code' => 403,
                    ], 403);
                }
            }

            if (method_exists($entity, 'canDeleteRelationship') && $authenticatedUser) {
                if (! $entity->canDeleteRelationship($authenticatedUser, $relationship)) {
                    return response()->json([
                        'value' => false,
                        'result' => 'Permission denied',
                        'code' => 403,
                    ], 403);
                }
            }

            // Archive and delete relationship
            $deletedBy = $authenticatedUser ? $authenticatedUser->getKey() : null;
            if (! $deletedBy) {
                return response()->json([
                    'value' => false,
                    'result' => 'Cannot delete: User ID required for audit trail',
                    'code' => 400,
                ], 400);
            }

            $relationship->delete();

            return response()->json([
                'value' => true,
                'result' => 'Relationship deleted successfully',
                'code' => 200,
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'value' => false,
                'result' => 'Entity not found',
                'code' => 404,
            ], 404);
        } catch (\Exception $e) {
            \Log::error('Failed to delete relationship', ['entity_id' => $id, 'relationship_id' => $relationshipId, 'error' => $e->getMessage()]);

            return response()->json([
                'value' => false,
                'result' => 'Failed to delete relationship',
                'code' => 500,
            ], 500);
        }
    }

    /**
     * Sync relationships for a specific type.
     *
     * POST /api/v1/{entity-type}/{id}/relationships/sync
     *
     * @param  string  $id  Entity ID
     */
    public function syncRelationships(Request $request, string $id): JsonResponse
    {
        try {
            $entity = $this->findEntity($id);

            if (! $entity) {
                return response()->json([
                    'value' => false,
                    'result' => 'Entity not found',
                    'code' => 404,
                ], 404);
            }

            // Validate request with max limit to prevent DoS
            $validator = Validator::make($request->all(), [
                'target_type' => 'required|string|max:64',
                'relationship_type' => 'required|string|max:64',
                'entities' => 'required|array|max:200',
                'entities.*' => 'string|max:36',
                'metadata' => 'nullable|array',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'value' => false,
                    'result' => 'Validation failed: '.$validator->errors()->first(),
                    'code' => 422,
                ], 422);
            }

            // Get the authenticated user using the proper method chain
            // SECURITY: Get user from attributes only (set by middleware) to prevent POST body injection
            $authenticatedUser = $this->getAuthenticatedUser($request);

            // SECURITY: Validate entity belongs to user's accessible partitions
            // This prevents IDOR attacks where users access entities from other partitions by guessing IDs
            if (! $this->canAccessEntityPartition($entity, $authenticatedUser)) {
                return response()->json([
                    'value' => false,
                    'result' => 'Entity not found',
                    'code' => 404,
                ], 404);
            }

            // Security: Check folder access when the source entity IS a folder
            if ($entity instanceof Folder) {
                $canWriteToSourceFolder = $authenticatedUser->is_system_user
                    || $authenticatedUser->isPartitionAdmin($entity->partition_id)
                    || $entity->created_by === $authenticatedUser->getKey();

                if (! $canWriteToSourceFolder) {
                    return response()->json([
                        'value' => false,
                        'result' => 'Permission denied: cannot modify this folder',
                        'code' => 403,
                    ], 403);
                }
            }

            // Security: Check folder access when syncing items TO folders (target_type === 'folders')
            if ($request->target_type === 'folders') {
                foreach ($request->entities as $folderId) {
                    $folderQuery = Folder::where('record_id', $folderId);
                    // System admins bypass partition filtering
                    if (!$authenticatedUser->is_system_user) {
                        $folderQuery->where('partition_id', $entity->partition_id);
                    }
                    $folder = $folderQuery->first();

                    if (! $folder) {
                        return response()->json([
                            'value' => false,
                            'result' => 'Target folder not found: '.$folderId,
                            'code' => 404,
                        ], 404);
                    }

                    // Check if user can write to this folder (owned by user, or admin)
                    $canWriteToFolder = $authenticatedUser->is_system_user
                        || $authenticatedUser->isPartitionAdmin($entity->partition_id)
                        || $folder->created_by === $authenticatedUser->getKey();

                    if (! $canWriteToFolder) {
                        return response()->json([
                            'value' => false,
                            'result' => 'Permission denied: cannot add items to folder '.$folder->name,
                            'code' => 403,
                        ], 403);
                    }
                }
            }

            $createdBy = $authenticatedUser ? $authenticatedUser->getKey() : null;

            // Sync relationships
            $results = $entity->syncEntities(
                $request->target_type,
                $request->entities,
                $request->relationship_type,
                [
                    'metadata' => $request->input('metadata', []),
                    'created_by' => $createdBy,
                ]
            );

            return response()->json([
                'value' => true,
                'result' => [
                    'attached' => $results['attached'],
                    'detached' => $results['detached'],
                    'updated' => $results['updated'],
                    'attached_count' => count($results['attached']),
                    'detached_count' => count($results['detached']),
                    'updated_count' => count($results['updated']),
                ],
                'code' => 200,
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'value' => false,
                'result' => 'Entity not found',
                'code' => 404,
            ], 404);
        } catch (\Exception $e) {
            \Log::error('Failed to sync relationships', ['entity_id' => $id, 'error' => $e->getMessage()]);

            return response()->json([
                'value' => false,
                'result' => 'Failed to sync relationships',
                'code' => 500,
            ], 500);
        }
    }

    /**
     * Check if user can access the entity's partition.
     *
     * SECURITY: This prevents IDOR attacks where users access entities from other partitions by guessing IDs.
     * System admins can access all partitions.
     * Partition admins can access their administered partitions.
     * Regular users can only access entities in their assigned partitions.
     *
     * @param  mixed  $entity  The entity to check access for
     * @param  mixed  $user  The authenticated user
     * @return bool True if user can access the entity's partition
     */
    protected function canAccessEntityPartition($entity, $user): bool
    {
        if (! $user || ! $entity) {
            return false;
        }

        // System admins can access all partitions
        if ($user->is_system_user) {
            return true;
        }

        // Check if entity has partition_id
        if (! isset($entity->partition_id) || $entity->partition_id === null) {
            return true; // Entity has no partition restriction
        }

        // Partition admins can access their administered partition
        if ($user->isPartitionAdmin($entity->partition_id)) {
            return true;
        }

        // Regular users can only access entities in their home partition
        return $user->partition_id === $entity->partition_id;
    }

    /**
     * Filter cross-partition source/target entities from relationship responses.
     *
     * API-MED-010: Polymorphic morphTo relations can load entities from other partitions.
     * This sanitizes responses to null out any source/target entities that:
     * 1. Have a different partition_id than the requested entity
     * 2. The user doesn't have access to
     *
     * @param  \Illuminate\Support\Collection  $relationships  Collection of relationships
     * @param  mixed  $entity  The requested entity
     * @param  mixed  $user  The authenticated user
     * @return \Illuminate\Support\Collection Sanitized relationships
     */
    protected function filterCrossPartitionRelationships($relationships, $entity, $user): \Illuminate\Support\Collection
    {
        $entityPartitionId = $entity->partition_id ?? null;

        return $relationships->map(function ($rel) use ($entityPartitionId, $user) {
            // Check source entity partition
            if ($rel->relationLoaded('source') && $rel->source) {
                $sourcePartition = $rel->source->partition_id ?? null;
                if ($sourcePartition !== null && $sourcePartition !== $entityPartitionId) {
                    if (! $this->canAccessEntityPartition($rel->source, $user)) {
                        $rel->setRelation('source', null);
                        \Log::warning('API-MED-010: Cross-partition source filtered', [
                            'relationship_id' => $rel->record_id ?? $rel->original_record_id ?? 'unknown',
                            'source_partition' => $sourcePartition,
                            'entity_partition' => $entityPartitionId,
                        ]);
                    }
                }
            }

            // Check target entity partition
            if ($rel->relationLoaded('target') && $rel->target) {
                $targetPartition = $rel->target->partition_id ?? null;
                if ($targetPartition !== null && $targetPartition !== $entityPartitionId) {
                    if (! $this->canAccessEntityPartition($rel->target, $user)) {
                        $rel->setRelation('target', null);
                        \Log::warning('API-MED-010: Cross-partition target filtered', [
                            'relationship_id' => $rel->record_id ?? $rel->original_record_id ?? 'unknown',
                            'target_partition' => $targetPartition,
                            'entity_partition' => $entityPartitionId,
                        ]);
                    }
                }
            }

            return $rel;
        });
    }

    /**
     * Find entity by ID.
     * This method should be implemented in the controller.
     *
     * @return mixed
     */
    abstract protected function findEntity(string $id);
}
