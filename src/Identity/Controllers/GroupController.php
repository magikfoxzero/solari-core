<?php

namespace NewSolari\Core\Identity\Controllers;

use NewSolari\Core\Http\BaseController;

use NewSolari\Core\Identity\Models\Group;
use NewSolari\Core\Identity\Models\IdentityUser;
use NewSolari\Core\Identity\Models\Permission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class GroupController extends BaseController
{
    public function index(Request $request): JsonResponse
    {
        $this->logRequest($request, 'index', 'groups');

        $user = $this->getAuthenticatedUser($request);
        $partitionId = $this->getPartitionId($request);

        // Load user's groups and permissions for partition admin check
        if (! $user->is_system_user) {
            $user->load('groups.permissions');
        }

        // Regular users cannot access groups
        if (! $user->is_system_user && ! $user->isPartitionAdmin($partitionId)) {
            return $this->errorResponse('Unauthorized', 403);
        }

        // Build query - filter by partition for non-system users
        // System admins can see all groups (PartitionScope already bypasses for them)
        $query = Group::with(['users', 'permissions']);

        if ($partitionId && ! $user->is_system_user) {
            $query->where('partition_id', $partitionId);
        }

        $groups = $query->get();

        return $this->successResponse($groups);
    }

    public function store(Request $request): JsonResponse
    {
        $this->logRequest($request, 'store', 'groups');

        $user = $this->getAuthenticatedUser($request);
        $requestPartitionId = $this->getPartitionId($request);

        // Load user's groups and permissions for partition admin check
        if (! $user->is_system_user) {
            $user->load('groups.permissions');
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:groups',
            'description' => 'nullable|string',
            'is_active' => 'boolean',
            'partition_id' => 'nullable|string|exists:identity_partitions,record_id',
        ]);

        // Default to X-Partition-ID header if partition_id not provided in body
        $targetPartitionId = $validated['partition_id'] ?? $requestPartitionId;

        if (! $targetPartitionId) {
            return $this->errorResponse('Partition ID is required (via request body or X-Partition-ID header)', 400);
        }

        // Partition admins can only create groups in partitions they administer
        if (! $user->is_system_user && ! $user->isPartitionAdmin($targetPartitionId)) {
            return $this->errorResponse('Unauthorized', 403);
        }

        $validated['partition_id'] = $targetPartitionId;
        $group = Group::createWithValidation($validated);

        return $this->successResponse($group, 201);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $this->logRequest($request, 'show', 'groups');

        $user = $this->getAuthenticatedUser($request);
        $group = Group::with(['users', 'permissions'])->find($id);

        if (! $group) {
            return $this->errorResponse('Group not found', 404);
        }

        // Load user's groups and permissions for partition admin check
        if (! $user->is_system_user) {
            $user->load('groups.permissions');
        }

        // Regular users cannot access groups
        if (! $user->is_system_user && ! $user->isPartitionAdmin($this->getPartitionId($request))) {
            return $this->errorResponse('Unauthorized', 403);
        }

        // Partition admins can only access groups in their partition
        if (! $user->is_system_user) {
            $partitionId = $this->getPartitionId($request);
            if ($group->partition_id !== $partitionId) {
                return $this->errorResponse('Unauthorized', 403);
            }
        }

        return $this->successResponse($group);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $this->logRequest($request, 'update', 'groups');

        $user = $this->getAuthenticatedUser($request);
        $group = Group::find($id);

        if (! $group) {
            return $this->errorResponse('Group not found', 404);
        }

        // Load user's groups and permissions for partition admin check
        if (! $user->is_system_user) {
            $user->load('groups.permissions');
        }

        // Regular users cannot update groups
        if (! $user->is_system_user && ! $user->isPartitionAdmin($this->getPartitionId($request))) {
            return $this->errorResponse('Unauthorized', 403);
        }

        // Partition admins can only update groups in their partition
        if (! $user->is_system_user) {
            $partitionId = $this->getPartitionId($request);
            if ($group->partition_id !== $partitionId) {
                return $this->errorResponse('Unauthorized', 403);
            }
        }

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255|unique:groups,name,'.$id.',record_id',
            'description' => 'nullable|string',
            'is_active' => 'boolean',
        ]);

        $group->updateWithValidation($validated);

        return $this->successResponse($group);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $this->logRequest($request, 'destroy', 'groups');

        $user = $this->getAuthenticatedUser($request);
        $group = Group::find($id);

        if (! $group) {
            return $this->errorResponse('Group not found', 404);
        }

        // Load user's groups and permissions for partition admin check
        if (! $user->is_system_user) {
            $user->load('groups.permissions');
        }

        // Regular users cannot delete groups
        if (! $user->is_system_user && ! $user->isPartitionAdmin($this->getPartitionId($request))) {
            return $this->errorResponse('Unauthorized', 403);
        }

        // Partition admins can only delete groups in their partition
        if (! $user->is_system_user) {
            $partitionId = $this->getPartitionId($request);
            if ($group->partition_id !== $partitionId) {
                return $this->errorResponse('Unauthorized', 403);
            }
        }

        // Check if group has users
        if ($group->users()->count() > 0) {
            return $this->errorResponse('Cannot delete group with users', 400);
        }

        $group->deleteWithValidation();

        return $this->successResponse(['message' => 'Group deleted successfully'], 200);
    }

    public function getUsers(Request $request, string $id): JsonResponse
    {
        $this->logRequest($request, 'get_users', 'groups');

        $user = $this->getAuthenticatedUser($request);
        $group = Group::find($id);

        if (! $group) {
            return $this->errorResponse('Group not found', 404);
        }

        // Load user's groups and permissions for partition admin check
        if (! $user->is_system_user) {
            $user->load('groups.permissions');
        }

        // Regular users cannot access group users
        if (! $user->is_system_user && ! $user->isPartitionAdmin($this->getPartitionId($request))) {
            return $this->errorResponse('Unauthorized', 403);
        }

        // Partition admins can only access groups in their partition
        if (! $user->is_system_user) {
            $partitionId = $this->getPartitionId($request);
            if ($group->partition_id !== $partitionId) {
                return $this->errorResponse('Unauthorized', 403);
            }
        }

        $users = $group->users()->with(['permissions', 'partitions'])->get();

        return $this->successResponse($users);
    }

    public function addUser(Request $request, string $id, string $userId): JsonResponse
    {
        $this->logRequest($request, 'add_user', 'groups');

        $authenticatedUser = $this->getAuthenticatedUser($request);
        $group = Group::find($id);

        if (! $group) {
            return $this->errorResponse('Group not found', 404);
        }

        // Load user's groups and permissions for partition admin check
        if (! $authenticatedUser->is_system_user) {
            $authenticatedUser->load('groups.permissions');
        }

        // Regular users cannot add users to groups
        if (! $authenticatedUser->is_system_user && ! $authenticatedUser->isPartitionAdmin($this->getPartitionId($request))) {
            return $this->errorResponse('Unauthorized', 403);
        }

        // Partition admins can only manage groups in their partition
        if (! $authenticatedUser->is_system_user) {
            $partitionId = $this->getPartitionId($request);
            if ($group->partition_id !== $partitionId) {
                return $this->errorResponse('Unauthorized', 403);
            }
        }

        // Find user with partition isolation - only find users in the group's partition for non-system users
        $userQuery = IdentityUser::where('record_id', $userId);
        if (!$authenticatedUser->is_system_user) {
            $userQuery->where('partition_id', $group->partition_id);
        }
        $user = $userQuery->first();

        if (! $user) {
            return $this->errorResponse('User not found', 404);
        }

        if ($group->addUser($userId)) {
            Log::info('User added to group', [
                'group_id' => $group->record_id,
                'group_name' => $group->name,
                'target_user_id' => $user->record_id,
                'target_username' => $user->username,
                'added_by' => $authenticatedUser->record_id,
                'added_by_username' => $authenticatedUser->username,
                'partition_id' => $group->partition_id,
                'ip' => $request->ip(),
            ]);

            return $this->successResponse([
                'message' => 'User added to group successfully',
            ]);
        }

        return $this->errorResponse('User already in group', 400);
    }

    public function removeUser(Request $request, string $id, string $userId): JsonResponse
    {
        $this->logRequest($request, 'remove_user', 'groups');

        $authenticatedUser = $this->getAuthenticatedUser($request);
        $group = Group::find($id);

        if (! $group) {
            return $this->errorResponse('Group not found', 404);
        }

        // Load user's groups and permissions for partition admin check
        if (! $authenticatedUser->is_system_user) {
            $authenticatedUser->load('groups.permissions');
        }

        // Regular users cannot remove users from groups
        if (! $authenticatedUser->is_system_user && ! $authenticatedUser->isPartitionAdmin($this->getPartitionId($request))) {
            return $this->errorResponse('Unauthorized', 403);
        }

        // Partition admins can only manage groups in their partition
        if (! $authenticatedUser->is_system_user) {
            $partitionId = $this->getPartitionId($request);
            if ($group->partition_id !== $partitionId) {
                return $this->errorResponse('Unauthorized', 403);
            }
        }

        // Find user with partition isolation - only find users in the group's partition for non-system users
        $userQuery = IdentityUser::where('record_id', $userId);
        if (!$authenticatedUser->is_system_user) {
            $userQuery->where('partition_id', $group->partition_id);
        }
        $user = $userQuery->first();

        if (! $user) {
            return $this->errorResponse('User not found', 404);
        }

        if ($group->removeUser($userId)) {
            Log::info('User removed from group', [
                'group_id' => $group->record_id,
                'group_name' => $group->name,
                'target_user_id' => $user->record_id,
                'target_username' => $user->username,
                'removed_by' => $authenticatedUser->record_id,
                'removed_by_username' => $authenticatedUser->username,
                'partition_id' => $group->partition_id,
                'ip' => $request->ip(),
            ]);

            return $this->successResponse([
                'message' => 'User removed from group successfully',
            ], 200);
        }

        return $this->errorResponse('User not in group', 400);
    }

    public function getPermissions(Request $request, string $id): JsonResponse
    {
        $this->logRequest($request, 'get_permissions', 'groups');

        $user = $this->getAuthenticatedUser($request);
        $group = Group::find($id);

        if (! $group) {
            return $this->errorResponse('Group not found', 404);
        }

        // Load user's groups and permissions for partition admin check
        if (! $user->is_system_user) {
            $user->load('groups.permissions');
        }

        // Regular users cannot access group permissions
        if (! $user->is_system_user && ! $user->isPartitionAdmin($this->getPartitionId($request))) {
            return $this->errorResponse('Unauthorized', 403);
        }

        // Partition admins can only access groups in their partition
        if (! $user->is_system_user) {
            $partitionId = $this->getPartitionId($request);
            if ($group->partition_id !== $partitionId) {
                return $this->errorResponse('Unauthorized', 403);
            }
        }

        $permissions = $group->permissions;

        return $this->successResponse($permissions);
    }

    public function assignPermission(Request $request, string $id, string $permissionId): JsonResponse
    {
        $this->logRequest($request, 'assign_permission', 'groups');

        $authenticatedUser = $this->getAuthenticatedUser($request);
        $group = Group::find($id);
        $permission = Permission::find($permissionId);

        if (! $group) {
            return $this->errorResponse('Group not found', 404);
        }

        if (! $permission) {
            return $this->errorResponse('Permission not found', 404);
        }

        // Load user's groups and permissions for partition admin check
        if (! $authenticatedUser->is_system_user) {
            $authenticatedUser->load('groups.permissions');
        }

        // Regular users cannot assign permissions to groups
        if (! $authenticatedUser->is_system_user) {
            // Load user's groups and permissions for partition admin check
            $authenticatedUser->load('groups.permissions');
            if (! $authenticatedUser->isPartitionAdmin($this->getPartitionId($request))) {
                return $this->errorResponse('Unauthorized', 403);
            }
        }

        // Partition admins can only manage groups in their partition
        if (! $authenticatedUser->is_system_user) {
            $partitionId = $this->getPartitionId($request);
            if ($group->partition_id !== $partitionId) {
                return $this->errorResponse('Unauthorized', 403);
            }
        }

        if ($group->assignPermission($permissionId)) {
            Log::info('Permission assigned to group', [
                'group_id' => $group->record_id,
                'group_name' => $group->name,
                'permission_id' => $permission->record_id,
                'permission_name' => $permission->name,
                'assigned_by' => $authenticatedUser->record_id,
                'assigned_by_username' => $authenticatedUser->username,
                'partition_id' => $group->partition_id,
                'ip' => $request->ip(),
            ]);

            return $this->successResponse([
                'message' => 'Permission assigned to group successfully',
            ]);
        }

        return $this->errorResponse('Permission already assigned to group', 400);
    }

    public function revokePermission(Request $request, string $id, string $permissionId): JsonResponse
    {
        $this->logRequest($request, 'revoke_permission', 'groups');

        $authenticatedUser = $this->getAuthenticatedUser($request);
        $group = Group::find($id);
        $permission = Permission::find($permissionId);

        if (! $group) {
            return $this->errorResponse('Group not found', 404);
        }

        if (! $permission) {
            return $this->errorResponse('Permission not found', 404);
        }

        // Load user's groups and permissions for partition admin check
        if (! $authenticatedUser->is_system_user) {
            $authenticatedUser->load('groups.permissions');
        }

        // Regular users cannot revoke permissions from groups
        if (! $authenticatedUser->is_system_user && ! $authenticatedUser->isPartitionAdmin($this->getPartitionId($request))) {
            return $this->errorResponse('Unauthorized', 403);
        }

        // Partition admins can only manage groups in their partition
        if (! $authenticatedUser->is_system_user) {
            $partitionId = $this->getPartitionId($request);
            if ($group->partition_id !== $partitionId) {
                return $this->errorResponse('Unauthorized', 403);
            }
        }

        if ($group->revokePermission($permissionId)) {
            Log::info('Permission revoked from group', [
                'group_id' => $group->record_id,
                'group_name' => $group->name,
                'permission_id' => $permission->record_id,
                'permission_name' => $permission->name,
                'revoked_by' => $authenticatedUser->record_id,
                'revoked_by_username' => $authenticatedUser->username,
                'partition_id' => $group->partition_id,
                'ip' => $request->ip(),
            ]);

            return $this->successResponse([
                'message' => 'Permission revoked from group successfully',
            ], 200);
        }

        return $this->errorResponse('Permission not assigned to group', 400);
    }
}
