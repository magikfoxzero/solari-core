<?php

namespace NewSolari\Core\Module;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use NewSolari\Core\Module\Contracts\ModuleInterface;

class ModuleRegistry
{
    protected array $modules = [];
    protected ?bool $coreModulesTableExists = null;
    protected ?array $discovered = null;

    public function register(ModuleInterface $module): void
    {
        $this->modules[$module->getId()] = $module;
    }

    public function isEnabled(string $moduleId): bool
    {
        // Config-based check first (works with config:cache in production)
        $configEnabled = config("modules.{$moduleId}.enabled");
        if ($configEnabled !== null) {
            return (bool) $configEnabled;
        }

        // Fallback to env (dev convenience only — breaks with config:cache)
        $slug = strtoupper(str_replace('-', '_', $moduleId));
        $envValue = env("MODULE_{$slug}_ENABLED");
        if ($envValue !== null) {
            return filter_var($envValue, FILTER_VALIDATE_BOOLEAN);
        }

        // Check DB (cached) — only if table exists (cached per-request)
        if ($this->coreModulesTableExists()) {
            $dbStatus = $this->getModuleStatus($moduleId);
            if ($dbStatus !== null) {
                return $dbStatus === 'enabled';
            }
        }

        // Fallback: default to enabled if no config or DB entry
        return true;
    }

    /**
     * Returns cached array of module.json data indexed by module ID.
     */
    public function getDiscoveredModules(): array
    {
        if ($this->discovered !== null) {
            return $this->discovered;
        }

        $this->discovered = Cache::remember(
            'module_registry:discovered',
            config('modules.cache_ttl', 3600),
            fn () => $this->scanModuleJsonFiles()
        );

        return $this->discovered;
    }

    /**
     * Scans modules directory for module.json files.
     * Returns array indexed by module ID.
     */
    public function scanModuleJsonFiles(): array
    {
        $discoveryPath = config('modules.discovery_path', base_path('../modules'));
        $modules = [];

        $pattern = rtrim($discoveryPath, '/') . '/*/backend/module.json';
        $files = glob($pattern);

        if (!$files) {
            return [];
        }

        foreach ($files as $file) {
            try {
                $contents = file_get_contents($file);
                if ($contents === false) {
                    continue;
                }

                $data = json_decode($contents, true);
                if (!is_array($data) || empty($data['id'])) {
                    continue;
                }

                $modules[$data['id']] = $data;
            } catch (\Throwable) {
                // Skip malformed module.json files
                continue;
            }
        }

        return $modules;
    }

    /**
     * Merges discovered + in-process modules (in-process overrides discovered).
     * Filters by isEnabled(). Returns normalized array of module info.
     */
    public function getAllModulesWithManifest(): array
    {
        $discovered = $this->getDiscoveredModules();
        $result = [];

        // Start with discovered modules
        foreach ($discovered as $id => $data) {
            if (!$this->isEnabled($id)) {
                continue;
            }

            $result[$id] = [
                'id' => $id,
                'name' => $data['name'] ?? $id,
                'type' => $data['type'] ?? 'unknown',
                'version' => $data['version'] ?? '0.0.0',
                'description' => $data['description'] ?? '',
                'frontend' => $data['frontend'] ?? null,
                'dependencies' => $data['dependencies'] ?? [],
            ];
        }

        // In-process modules override discovered
        foreach ($this->modules as $id => $module) {
            if (!$this->isEnabled($id)) {
                continue;
            }

            $discoveredData = $discovered[$id] ?? [];

            $result[$id] = [
                'id' => $id,
                'name' => $module->getName(),
                'type' => $module->getType(),
                'version' => $module->getVersion(),
                'description' => $discoveredData['description'] ?? '',
                'frontend' => $module->getFrontendManifest() ?? ($discoveredData['frontend'] ?? null),
                'dependencies' => $module->getDependencies(),
            ];
        }

        return $result;
    }

    /**
     * Gets a single module's manifest (checks in-process first, then discovered).
     */
    public function getModuleManifest(string $moduleId): ?array
    {
        // Check in-process modules first
        $module = $this->getModule($moduleId);
        if ($module !== null) {
            $discoveredData = $this->getDiscoveredModules()[$moduleId] ?? [];

            return [
                'name' => $module->getName(),
                'type' => $module->getType(),
                'version' => $module->getVersion(),
                'description' => $discoveredData['description'] ?? '',
                'routes' => $discoveredData['routes'] ?? null,
                'permissions' => $discoveredData['permissions'] ?? null,
                'dependencies' => $module->getDependencies(),
            ];
        }

        // Check discovered modules
        $discovered = $this->getDiscoveredModules();
        if (!isset($discovered[$moduleId])) {
            return null;
        }

        $data = $discovered[$moduleId];

        return [
            'name' => $data['name'] ?? $moduleId,
            'type' => $data['type'] ?? 'unknown',
            'version' => $data['version'] ?? '0.0.0',
            'description' => $data['description'] ?? '',
            'routes' => $data['routes'] ?? null,
            'permissions' => $data['permissions'] ?? null,
            'dependencies' => $data['dependencies'] ?? [],
        ];
    }

    /**
     * Clears both the in-memory cache and the Cache store key for discovered modules.
     */
    public function clearDiscoveryCache(): void
    {
        $this->discovered = null;
        Cache::forget('module_registry:discovered');
    }

    public function getEnabledModules(): array
    {
        return array_filter(
            $this->modules,
            fn (ModuleInterface $mod) => $this->isEnabled($mod->getId())
        );
    }

    public function getModule(string $moduleId): ?ModuleInterface
    {
        return $this->modules[$moduleId] ?? null;
    }

    public function getServiceContract(string $moduleId): ?string
    {
        $module = $this->getModule($moduleId);
        return $module?->getServiceContract();
    }

    public function getAllModules(): array
    {
        return $this->modules;
    }

    protected function coreModulesTableExists(): bool
    {
        if ($this->coreModulesTableExists === null) {
            $this->coreModulesTableExists = Schema::hasTable('core_modules');
        }
        return $this->coreModulesTableExists;
    }

    protected function getModuleStatus(string $moduleId): ?string
    {
        return Cache::remember(
            "module_status:{$moduleId}",
            300,
            function () use ($moduleId) {
                $row = DB::table('core_modules')->where('id', $moduleId)->first();
                return $row?->status;
            }
        );
    }

    public function clearCache(string $moduleId): void
    {
        Cache::forget("module_status:{$moduleId}");
    }
}
