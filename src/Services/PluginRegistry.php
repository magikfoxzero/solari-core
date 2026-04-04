<?php

namespace NewSolari\Core\Services;

use NewSolari\Core\Plugin\PluginInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

class PluginRegistry
{
    /**
     * Array of discovered plugin instances.
     *
     * @var array<string, PluginInterface>
     */
    protected array $plugins = [];

    /**
     * Array of plugin manifests (metadata only).
     */
    protected array $manifests = [];

    /**
     * Whether plugins have been discovered.
     */
    protected bool $discovered = false;

    /**
     * Create a new plugin registry instance.
     */
    public function __construct()
    {
        $this->discoverPlugins();
    }

    /**
     * Discover all available plugins.
     */
    protected function discoverPlugins(): void
    {
        if ($this->discovered) {
            return;
        }

        // Try to load from cache first (1 hour TTL)
        $cached = Cache::get('plugin_registry_manifests');
        if ($cached) {
            $this->manifests = $cached;
            $this->discovered = true;

            return;
        }

        Log::info('Discovering plugins...');

        // Scan legacy plugin directories (if they still exist)
        $this->scanPluginDirectory(app_path('Plugins/Apps/MiniApps'));
        $this->scanPluginDirectory(app_path('Plugins/Apps/MetaApps'));
        $this->scanPluginDirectory(app_path('Plugins/Apps/StandaloneApps'));

        // Discover from ModuleRegistry — modules register via their service providers
        if (app()->bound(\NewSolari\Core\Module\ModuleRegistry::class)) {
            $moduleRegistry = app(\NewSolari\Core\Module\ModuleRegistry::class);
            foreach ($moduleRegistry->getAllModules() as $module) {
                $pluginId = $module->getId() . '-' . $module->getType();
                if (!isset($this->manifests[$pluginId])) {
                    $this->manifests[$pluginId] = [
                        'name' => $module->getName(),
                        'type' => $module->getType(),
                        'version' => $module->getVersion(),
                        'description' => '',
                        'routes' => [],
                        'permissions' => [],
                        'dependencies' => $module->getDependencies(),
                    ];
                }
            }
        }

        // Cache the manifests
        Cache::put('plugin_registry_manifests', $this->manifests, 3600); // 1 hour

        $this->discovered = true;

        Log::info('Plugin discovery complete', ['count' => count($this->manifests)]);
    }

    /**
     * Scan a directory for plugin classes.
     */
    protected function scanPluginDirectory(string $directory): void
    {
        if (! File::isDirectory($directory)) {
            return;
        }

        $subdirectories = File::directories($directory);

        foreach ($subdirectories as $pluginDir) {
            $pluginName = basename($pluginDir);
            $pluginFile = $pluginDir.'/'.$pluginName.'Plugin.php';

            if (File::exists($pluginFile)) {
                $this->registerPlugin($pluginName, $pluginFile);
            }
        }
    }

    /**
     * Register a plugin from its file path.
     */
    protected function registerPlugin(string $pluginName, string $pluginFile): void
    {
        try {
            // Determine the namespace based on file path
            $relativePath = str_replace(app_path(), '', dirname($pluginFile));
            $namespace = 'App'.str_replace('/', '\\', $relativePath);
            $className = $namespace.'\\'.$pluginName.'Plugin';

            // Check if class exists
            if (! class_exists($className)) {
                Log::warning("Plugin class not found: {$className}");

                return;
            }

            // Instantiate plugin to get metadata using Laravel's container for dependency injection
            $plugin = app()->make($className);

            if (! ($plugin instanceof PluginInterface)) {
                Log::warning("Plugin does not implement PluginInterface: {$className}");

                return;
            }

            // Store manifest
            $this->manifests[$plugin->getId()] = [
                'id' => $plugin->getId(),
                'name' => $plugin->getName(),
                'type' => $plugin->getType(),
                'version' => $plugin->getVersion(),
                'description' => method_exists($plugin, 'getDescription') ? $plugin->getDescription() : '',
                'dependencies' => $plugin->getDependencies(),
                'permissions' => $plugin->getPermissions(),
                'routes' => $plugin->getRoutes(),
                'class' => $className,
            ];

            Log::debug("Registered plugin: {$plugin->getId()}");
        } catch (\Exception $e) {
            Log::error("Failed to register plugin: {$pluginName}", [
                'error' => $e->getMessage(),
                'file' => $pluginFile,
            ]);
        }
    }

