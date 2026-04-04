<?php

namespace NewSolari\Core\Identity\Controllers;

use NewSolari\Core\Http\BaseController;

use NewSolari\Core\Constants\ApiConstants;
use NewSolari\Core\Identity\Models\IdentityUser;
use NewSolari\Core\Identity\Models\Permission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PermissionController extends BaseController
{
    public function index(Request $request): JsonResponse
    {
        $this->logRequest($request, 'index', 'permissions');

        $user = $this->getAuthenticatedUser($request);
        $partitionId = $this->getPartitionId($request);

        // Load groups to ensure isPartitionAdmin works correctly
        if (! $user->is_system_user) {
            $user->load('groups.permissions');
            if (! $user->isPartitionAdmin($partitionId)) {
                return $this->errorResponse('Unauthorized', 403);
            }
        }

        // Permissions are system-wide - return all permissions
        // Both system admins and partition admins can view all permissions
        $query = Permission::orderBy('name');

        // Apply search filter if provided
        $search = $request->get('search');
        if (! empty($search)) {
            $query->where('name', 'like', '%'.$search.'%');
        }

        // Handle pagination with max limit to prevent resource exhaustion
        $perPage = min((int) $request->get('per_page', ApiConstants::PAGINATION_DEFAULT), ApiConstants::PAGINATION_MAX);
        $perPage = max($perPage, ApiConstants::PAGINATION_MIN);
        $permissions = $query->paginate($perPage);

        return $this->successResponse([
            'permissions' => $permissions->items(),
            'pagination' => [
                'total' => $permissions->total(),
                'per_page' => $permissions->perPage(),
                'current_page' => $permissions->currentPage(),
                'last_page' => $permissions->lastPage(),
                'from' => $permissions->firstItem(),
                'to' => $permissions->lastItem(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->logRequest($request, 'store', 'permissions');

        $user = $this->getAuthenticatedUser($request);

        // Only system admins can create permissions (they are system-wide)
        if (! $user->is_system_user) {
            return $this->errorResponse('Only system administrators can create permissions', 403);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:permissions',
            'description' => 'nullable|string',
            'permission_type' => 'required|string|in:View,Create,Update,Delete,ViewAll,Admin,Manage,system,plugin',
            'entity_type' => 'required|string|max:255',
        ]);

        // Permissions are system-wide - no partition_id
        $permission = Permission::createWithValidation($validated);

        Log::info('Permission created', [
            'permission_id' => $permission->record_id,
            'permission_name' => $permission->name,
            'permission_type' => $permission->permission_type,
            'entity_type' => $permission->entity_type,
            'created_by' => $user->record_id,
            'created_by_username' => $user->username,
            'ip' => $request->ip(),
        ]);

        return $this->successResponse($permission, 201);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $this->logRequest($request, 'show', 'permissions');

        $user = $this->getAuthenticatedUser($request);
        $permission = Permission::find($id);

        if (! $permission) {
            return $this->errorResponse('Permission not found', 404);
        }

        // Load groups to ensure isPartitionAdmin works correctly
        if (! $user->is_system_user) {
            $user->load('groups.permissions');
            if (! $user->isPartitionAdmin($this->getPartitionId($request))) {
                return $this->errorResponse('Unauthorized', 403);
            }
        }

        // Permissions are system-wide - partition admins can view any permission
        return $this->successResponse($permission);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $this->logRequest($request, 'update', 'permissions');

        $user = $this->getAuthenticatedUser($request);

        // Only system admins can update permissions (they are system-wide)
        if (! $user->is_system_user) {
            return $this->errorResponse('Only system administrators can update permissions', 403);
        }

        $permission = Permission::find($id);

        if (! $permission) {
            return $this->errorResponse('Permission not found', 404);
        }

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255|unique:permissions,name,'.$id.',record_id',
            'description' => 'nullable|string',
            'permission_type' => 'sometimes|string|in:View,Create,Update,Delete,ViewAll,Admin,Manage,system,plugin',
            'entity_type' => 'sometimes|string|max:255',
        ]);

        // If permission_type and entity_type are not provided, keep existing values
        if (! isset($validated['permission_type'])) {
            $validated['permission_type'] = $permission->permission_type;
        }
        if (! isset($validated['entity_type'])) {
            $validated['entity_type'] = $permission->entity_type;
        }

        $permission->updateWithValidation($validated);

        return $this->successResponse($permission);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $this->logRequest($request, 'destroy', 'permissions');

        $user = $this->getAuthenticatedUser($request);

        // Only system admins can delete permissions (they are system-wide)
        if (! $user->is_system_user) {
            return $this->errorResponse('Only system administrators can delete permissions', 403);
        }

        $permission = Permission::find($id);

        if (! $permission) {
            return $this->errorResponse('Permission not found', 404);
        }

        // Check if permission is assigned to any groups or users
        if ($permission->groups()->count() > 0 || $permission->users()->count() > 0) {
            return $this->errorResponse('Cannot delete permission that is in use', 400);
        }

        $deletedPermissionId = $permission->record_id;
        $deletedPermissionName = $permission->name;

        $permission->deleteWithValidation();

        Log::info('Permission deleted', [
            'permission_id' => $deletedPermissionId,
            'permission_name' => $deletedPermissionName,
            'deleted_by' => $user->record_id,
            'deleted_by_username' => $user->username,
            'ip' => $request->ip(),
        ]);

        return $this->successResponse(['message' => 'Permission deleted successfully']);
    }

    public function getPermissionTypes(): JsonResponse
    {
        $this->logRequest(request(), 'get_permission_types', 'permissions');

        $user = $this->getAuthenticatedUser(request());

        Log::debug('Permission types endpoint hit', [
            'user' => $user ? $user->username : 'null',
            'is_system_user' => $user ? $user->is_system_user : 'null',
        ]);

        // Only system users can access permission types
        if (! $user->is_system_user) {
            return $this->errorResponse('Forbidden', 403);
        }

        $permissionTypes = [
            'View', 'Create', 'Update', 'Delete', 'ViewAll', 'Admin', 'Manage', 'system', 'plugin',
        ];

        return $this->successResponse(['types' => $permissionTypes]);
    }

    public function assignUserPermission(Request $request, string $userId, string $permissionId): JsonResponse
    {
        $this->logRequest($request, 'assign_user_permission', 'permissions');

        $authenticatedUser = $this->getAuthenticatedUser($request);
        $partitionId = $this->getPartitionId($request);

        // Load user's groups and permissions for partition admin check
        if (! $authenticatedUser->is_system_user) {
            $authenticatedUser->load('groups.permissions');
        }

        // Regular users cannot assign permissions to users
        if (! $authenticatedUser->is_system_user && ! $authenticatedUser->isPartitionAdmin($partitionId)) {
            return $this->errorResponse('Unauthorized', 403);
        }

        // Find user with partition isolation for non-system users
        $userQuery = IdentityUser::where('record_id', $userId);
        if (!$authenticatedUser->is_system_user) {
            $userQuery->where('partition_id', $partitionId);
        }
        $user = $userQuery->first();

        if (! $user) {
            return $this->errorResponse('User not found', 404);
        }

        $permission = Permission::find($permissionId);

        if (! $permission) {
            return $this->errorResponse('Permission not found', 404);
        }

        if ($user->assignPermission($permissionId)) {
            Log::info('Permission assigned to user', [
                'target_user_id' => $user->record_id,
                'target_username' => $user->username,
                'permission_id' => $permission->record_id,
                'permission_name' => $permission->name,
                'assigned_by' => $authenticatedUser->record_id,
                'assigned_by_username' => $authenticatedUser->username,
                'partition_id' => $partitionId,
                'ip' => $request->ip(),
            ]);

            return $this->successResponse([
                'message' => 'Permission assigned to user successfully',
            ]);
        }

        return $this->errorResponse('Permission already assigned to user', 400);
    }

    public function revokeUserPermission(Request $request, string $userId, string $permissionId): JsonResponse
    {
        $this->logRequest($request, 'revoke_user_permission', 'permissions');

        $authenticatedUser = $this->getAuthenticatedUser($request);
        $partitionId = $this->getPartitionId($request);

        // Load user's groups and permissions for partition admin check
        if (! $authenticatedUser->is_system_user) {
            $authenticatedUser->load('groups.permissions');
        }

        // Regular users cannot revoke permissions from users
        if (! $authenticatedUser->is_system_user && ! $authenticatedUser->isPartitionAdmin($partitionId)) {
            return $this->errorResponse('Unauthorized', 403);
        }

        // Find user with partition isolation for non-system users
        $userQuery = IdentityUser::where('record_id', $userId);
        if (!$authenticatedUser->is_system_user) {
            $userQuery->where('partition_id', $partitionId);
        }
        $user = $userQuery->first();

        if (! $user) {
            return $this->errorResponse('User not found', 404);
        }

        $permission = Permission::find($permissionId);

        if (! $permission) {
            return $this->errorResponse('Permission not found', 404);
        }

        if ($user->revokePermission($permissionId)) {
            Log::info('Permission revoked from user', [
                'target_user_id' => $user->record_id,
                'target_username' => $user->username,
                'permission_id' => $permission->record_id,
                'permission_name' => $permission->name,
                'revoked_by' => $authenticatedUser->record_id,
                'revoked_by_username' => $authenticatedUser->username,
                'partition_id' => $partitionId,
                'ip' => $request->ip(),
            ]);

            return $this->successResponse([
                'message' => 'Permission revoked from user successfully',
            ]);
        }

        return $this->errorResponse('Permission not assigned to user', 400);
    }
}
