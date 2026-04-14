<?php

namespace NewSolari\Core\Plugin;

use NewSolari\Core\Contracts\IdentityPartitionContract;
use NewSolari\Core\Contracts\IdentityUserContract;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

abstract class PluginBase implements PluginInterface
{
    /**
     * Plugin metadata
     */
    protected $pluginId;

    protected $pluginName;

    protected $pluginType;

    protected $version = '1.0.0';

    protected $description = '';

    protected $author = 'WebOS Team';

    protected $dependencies = [];

    protected $permissions = [];

    protected $routes = [];

    protected $database = [
        'migrations' => [],
        'models' => [],
    ];

    protected $config = [];

    protected $isEnabled = true;

    protected $cacheKey;

    protected $cacheTTL = 3600; // 1 hour

    /**
     * PluginBase constructor
     */
    public function __construct()
    {
        // Initialize basic properties first
        $this->pluginId = $this->pluginId ?? 'base-plugin';
        $this->pluginName = $this->pluginName ?? 'Base Plugin';
        $this->pluginType = $this->pluginType ?? 'base';
        $this->version = $this->version ?? '1.0.0';

        // Now set up cache and config
        $this->cacheKey = 'plugin_'.$this->pluginId.'_config';
        $this->initializeConfig();
    }

    /**
     * Initialize plugin configuration
     */
    protected function initializeConfig(): void
    {
        // Load from cache if available
        if (Cache::has($this->cacheKey)) {
            $this->config = Cache::get($this->cacheKey);

            return;
        }

        // Load default configuration
        $this->config = $this->getDefaultConfig();

        // Cache the configuration
        Cache::put($this->cacheKey, $this->config, $this->cacheTTL);
    }

    /**
     * Get default configuration
     */
    protected function getDefaultConfig(): array
    {
        return [
            'enabled' => $this->isEnabled,
            'permissions' => $this->permissions,
            'routes' => $this->routes,
            'database' => $this->database,
        ];
    }

    /**
     * Get the plugin ID
     */
    public function getId(): string
    {
        return $this->pluginId;
    }

    /**
     * Get the plugin name
     */
    public function getName(): string
    {
        return $this->pluginName;
    }

    /**
     * Get the plugin type
     */
    public function getType(): string
    {
        return $this->pluginType;
    }

    /**
     * Get the plugin version
     */
    public function getVersion(): string
    {
        return $this->version;
    }

    /**
     * Get plugin description
     */
    public function getDescription(): string
    {
        return $this->description;
    }

    /**
     * Get plugin dependencies
     */
    public function getDependencies(): array
    {
        return $this->dependencies;
    }

    /**
     * Get required permissions
     */
    public function getPermissions(): array
    {
        return $this->permissions;
    }

    /**
     * Get plugin routes
     */
    public function getRoutes(): array
    {
        return $this->routes;
    }

    /**
     * Initialize the plugin
     */
    public function initialize(): bool
    {
        try {
            // Validate plugin configuration
            $this->validateConfiguration();

            // Register routes
            $this->registerRoutes();

            // Register permissions
            $this->registerPermissions();

            // Run database migrations if needed
            $this->runMigrations();

            Log::info('Plugin initialized: '.$this->getName());

            return true;
        } catch (\Exception $e) {
            Log::error('Plugin initialization failed: '.$this->getName().' - '.$e->getMessage());

            return false;
        }
    }

    /**
     * Check if plugin is enabled
     */
    public function isEnabled(): bool
    {
        return $this->isEnabled;
    }

    /**
     * Get plugin configuration
     */
    public function getConfig(): array
    {
        return $this->config;
    }

    /**
     * Validate plugin configuration
     *
     * @throws \Exception
     */
    protected function validateConfiguration(): void
    {
        if (empty($this->pluginId)) {
            throw new \Exception('Plugin ID is required');
        }

        if (empty($this->pluginName)) {
            throw new \Exception('Plugin name is required');
        }

        if (empty($this->pluginType) || ! in_array($this->pluginType, ['mini-app', 'meta-app', 'standalone'])) {
            throw new \Exception('Invalid plugin type');
        }

        if (empty($this->version)) {
            throw new \Exception('Plugin version is required');
        }
    }

    /**
     * Register plugin routes
     */
    protected function registerRoutes(): void
    {
        // This will be implemented by the plugin manager
    }

    /**
     * Register plugin permissions
     */
    protected function registerPermissions(): void
    {
        // This will be implemented by the plugin manager
    }

    /**
     * Run database migrations
     */
    protected function runMigrations(): void
    {
        // This will be implemented by the plugin manager
    }

    /**
     * Clear plugin cache
     */
    public function clearCache(): void
    {
        Cache::forget($this->cacheKey);
        $this->initializeConfig();
    }

    /**
     * Check if user has permission for this plugin
     */
    public function checkUserPermission(IdentityUserContract $user, string $permission): bool
    {
        if ($user->is_system_user) {
            return true;
        }

        // Check if user has the specific permission
        return $user->hasPermission($permission);
    }

    /**
     * Check if current partition has access to this plugin
     */
    public function checkPartitionAccess(IdentityPartitionContract $partition): bool
    {
        // By default, all partitions have access
        // Can be overridden by specific plugins
        return true;
    }

    /**
     * Get plugin manifest data
     */
    public function getManifest(): array
    {
        return [
            'id' => $this->getId(),
            'name' => $this->getName(),
            'type' => $this->getType(),
            'version' => $this->getVersion(),
            'description' => $this->description,
            'author' => $this->author,
            'dependencies' => $this->getDependencies(),
            'permissions' => $this->getPermissions(),
            'routes' => $this->getRoutes(),
            'database' => $this->database,
            'enabled' => $this->isEnabled(),
        ];
    }

    /**
     * Load plugin from manifest file
     *
     * @throws \Exception
     */
    protected function loadManifest(string $manifestPath): array
    {
        if (! File::exists($manifestPath)) {
            throw new \Exception('Plugin manifest file not found: '.$manifestPath);
        }

        $manifestContent = File::get($manifestPath);
        $manifestData = json_decode($manifestContent, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception('Invalid JSON in plugin manifest: '.json_last_error_msg());
        }

        return $manifestData;
    }
}