    /**
     * Get all registered plugins.
     *
     * @return array<string, array>
     */
    public function getAll(): array
    {
        return $this->manifests;
    }

    /**
     * Get a specific plugin instance.
     */
    public function get(string $pluginId): ?PluginInterface
    {
        // Return cached instance if exists
        if (isset($this->plugins[$pluginId])) {
            return $this->plugins[$pluginId];
        }

        // Get manifest
        $manifest = $this->manifests[$pluginId] ?? null;
        if (! $manifest) {
            return null;
        }

        // Instantiate and cache using Laravel's container for dependency injection
        try {
            $this->plugins[$pluginId] = app()->make($manifest['class']);

            return $this->plugins[$pluginId];
        } catch (\Exception $e) {
            Log::error("Failed to instantiate plugin: {$pluginId}", [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Get the dependencies for a plugin.
     */
    public function getDependencies(string $pluginId): array
    {
        $manifest = $this->manifests[$pluginId] ?? null;

        return $manifest ? $manifest['dependencies'] : [];
    }

    /**
     * Get all plugins that depend on the given plugin.
     */
    public function getDependents(string $pluginId): array
    {
        $dependents = [];

        foreach ($this->manifests as $id => $manifest) {
            if (in_array($pluginId, $manifest['dependencies'])) {
                $dependents[] = $id;
            }
        }

        return $dependents;
    }

    /**
     * Resolve the full dependency chain for a plugin (including transitive dependencies).
     *
     * @param  array|null  $visited  Pass by reference to track across parallel branches
     *
     * @throws \RuntimeException if circular dependency detected
     */
    public function resolveDependencyChain(string $pluginId, ?array &$visited = null): array
    {
        // Initialize visited array on first call
        if ($visited === null) {
            $visited = [];
        }

        // Check for circular dependency
        if (in_array($pluginId, $visited)) {
            throw new \RuntimeException('Circular dependency detected: '.implode(' -> ', array_merge($visited, [$pluginId])));
        }

        $visited[] = $pluginId;
        $allDependencies = [];

        $dependencies = $this->getDependencies($pluginId);

        foreach ($dependencies as $depId) {
            // Recursively resolve dependencies (visited is passed by reference)
            $transitiveDeps = $this->resolveDependencyChain($depId, $visited);
            $allDependencies = array_merge($allDependencies, $transitiveDeps);

            // Add this dependency if not already in list
            if (! in_array($depId, $allDependencies)) {
                $allDependencies[] = $depId;
            }
        }

        return $allDependencies;
    }

    /**
     * Validate that all dependencies are present in the given list.
     *
     * @param  array|null  $visited  Pass by reference to prevent infinite recursion
     */
    public function validateDependencies(string $pluginId, array $enabledPluginIds, ?array &$visited = null): bool
    {
        // Initialize visited array on first call
        if ($visited === null) {
            $visited = [];
        }

        // Skip if already validated (prevents infinite loops)
        if (in_array($pluginId, $visited)) {
            return true;
        }

        $visited[] = $pluginId;
        $dependencies = $this->getDependencies($pluginId);

        foreach ($dependencies as $depId) {
            if (! in_array($depId, $enabledPluginIds)) {
                return false;
            }

            // Also check transitive dependencies (visited passed by reference)
            if (! $this->validateDependencies($depId, $enabledPluginIds, $visited)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get manifest for a specific plugin.
     */
    public function getManifest(string $pluginId): ?array
    {
        return $this->manifests[$pluginId] ?? null;
    }

    /**
     * Clear the plugin registry cache.
     */
    public function clearCache(): void
    {
        Cache::forget('plugin_registry_manifests');
        $this->discovered = false;
        $this->manifests = [];
        $this->plugins = [];
        $this->discoverPlugins();
    }
}
