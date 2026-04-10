<?php

namespace NewSolari\Core\Identity\Controllers;

use NewSolari\Core\Http\BaseController;

use NewSolari\Core\Services\PartitionAppService;
use NewSolari\Core\Services\PluginRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PartitionAppsController extends BaseController
{
    /**
     * The partition app service instance.
     */
    protected PartitionAppService $service;

    /**
     * The plugin registry instance.
     */
    protected PluginRegistry $registry;

    /**
     * Create a new controller instance.
     */
    public function __construct(PartitionAppService $service, PluginRegistry $registry)
    {
        $this->service = $service;
        $this->registry = $registry;
    }

    /**
     * Get all apps for a partition with their status.
     *
     * GET /api/partitions/{partitionId}/apps
     */
    public function index(Request $request, string $partitionId): JsonResponse
    {
        try {
            // SECURITY: Get authenticated user from attributes only (set by middleware)
            $user = $this->getAuthenticatedUser($request);

            // Authorization: System admin OR partition admin
            if (! $user->is_system_user && ! $user->isPartitionAdmin($partitionId)) {
                return $this->errorResponse(
                    'Unauthorized - requires partition admin or system admin',
                    403
                );
            }

            // Get all apps from database (includes both enabled/disabled and visibility info)
            // Note: This endpoint is partition-specific by design (uses $partitionId from route)
            // System admins can access any partition's apps through the route parameter
            $appRecords = \NewSolari\Core\Identity\Models\PartitionApp::where('partition_id', $partitionId)->get();
            $appRecordsMap = $appRecords->keyBy('plugin_id');

            // Get all available plugins from the legacy PluginRegistry
            $allPlugins = $this->registry->getAll();

            // Include all discovered/registered modules
            $moduleRegistry = app(\NewSolari\Core\Module\ModuleRegistry::class);
            foreach ($moduleRegistry->getAllModulesWithManifest() as $mod) {
                $remotePluginId = $mod['id'] . '-' . ($mod['type'] ?? 'mini-app');
                if (!isset($allPlugins[$remotePluginId])) {
                    $allPlugins[$remotePluginId] = [
                        'name' => $mod['name'],
                        'type' => $mod['type'],
                        'version' => $mod['version'] ?? '1.0.0',
                        'description' => $mod['description'] ?? '',
                        'dependencies' => $mod['dependencies'] ?? [],
                    ];
                }
            }

            // Build response with status
            $apps = [];
            foreach ($allPlugins as $pluginId => $manifest) {
                $record = $appRecordsMap->get($pluginId);
                $isEnabled = $record ? $record->is_enabled : true; // Default enabled for backward compat
                $showInUi = $record ? $record->show_in_ui : true;  // Default visible

                $showInDashboard = $record ? $record->show_in_dashboard : true; // Default visible in dashboard
                $excludeMetaApp = $record ? $record->exclude_meta_app : false; // Default to showing all data
                $adminOnly = $record ? $record->admin_only : false; // Default visible to all users

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
                    'dependencies' => $manifest['dependencies'],
                ];
            }

            return $this->successResponse($apps, 200, ['message' => 'Apps retrieved successfully']);
        } catch (\Exception $e) {
            Log::error('Failed to list partition apps', [
                'partition_id' => $partitionId,
                'error' => $e->getMessage(),
            ]);

            return $this->errorResponse(
                'Failed to retrieve apps: '.$e->getMessage(),
                500
            );
        }
    }

    /**
     * Get details for a specific app.
     *
     * GET /api/partitions/{partitionId}/apps/{pluginId}
     */
    public function show(Request $request, string $partitionId, string $pluginId): JsonResponse
    {
        try {
            // SECURITY: Get authenticated user from attributes only (set by middleware)
            $user = $this->getAuthenticatedUser($request);

            // Authorization: System admin OR partition admin
            if (! $user->is_system_user && ! $user->isPartitionAdmin($partitionId)) {
                return $this->errorResponse(
                    'Unauthorized - requires partition admin or system admin',
                    403
                );
            }

            // Get plugin manifest
            $manifest = $this->registry->getManifest($pluginId);
            if (! $manifest) {
                return $this->errorResponse("Plugin not found: {$pluginId}", 404);
            }

            // Check if enabled and visible
            $isEnabled = $this->service->isEnabled($partitionId, $pluginId);
            $showInUi = $this->service->isVisibleInUi($partitionId, $pluginId);
            $showInDashboard = $this->service->isVisibleInDashboard($partitionId, $pluginId);
            $excludeMetaApp = $this->service->shouldExcludeMetaApp($partitionId, $pluginId);
            $adminOnly = $this->service->isAdminOnly($partitionId, $pluginId);

            $data = [
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
                'dependencies' => $manifest['dependencies'],
                'routes' => $manifest['routes'],
                'permissions' => $manifest['permissions'],
            ];

            return $this->successResponse($data, 200, ['message' => 'App details retrieved successfully']);
        } catch (\Exception $e) {
            Log::error('Failed to get app details', [
                'partition_id' => $partitionId,
                'plugin_id' => $pluginId,
                'error' => $e->getMessage(),
            ]);

            return $this->errorResponse(
                'Failed to retrieve app details: '.$e->getMessage(),
                500
            );
        }
    }

    /**
     * Enable an app for a partition.
     *
     * POST /api/partitions/{partitionId}/apps/{pluginId}/enable
     */
    public function enable(Request $request, string $partitionId, string $pluginId): JsonResponse
    {
        try {
            // SECURITY: Get authenticated user from attributes only (set by middleware)
            $user = $this->getAuthenticatedUser($request);

            // Authorization: System admin OR partition admin
            if (! $user->is_system_user && ! $user->isPartitionAdmin($partitionId)) {
                return $this->errorResponse(
                    'Unauthorized - requires partition admin or system admin',
                    403
                );
            }

            // Get enable_dependencies flag (default true)
            $enableDependencies = $request->input('enable_dependencies', true);

            // Enable the app
            $result = $this->service->enable($partitionId, $pluginId, $user, $enableDependencies);

            return $this->successResponse($result, 200, ['message' => 'App enabled successfully']);
        } catch (\Exception $e) {
            Log::error('Failed to enable app', [
                'partition_id' => $partitionId,
                'plugin_id' => $pluginId,
                'error' => $e->getMessage(),
            ]);

            return $this->errorResponse(
                $e->getMessage(),
                422
            );
        }
    }

    /**
     * Disable an app for a partition.
     *
     * POST /api/partitions/{partitionId}/apps/{pluginId}/disable
     */
    public function disable(Request $request, string $partitionId, string $pluginId): JsonResponse
    {
        try {
            // SECURITY: Get authenticated user from attributes only (set by middleware)
            $user = $this->getAuthenticatedUser($request);

            // Authorization: System admin OR partition admin
            if (! $user->is_system_user && ! $user->isPartitionAdmin($partitionId)) {
                return $this->errorResponse(
                    'Unauthorized - requires partition admin or system admin',
                    403
                );
            }

            // Get force flag (default false)
            $force = $request->input('force', false);

            // Disable the app
            $result = $this->service->disable($partitionId, $pluginId, $user, $force);

            return $this->successResponse($result, 200, ['message' => 'App disabled successfully']);
        } catch (\Exception $e) {
            Log::error('Failed to disable app', [
                'partition_id' => $partitionId,
                'plugin_id' => $pluginId,
                'error' => $e->getMessage(),
            ]);

            return $this->errorResponse(
                $e->getMessage(),
                422
            );
        }
    }

    /**
     * Bulk update app status.
     *
     * PUT /api/partitions/{partitionId}/apps
     */
    public function bulkUpdate(Request $request, string $partitionId): JsonResponse
    {
        try {
            // SECURITY: Get authenticated user from attributes only (set by middleware)
            $user = $this->getAuthenticatedUser($request);

            // Authorization: System admin OR partition admin
            if (! $user->is_system_user && ! $user->isPartitionAdmin($partitionId)) {
                return $this->errorResponse(
                    'Unauthorized - requires partition admin or system admin',
                    403
                );
            }

            // Validate request with max limit to prevent DoS
            $request->validate([
                'apps' => 'required|array|max:100',
                'apps.*' => 'required|boolean',
            ]);

            $apps = $request->input('apps');

            // Perform bulk update
            $result = $this->service->bulkUpdate($partitionId, $apps, $user);

            return $this->successResponse($result, 200, ['message' => 'Apps updated successfully']);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->errorResponse(
                'Validation failed: '.json_encode($e->errors()),
                422
            );
        } catch (\Exception $e) {
            Log::error('Failed to bulk update apps', [
                'partition_id' => $partitionId,
                'error' => $e->getMessage(),
            ]);

            return $this->errorResponse(
                'Failed to update apps: '.$e->getMessage(),
                500
            );
        }
    }

    /**
     * Toggle UI visibility for an app.
     *
     * POST /api/partitions/{partitionId}/apps/{pluginId}/visibility
     */
    public function toggleVisibility(Request $request, string $partitionId, string $pluginId): JsonResponse
    {
        try {
            // SECURITY: Get authenticated user from attributes only (set by middleware)
            $user = $this->getAuthenticatedUser($request);

            // Authorization: System admin OR partition admin
            if (! $user->is_system_user && ! $user->isPartitionAdmin($partitionId)) {
                return $this->errorResponse(
                    'Unauthorized - requires partition admin or system admin',
                    403
                );
            }

            // Get show_in_ui value from request
            $showInUi = $request->input('show_in_ui');
            if ($showInUi === null) {
                return $this->errorResponse('show_in_ui is required', 422);
            }

            // Set visibility
            $result = $this->service->setUiVisibility($partitionId, $pluginId, (bool) $showInUi, $user);

            return $this->successResponse($result, 200, ['message' => 'App visibility updated successfully']);
        } catch (\Exception $e) {
            Log::error('Failed to update app visibility', [
                'partition_id' => $partitionId,
                'plugin_id' => $pluginId,
                'error' => $e->getMessage(),
            ]);

            return $this->errorResponse(
                $e->getMessage(),
                422
            );
        }
    }

    /**
     * Toggle dashboard visibility for an app.
     *
     * POST /api/partitions/{partitionId}/apps/{pluginId}/dashboard-visibility
     */
    public function toggleDashboardVisibility(Request $request, string $partitionId, string $pluginId): JsonResponse
    {
        try {
            // SECURITY: Get authenticated user from attributes only (set by middleware)
            $user = $this->getAuthenticatedUser($request);

            // Authorization: System admin OR partition admin
            if (! $user->is_system_user && ! $user->isPartitionAdmin($partitionId)) {
                return $this->errorResponse(
                    'Unauthorized - requires partition admin or system admin',
                    403
                );
            }

            // Get show_in_dashboard value from request
            $showInDashboard = $request->input('show_in_dashboard');
            if ($showInDashboard === null) {
                return $this->errorResponse('show_in_dashboard is required', 422);
            }

            // Set dashboard visibility
            $result = $this->service->setDashboardVisibility($partitionId, $pluginId, (bool) $showInDashboard, $user);

            return $this->successResponse($result, 200, ['message' => 'App dashboard visibility updated successfully']);
        } catch (\Exception $e) {
            Log::error('Failed to update app dashboard visibility', [
                'partition_id' => $partitionId,
                'plugin_id' => $pluginId,
                'error' => $e->getMessage(),
            ]);

            return $this->errorResponse(
                $e->getMessage(),
                422
            );
        }
    }

    /**
     * Toggle exclude_meta_app setting for an app.
     *
     * POST /api/partitions/{partitionId}/apps/{pluginId}/exclude-meta-app
     */
    public function toggleExcludeMetaApp(Request $request, string $partitionId, string $pluginId): JsonResponse
    {
        try {
            // SECURITY: Get authenticated user from attributes only (set by middleware)
            $user = $this->getAuthenticatedUser($request);

            // Authorization: System admin OR partition admin
            if (! $user->is_system_user && ! $user->isPartitionAdmin($partitionId)) {
                return $this->errorResponse(
                    'Unauthorized - requires partition admin or system admin',
                    403
                );
            }

            // Get exclude_meta_app value from request
            $excludeMetaApp = $request->input('exclude_meta_app');
            if ($excludeMetaApp === null) {
                return $this->errorResponse('exclude_meta_app is required', 422);
            }

            // Set exclude_meta_app setting
            $result = $this->service->setExcludeMetaApp($partitionId, $pluginId, (bool) $excludeMetaApp, $user);

            return $this->successResponse($result, 200, ['message' => 'App exclude_meta_app setting updated successfully']);
        } catch (\Exception $e) {
            Log::error('Failed to update app exclude_meta_app setting', [
                'partition_id' => $partitionId,
                'plugin_id' => $pluginId,
                'error' => $e->getMessage(),
            ]);

            return $this->errorResponse(
                $e->getMessage(),
                422
            );
        }
    }

    /**
     * Toggle admin_only setting for an app.
     *
     * POST /api/partitions/{partitionId}/apps/{pluginId}/admin-only
     */
    public function toggleAdminOnly(Request $request, string $partitionId, string $pluginId): JsonResponse
    {
        try {
            // SECURITY: Get authenticated user from attributes only (set by middleware)
            $user = $this->getAuthenticatedUser($request);

            // Authorization: System admin OR partition admin
            if (! $user->is_system_user && ! $user->isPartitionAdmin($partitionId)) {
                return $this->errorResponse(
                    'Unauthorized - requires partition admin or system admin',
                    403
                );
            }

            // Get admin_only value from request
            $adminOnly = $request->input('admin_only');
            if ($adminOnly === null) {
                return $this->errorResponse('admin_only is required', 422);
            }

            // Set admin_only setting
            $result = $this->service->setAdminOnly($partitionId, $pluginId, (bool) $adminOnly, $user);

            return $this->successResponse($result, 200, ['message' => 'App admin_only setting updated successfully']);
        } catch (\Exception $e) {
            Log::error('Failed to update app admin_only setting', [
                'partition_id' => $partitionId,
                'plugin_id' => $pluginId,
                'error' => $e->getMessage(),
            ]);

            return $this->errorResponse(
                $e->getMessage(),
                422
            );
        }
    }
}
