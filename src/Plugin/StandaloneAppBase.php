<?php

namespace NewSolari\Core\Plugin;

use NewSolari\Core\Identity\Models\IdentityUser;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

abstract class StandaloneAppBase extends PluginBase
{
    /**
     * StandaloneAppBase constructor
     */
    public function __construct()
    {
        parent::__construct();
        $this->pluginType = 'standalone';
    }

    /**
     * Get standalone app configuration
     */
    abstract public function getAppConfig(): array;

    /**
     * Handle app request
     */
    abstract public function handleRequest(IdentityUser $user, array $requestData): array;

    /**
     * Get frontend configuration
     */
    public function getFrontendConfig(IdentityUser $user): array
    {
        $config = $this->getAppConfig();

        // Apply user-specific configuration
        $userConfig = $this->getUserConfig($user);
        $config = array_merge($config, $userConfig);

        // Apply permission-based configuration
        $config = $this->applyPermissionConfig($config, $user);

        return $config;
    }

    /**
     * Get user-specific configuration
     */
    protected function getUserConfig(IdentityUser $user): array
    {
        $cacheKey = 'standalone_app_'.$this->getId().'_user_'.$user->record_id.'_config';

        // Try to get from cache
        if (Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        // Get default user config
        $userConfig = $this->getDefaultUserConfig($user);

        // Cache for 1 hour
        Cache::put($cacheKey, $userConfig, 3600);

        return $userConfig;
    }

    /**
     * Get default user configuration
     */
    protected function getDefaultUserConfig(IdentityUser $user): array
    {
        return [
            'user_id' => $user->record_id,
            'partition_id' => $user->partition_id,
            'username' => $user->username,
            'is_admin' => $user->is_system_user || $user->isPartitionAdmin($user->partition_id),
        ];
    }

    /**
     * Apply permission-based configuration
     */
    protected function applyPermissionConfig(array $config, IdentityUser $user): array
    {
        $permissions = $this->getPermissions();

        foreach ($permissions as $permission) {
            $hasPermission = $user->hasPermission($permission);
            $config['permissions'][$permission] = $hasPermission;

            // Apply permission-based settings
            if (isset($config['permission_settings'][$permission])) {
                $config = array_merge($config, $config['permission_settings'][$permission]);
            }
        }

        return $config;
    }

    /**
     * Handle API request with authentication and permission checking
     *
     * @throws \Exception
     */
    public function handleApiRequest(IdentityUser $user, string $action, array $data): array
    {
        try {
            // Check if action requires specific permission
            $requiredPermission = $this->getRequiredPermissionForAction($action);

            if ($requiredPermission && ! $this->checkUserPermission($user, $requiredPermission)) {
                throw new \Exception('Permission denied for action: '.$action);
            }

            // Handle the request
            $result = $this->handleRequest($user, array_merge($data, [
                'action' => $action,
                'user_id' => $user->record_id,
                'partition_id' => $user->partition_id,
            ]));

            Log::info('Standalone app request handled', [
                'app' => $this->getId(),
                'action' => $action,
                'user_id' => $user->record_id,
                'success' => true,
            ]);

            return [
                'success' => true,
                'data' => $result,
            ];

        } catch (\Exception $e) {
            Log::error('Standalone app request failed', [
                'app' => $this->getId(),
                'action' => $action,
                'user_id' => $user->record_id,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Get required permission for action
     */
    protected function getRequiredPermissionForAction(string $action): ?string
    {
        $permissionMap = $this->getActionPermissionMap();

        return $permissionMap[$action] ?? null;
    }

    /**
     * Get action to permission mapping
     */
    protected function getActionPermissionMap(): array
    {
        $permissions = $this->getPermissions();
        $permissionMap = [];

        foreach ($permissions as $permission) {
            // Convert permission to action (e.g., "app.manage" -> "manage")
            $action = str_after($permission, '.');
            $permissionMap[$action] = $permission;
        }

        return $permissionMap;
    }

    /**
     * Get cached data for standalone app
     *
     * @return mixed
     */
    protected function getCachedData(string $cacheKey, \Closure $dataProvider, int $ttl = 3600)
    {
        $fullCacheKey = 'standalone_'.$this->getId().'_'.$cacheKey;

        // Try to get from cache
        if (Cache::has($fullCacheKey)) {
            return Cache::get($fullCacheKey);
        }

        // Get data from provider
        $data = $dataProvider();

        // Cache the data
        Cache::put($fullCacheKey, $data, $ttl);

        return $data;
    }

    /**
     * Clear standalone app cache
     */
    protected function clearCache(string $cacheKey): void
    {
        $fullCacheKey = 'standalone_'.$this->getId().'_'.$cacheKey;
        Cache::forget($fullCacheKey);
    }

    /**
     * Clear all cache for this standalone app
     */
    public function clearAllCache(): void
    {
        $cachePattern = 'standalone_'.$this->getId().'_*';

        // This would require a more sophisticated cache clearing mechanism
        // For now, we'll just clear the main cache keys we know about
        $this->clearCache('config');
        $this->clearCache('user_config');
        $this->clearCache('data');
    }

    /**
     * Get external service configuration
     */
    protected function getExternalServiceConfig(): array
    {
        // This can be overridden by standalone apps that integrate with external services
        return [];
    }

    /**
     * Handle external service request
     */
    protected function handleExternalServiceRequest(string $service, array $requestData): array
    {
        // This can be implemented by standalone apps that need external service integration
        return [
            'success' => false,
            'error' => 'External service not implemented',
        ];
    }

    /**
     * Get app health status
     */
    public function getHealthStatus(): array
    {
        return [
            'status' => 'healthy',
            'app_id' => $this->getId(),
            'app_name' => $this->getName(),
            'version' => $this->getVersion(),
            'timestamp' => now()->toDateTimeString(),
        ];
    }

    /**
     * Get app metrics
     */
    public function getMetrics(): array
    {
        return [
            'app_id' => $this->getId(),
            'requests_handled' => 0, // Would need to be tracked
            'cache_hits' => 0,      // Would need to be tracked
            'errors' => 0,           // Would need to be tracked
        ];
    }
}
