<?php

namespace NewSolari\Core\Identity\Controllers;

use NewSolari\Core\Http\BaseController;

use NewSolari\Core\Identity\Models\Group;
use NewSolari\Core\Identity\Models\IdentityPartition;
use NewSolari\Core\Identity\Models\IdentityUser;
use NewSolari\Core\Identity\Models\Permission;
use NewSolari\Core\Identity\Models\RegistrySetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PartitionController extends BaseController
{
    /**
     * Get list of active partitions for login dropdown.
     * This is a PUBLIC endpoint - no authentication required.
     * Returns only partition ID and name (no sensitive data).
     *
     * GET /api/partitions/public
     */
    public function publicList(): JsonResponse
    {
        $partitions = IdentityPartition::where('is_active', true)
            ->select('record_id', 'name')
            ->orderBy('name')
            ->get()
            ->map(fn ($p) => [
                'id' => $p->record_id,
                'name' => $p->name,
            ]);

        return $this->successResponse($partitions);
    }

    /**
     * Check registration status for a partition.
     * This is a PUBLIC endpoint - no authentication required.
     * Returns whether registration is enabled for the specified partition.
     *
     * GET /api/partitions/{id}/registration-status
     *
     * @unauthenticated
     *
     * @param  string  $id  Partition ID
     */
    public function registrationStatus(string $id): JsonResponse
    {
        // Check if partition exists and is active
        $partition = IdentityPartition::where('record_id', $id)
            ->where('is_active', true)
            ->first();

        if (! $partition) {
            return $this->errorResponse('Partition not found', 404);
        }

        // Check system-level registration setting (master switch)
        $systemSetting = RegistrySetting::where('scope', 'system')
            ->where('key', 'system.registration.enabled')
            ->first();

        // If system setting exists and is 'false', registration is globally disabled
        $systemEnabled = true;
        if ($systemSetting) {
            try {
                $systemEnabled = ! ($systemSetting->value === 'false' || $systemSetting->value === false);
            } catch (\Exception $e) {
                // If we can't read the setting, assume disabled for safety
                $systemEnabled = false;
            }
        }

        if (! $systemEnabled) {
            return $this->successResponse([
                'enabled' => false,
                'require_email' => false,
                'message' => 'Registration is currently disabled system-wide',
            ]);
        }

        // Check partition-level registration setting
        $partitionSetting = RegistrySetting::where('scope', 'partition')
            ->where('key', 'partition.registration.enabled')
            ->where('partition_id', $id)
            ->first();

        $partitionEnabled = false;
        if ($partitionSetting) {
            try {
                $partitionEnabled = ($partitionSetting->value === 'true' || $partitionSetting->value === true);
            } catch (\Exception $e) {
                $partitionEnabled = false;
            }
        }

        // Check if email is required (system-level setting, admin-only)
        $requireEmailSetting = RegistrySetting::where('scope', 'system')
            ->where('key', 'system.registration.require_email')
            ->first();

        $requireEmail = false;
        if ($requireEmailSetting) {
            try {
                $requireEmail = ($requireEmailSetting->value === 'true' || $requireEmailSetting->value === true);
            } catch (\Exception $e) {
                $requireEmail = false;
            }
        }

        // Check if name fields should be hidden (partition-level setting)
        $hideNameFieldsSetting = RegistrySetting::where('scope', 'partition')
            ->where('key', 'partition.registration.hide_name_fields')
            ->where('partition_id', $id)
            ->first();

        $hideNameFields = false;
        if ($hideNameFieldsSetting) {
            try {
                $hideNameFields = ($hideNameFieldsSetting->value === 'true' || $hideNameFieldsSetting->value === true);
            } catch (\Exception $e) {
                $hideNameFields = false;
            }
        }

        return $this->successResponse([
            'enabled' => $partitionEnabled,
            'require_email' => $requireEmail,
            'hide_name_fields' => $hideNameFields,
            'partition_name' => $partition->name,
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $this->logRequest($request, 'index', 'partitions');

        $user = $this->getAuthenticatedUser($request);

        // Load user's groups and permissions for partition admin check
        if (! $user->is_system_user) {
            $user->load('groups.permissions');
        }

        // Regular users cannot view partitions
        if (! $user->is_system_user && ! $user->isPartitionAdmin($this->getPartitionId($request))) {
            return $this->errorResponse('Unauthorized', 403);
        }

        // Build query - filter by partition context when set
        $partitionId = $this->getPartitionId($request);
        $query = IdentityPartition::with('users');

        // When in a partition context, only show that partition
        if ($partitionId) {
            $query->where('record_id', $partitionId);
        }

        $partitions = $query->get();

        return $this->successResponse($partitions);
    }

    public function store(Request $request): JsonResponse
    {
        $this->logRequest($request, 'store', 'partitions');

        $user = $this->getAuthenticatedUser($request);

        // Only system admins can create partitions
        if (! $user->is_system_user) {
            return $this->errorResponse('Unauthorized', 403);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:identity_partitions',
            'description' => 'nullable|string',
            'is_active' => 'boolean',
        ]);

        $partition = IdentityPartition::createWithValidation($validated);
        $partitionId = $partition->record_id;

        // Initialize default apps for the new partition (all apps DISABLED by default)
        $appService = app(\NewSolari\Core\Services\PartitionAppService::class);
        $appService->initializeDefaults($partitionId, false); // false = disabled by default

        // Create default "Users" group for new partition
        $usersGroup = Group::createWithValidation([
            'name' => 'Users',
            'description' => 'Default group for registered users',
            'partition_id' => $partitionId,
            'is_active' => true,
        ]);

        // Create "Partition Admins" group with admin permissions
        $partitionAdminsGroup = Group::createWithValidation([
            'name' => 'Partition Admins',
            'description' => 'Administrators for this partition',
            'partition_id' => $partitionId,
            'is_active' => true,
        ]);

        // Assign system-wide permissions to groups
        $this->assignDefaultGroupPermissions($usersGroup, $partitionAdminsGroup);

        // Set the Users group as the default registration group (stored as JSON array)
        RegistrySetting::create([
            'scope' => 'partition',
            'key' => 'partition.registration.default_group',
            'value' => json_encode([$usersGroup->record_id]),
            'scope_id' => $partitionId,
            'partition_id' => $partitionId,
        ]);

        // Set registration as disabled by default for new partitions
        RegistrySetting::create([
            'scope' => 'partition',
            'key' => 'partition.registration.enabled',
            'value' => 'false',
            'scope_id' => $partitionId,
            'partition_id' => $partitionId,
        ]);

        return $this->successResponse($partition, 201);
    }

    /**
     * Assign default system-wide permissions to new partition groups.
     * Permissions are system-wide (no partition_id), groups are partition-scoped.
     */
    protected function assignDefaultGroupPermissions(Group $usersGroup, Group $adminsGroup): void
    {
        // Basic user permissions - what regular users can do
        $basicUserPermissions = [
            // User self-access
            'users.read',

            // Plugin entity permissions (CRUD for all apps)
            'notes.read', 'notes.create', 'notes.update', 'notes.delete', 'notes.export',
            'files.read', 'files.create', 'files.update', 'files.delete', 'files.export',
            'folders.read', 'folders.create', 'folders.update', 'folders.delete', 'folders.export',
            'events.read', 'events.create', 'events.update', 'events.delete', 'events.export',
            'people.read', 'people.create', 'people.update', 'people.delete', 'people.export',
            'entities.read', 'entities.create', 'entities.update', 'entities.delete', 'entities.export',
            'tasks.read', 'tasks.create', 'tasks.update', 'tasks.delete', 'tasks.export',
            'tags.read', 'tags.create', 'tags.update', 'tags.delete', 'tags.export',
            'places.read', 'places.create', 'places.update', 'places.delete', 'places.export',
            'hypotheses.read', 'hypotheses.create', 'hypotheses.update', 'hypotheses.delete', 'hypotheses.export',
            'motives.read', 'motives.create', 'motives.update', 'motives.delete', 'motives.export',
            'blocknotes.read', 'blocknotes.create', 'blocknotes.update', 'blocknotes.delete', 'blocknotes.export',
            'private_messages.read', 'private_messages.create', 'private_messages.update', 'private_messages.delete',
            'broadcast_messages.read',
            'inventory_objects.read', 'inventory_objects.create', 'inventory_objects.update', 'inventory_objects.delete', 'inventory_objects.export',
            'reference_materials.read', 'reference_materials.create', 'reference_materials.update', 'reference_materials.delete', 'reference_materials.export',
            'login_banners.read',

            // Partition apps (view only for basic users)
            'partition_apps.read',

            // Settings (user can read their own settings)
            'settings.read',
        ];

        // Assign basic permissions to Users group
        foreach ($basicUserPermissions as $permissionName) {
            $permission = Permission::where('name', $permissionName)->first();
            if ($permission) {
                $usersGroup->assignPermission($permission->record_id);
            }
        }

        // Admin permissions - partition admins get all permissions including admin-level ones
        $adminPermissions = [
            // All basic user permissions
            ...$basicUserPermissions,

            // User management
            'users.create', 'users.update', 'users.delete', 'users.manage', 'users.password',

            // Group management
            'groups.read', 'groups.create', 'groups.update', 'groups.delete', 'groups.manage',

            // Permission viewing (admins can view and assign, but not create/edit permissions)
            'permissions.read',

            // Partition management (partitions.manage removed - partitions.admin provides full access)
            'partitions.read', 'partitions.update', 'partitions.admin',

            // Partition apps management
            'partition_apps.update', 'partition_apps.manage',

            // Settings management
            'settings.create', 'settings.update', 'settings.delete', 'settings.manage',

            // System info (read-only for partition admins)
            'system.info',

            // Broadcast messages (admins can create)
            'broadcast_messages.create', 'broadcast_messages.update', 'broadcast_messages.delete', 'broadcast_messages.manage',

            // Login banners management
            'login_banners.create', 'login_banners.update', 'login_banners.delete', 'login_banners.manage',
        ];

        // Assign admin permissions to Partition Admins group
        foreach ($adminPermissions as $permissionName) {
            $permission = Permission::where('name', $permissionName)->first();
            if ($permission) {
                $adminsGroup->assignPermission($permission->record_id);
            }
        }

        Log::info('Assigned default permissions to partition groups', [
            'users_group_id' => $usersGroup->record_id,
            'admins_group_id' => $adminsGroup->record_id,
            'basic_permissions_count' => count($basicUserPermissions),
            'admin_permissions_count' => count($adminPermissions),
        ]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $this->logRequest($request, 'show', 'partitions');

        $user = $this->getAuthenticatedUser($request);
        $partition = IdentityPartition::with(['users', 'entities'])->find($id);

        if (! $partition) {
            return $this->errorResponse('Partition not found', 404);
        }

        // Load user's groups and permissions for partition admin check
        if (! $user->is_system_user) {
            $user->load('groups.permissions');
        }

        // Regular users cannot view partitions
        if (! $user->is_system_user && ! $user->isPartitionAdmin($this->getPartitionId($request))) {
            return $this->errorResponse('Unauthorized', 403);
        }

        // Partition admins can only view their own partition
        if (! $user->is_system_user) {
            $requestPartitionId = $this->getPartitionId($request);
            if ($partition->record_id !== $requestPartitionId) {
                return $this->errorResponse('Unauthorized', 403);
            }
        }

        return $this->successResponse($partition);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $this->logRequest($request, 'update', 'partitions');

        $user = $this->getAuthenticatedUser($request);
        $partition = IdentityPartition::find($id);

        if (! $partition) {
            return $this->errorResponse('Partition not found', 404);
        }

        // Load user's groups and permissions for partition admin check
        if (! $user->is_system_user) {
            $user->load('groups.permissions');
        }

        // Regular users cannot update partitions
        if (! $user->is_system_user && ! $user->isPartitionAdmin($this->getPartitionId($request))) {
            return $this->errorResponse('Unauthorized', 403);
        }

        // Partition admins can only update their own partition
        if (! $user->is_system_user) {
            $requestPartitionId = $this->getPartitionId($request);
            if ($partition->record_id !== $requestPartitionId) {
                return $this->errorResponse('Unauthorized', 403);
            }
        }

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255|unique:identity_partitions,name,'.$id.',record_id',
            'description' => 'sometimes|nullable|string',
            'is_active' => 'sometimes|boolean',
        ]);

        $partition->updateWithValidation($validated);

        return $this->successResponse($partition);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $this->logRequest($request, 'destroy', 'partitions');

        $user = $this->getAuthenticatedUser($request);
        $partition = IdentityPartition::find($id);

        if (! $partition) {
            return $this->errorResponse('Partition not found', 404);
        }

        // Only system admins can delete partitions
        if (! $user->is_system_user) {
            return $this->errorResponse('Unauthorized', 403);
        }

        // Store partition info for logging before deletion
        $deletedPartitionName = $partition->name;
        $deletedPartitionId = $partition->record_id;

        // Soft delete handles all FK constraints
        // This will also soft delete all users in the partition
        try {
            $partition->deleteWithValidation($user->record_id);

            Log::info('Partition deleted', [
                'partition_id' => $deletedPartitionId,
                'partition_name' => $deletedPartitionName,
                'deleted_by' => $user->record_id,
                'deleted_by_username' => $user->username,
                'ip' => $request->ip(),
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to delete partition', [
                'partition_id' => $deletedPartitionId,
                'partition_name' => $deletedPartitionName,
                'error' => $e->getMessage(),
                'deleted_by' => $user->record_id,
            ]);

            return $this->errorResponse('Failed to delete partition', 500);
        }

        return $this->successResponse(['message' => 'Partition deleted successfully'], 200);
    }

    public function getUsers(Request $request, string $id): JsonResponse
    {
        $this->logRequest($request, 'get_users', 'partitions');

        $user = $this->getAuthenticatedUser($request);
        $partition = IdentityPartition::find($id);

        if (! $partition) {
            return $this->errorResponse('Partition not found', 404);
        }

        // Load user's groups and permissions for partition admin check
        if (! $user->is_system_user) {
            $user->load('groups.permissions');
        }

        // Regular users cannot view partition users
        if (! $user->is_system_user && ! $user->isPartitionAdmin($this->getPartitionId($request))) {
            return $this->errorResponse('Unauthorized', 403);
        }

        // Partition admins can only view users in their own partition
        if (! $user->is_system_user) {
            $requestPartitionId = $this->getPartitionId($request);
            if ($partition->record_id !== $requestPartitionId) {
                return $this->errorResponse('Unauthorized', 403);
            }
        }

        // Query users via the pivot table (supports multi-partition membership)
        // Bypass partition scope since users from other home partitions may be attached
        $users = $partition->users()
            ->withoutGlobalScope('partition')
            ->with(['groups', 'permissions'])
            ->get();

        return $this->successResponse($users);
    }

    public function addUser(Request $request, string $id, string $userId): JsonResponse
    {
        $this->logRequest($request, 'add_user', 'partitions');

        $authenticatedUser = $this->getAuthenticatedUser($request);
        $partition = IdentityPartition::find($id);
        // Bypass partition scope - adding users to partition is a cross-partition operation
        $user = IdentityUser::withoutGlobalScope('partition')->find($userId);

        if (! $partition) {
            return $this->errorResponse('Partition not found', 404);
        }

        if (! $user) {
            return $this->errorResponse('User not found', 404);
        }

        // Load user's groups and permissions for partition admin check
        if (! $authenticatedUser->is_system_user) {
            $authenticatedUser->load('groups.permissions');
        }

        // Regular users cannot add users to partitions
        if (! $authenticatedUser->is_system_user && ! $authenticatedUser->isPartitionAdmin($this->getPartitionId($request))) {
            return $this->errorResponse('Unauthorized', 403);
        }

        // Partition admins can only add users to their own partition
        if (! $authenticatedUser->is_system_user) {
            $requestPartitionId = $this->getPartitionId($request);
            if ($partition->record_id !== $requestPartitionId) {
                return $this->errorResponse('Unauthorized', 403);
            }
        }

        // Add user to partition via pivot table (supports multi-partition membership)
        try {
            DB::transaction(function () use ($user, $partition) {
                if ($partition->users()->where('identity_users.record_id', $user->record_id)->exists()) {
                    throw new \RuntimeException('User already in partition');
                }

                $partition->users()->attach($user->record_id);
            });
        } catch (\RuntimeException $e) {
            // Domain-specific error - safe to return specific message
            return $this->errorResponse($e->getMessage(), 400);
        }

        return $this->successResponse([
            'message' => 'User added to partition successfully',
        ]);
    }

    public function removeUser(Request $request, string $id, string $userId): JsonResponse
    {
        $this->logRequest($request, 'remove_user', 'partitions');

        $authenticatedUser = $this->getAuthenticatedUser($request);
        $partition = IdentityPartition::find($id);
        // Bypass partition scope - removing users from partition is a cross-partition operation
        $user = IdentityUser::withoutGlobalScope('partition')->find($userId);

        if (! $partition) {
            return $this->errorResponse('Partition not found', 404);
        }

        if (! $user) {
            return $this->errorResponse('User not found', 404);
        }

        // Load user's groups and permissions for partition admin check
        if (! $authenticatedUser->is_system_user) {
            $authenticatedUser->load('groups.permissions');
        }

        // Regular users cannot remove users from partitions
        if (! $authenticatedUser->is_system_user && ! $authenticatedUser->isPartitionAdmin($this->getPartitionId($request))) {
            return $this->errorResponse('Unauthorized', 403);
        }

        // Partition admins can only remove users from their own partition
        if (! $authenticatedUser->is_system_user) {
            $requestPartitionId = $this->getPartitionId($request);
            if ($partition->record_id !== $requestPartitionId) {
                return $this->errorResponse('Unauthorized', 403);
            }
        }

        // Prevent users from removing themselves
        if ($authenticatedUser->record_id === $userId) {
            return $this->errorResponse('Cannot remove yourself from partition', 400);
        }

        // Verify user belongs to this partition via pivot table
        if (! $partition->users()->where('identity_users.record_id', $user->record_id)->exists()) {
            return $this->errorResponse('User not in partition', 400);
        }

        // Prevent removing user's last partition
        if ($user->partitions()->count() <= 1) {
            return $this->errorResponse('Cannot remove last partition from user', 400);
        }

        // Remove user from partition via pivot table
        try {
            DB::transaction(function () use ($user, $partition) {
                $partition->users()->detach($user->record_id);
            });
        } catch (\RuntimeException $e) {
            // Domain-specific error - safe to return specific message
            return $this->errorResponse($e->getMessage(), 400);
        }

        return $this->successResponse([
            'message' => 'User removed from partition successfully',
        ]);
    }

    /**
     * Get all apps available for a partition with their enabled status.
     * This is the discovery endpoint for UI to poll.
     *
     * GET /api/partitions/{partitionId}/apps
     */
    public function apps(Request $request, string $partitionId): JsonResponse
    {
        $this->logRequest($request, 'apps', 'partitions');

        try {
            $user = $this->getAuthenticatedUser($request);
            $partition = IdentityPartition::find($partitionId);

            if (! $partition) {
                return $this->errorResponse('Partition not found', 404);
            }

            // Authorization: System admins OR partition members
            // Users can access if:
            // 1. They are a system user (admin access to all partitions)
            // 2. Their home partition (partition_id) matches this partition
            // 3. They are explicitly added to the partition's user list
            $isHomePartition = $user->partition_id === $partitionId;
            $isExplicitMember = $partition->users()->where('user_id', $user->record_id)->exists();
            $canAccess = $user->is_system_user || $isHomePartition || $isExplicitMember;

            if (! $canAccess) {
                return $this->errorResponse('Unauthorized', 403);
            }

            // Get services
            $registry = app(\NewSolari\Core\Services\PluginRegistry::class);

            // Get all apps from database (includes both enabled/disabled and visibility info)
            $appRecords = \NewSolari\Core\Identity\Models\PartitionApp::where('partition_id', $partitionId)->get();
            $appRecordsMap = $appRecords->keyBy('plugin_id');

            // Get all available plugins from the legacy PluginRegistry
            $allPlugins = $registry->getAll();

            // Include all discovered/registered modules (in-process + discovered)
            $moduleRegistry = app(\NewSolari\Core\Module\ModuleRegistry::class);
            foreach ($moduleRegistry->getAllModulesWithManifest() as $mod) {
                $remotePluginId = $mod['id'] . '-' . ($mod['type'] ?? 'mini-app');
                if (!isset($allPlugins[$remotePluginId])) {
                    $allPlugins[$remotePluginId] = [
                        'name' => $mod['name'],
                        'type' => $mod['type'],
                        'version' => $mod['version'] ?? '1.0.0',
                        'description' => $mod['description'] ?? '',
                        'routes' => [],
                        'permissions' => [],
                        'dependencies' => $mod['dependencies'] ?? [],
                    ];
                }
            }

            // Build response with enabled status and metadata
            // All users receive the full list so the frontend can properly filter navigation
            $apps = [];
            foreach ($allPlugins as $pluginId => $manifest) {
                $record = $appRecordsMap->get($pluginId);
                $isEnabled = $record ? $record->is_enabled : true; // Default enabled for backward compat
                $showInUi = $record ? $record->show_in_ui : true;  // Default visible
                $showInDashboard = $record ? $record->show_in_dashboard : true; // Default visible in dashboard
                $excludeMetaApp = $record ? (bool) $record->exclude_meta_app : false; // Default to showing all data
                $adminOnly = $record ? (bool) $record->admin_only : false; // Default visible to all users

                $apps[] = [
                    'plugin_id' => $pluginId,
                    'name' => $manifest['name'],
                    'type' => $manifest['type'],
                    'version' => $manifest['version'],
                    'description' => $manifest['description'] ?? '',
                    'is_enabled' => $isEnabled,
                    'show_in_ui' => $showInUi,
                    'show_in_dashboard' => $showInDashboard,
                    'exclude_meta_app' => $excludeMetaApp,
                    'admin_only' => $adminOnly,
                    'routes' => $manifest['routes'],
                    'permissions' => $manifest['permissions'],
                    'dependencies' => $manifest['dependencies'],
                ];
            }

            return $this->successResponse([
                'apps' => $apps,
                'partition' => [
                    'id' => $partition->record_id,
                    'name' => $partition->name,
                ],
            ]);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Failed to get partition apps', [
                'partition_id' => $partitionId,
                'error' => $e->getMessage(),
            ]);

            return $this->errorResponse(
                'Failed to retrieve apps',
                500
            );
        }
    }

    // ========================================
    // ADMIN-ONLY ENDPOINTS
    // These endpoints allow system admins to manage ALL partitions
    // ========================================

    /**
     * Get all partitions (admin only).
     * Unlike the regular index, this returns ALL partitions regardless of context.
     *
     * GET /api/admin/partitions
     */
    public function adminIndex(Request $request): JsonResponse
    {
        $this->logRequest($request, 'admin_index', 'partitions');

        $user = $this->getAuthenticatedUser($request);

        // Only system admins can access this endpoint
        if (! $user->is_system_user) {
            return $this->errorResponse('Unauthorized', 403);
        }

        $partitions = IdentityPartition::with('users')->get();

        return $this->successResponse($partitions);
    }

    /**
     * Get a specific partition (admin only).
     * Unlike the regular show, this works for ANY partition.
     *
     * GET /api/admin/partitions/{id}
     */
    public function adminShow(Request $request, string $id): JsonResponse
    {
        $this->logRequest($request, 'admin_show', 'partitions');

        $user = $this->getAuthenticatedUser($request);

        // Only system admins can access this endpoint
        if (! $user->is_system_user) {
            return $this->errorResponse('Unauthorized', 403);
        }

        $partition = IdentityPartition::with(['users', 'entities'])->find($id);

        if (! $partition) {
            return $this->errorResponse('Partition not found', 404);
        }

        return $this->successResponse($partition);
    }

    /**
     * Update any partition (admin only).
     * Unlike the regular update, this works for ANY partition.
     *
     * PUT /api/admin/partitions/{id}
     */
    public function adminUpdate(Request $request, string $id): JsonResponse
    {
        $this->logRequest($request, 'admin_update', 'partitions');

        $user = $this->getAuthenticatedUser($request);

        // Only system admins can access this endpoint
        if (! $user->is_system_user) {
            return $this->errorResponse('Unauthorized', 403);
        }

        $partition = IdentityPartition::find($id);

        if (! $partition) {
            return $this->errorResponse('Partition not found', 404);
        }

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255|unique:identity_partitions,name,'.$id.',record_id',
            'description' => 'sometimes|nullable|string',
            'is_active' => 'sometimes|boolean',
        ]);

        $partition->updateWithValidation($validated);

        return $this->successResponse($partition);
    }

    /**
     * Delete any partition (admin only).
     * Unlike the regular destroy, this works for ANY partition.
     *
     * DELETE /api/admin/partitions/{id}
     */
    public function adminDestroy(Request $request, string $id): JsonResponse
    {
        $this->logRequest($request, 'admin_destroy', 'partitions');

        $user = $this->getAuthenticatedUser($request);

        // Only system admins can access this endpoint
        if (! $user->is_system_user) {
            return $this->errorResponse('Unauthorized', 403);
        }

        $partition = IdentityPartition::find($id);

        if (! $partition) {
            return $this->errorResponse('Partition not found', 404);
        }

        // Prevent deleting the admin's own partition
        if ($partition->record_id === $user->partition_id) {
            return $this->errorResponse('Cannot delete your own partition', 400);
        }

        // Use transaction with locking to prevent race conditions
        try {
            DB::transaction(function () use ($partition) {
                // Clean up any orphaned pivot records where the user no longer exists
                // This handles cases where foreign key cascade didn't work properly
                DB::table('identity_user_partitions')
                    ->where('partition_id', $partition->record_id)
                    ->whereNotExists(function ($query) {
                        $query->select(DB::raw(1))
                            ->from('identity_users')
                            ->whereColumn('identity_users.record_id', 'identity_user_partitions.user_id');
                    })
                    ->delete();

                // Lock the partition and check user count atomically
                $userCount = DB::table('identity_user_partitions')
                    ->where('partition_id', $partition->record_id)
                    ->lockForUpdate()
                    ->count();

                if ($userCount > 0) {
                    throw new \RuntimeException('Cannot delete partition with users');
                }

                $partition->deleteWithValidation();
            });
        } catch (\RuntimeException $e) {
            // Domain-specific error - safe to return specific message
            return $this->errorResponse($e->getMessage(), 400);
        }

        return $this->successResponse(['message' => 'Partition deleted successfully'], 200);
    }
}
