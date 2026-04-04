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

    public function register(ModuleInterface $module): void
    {
        $this->modules[$module->getId()] = $module;
    }

    public function isEnabled(string $moduleId): bool
    {
        // Config-based override (reads env via config layer, safe with config:cache)
        // modules.php entries use env('MODULE_X_ENABLED', true), so this respects env vars
        $configEnabled = config("modules.{$moduleId}.enabled");
        if ($configEnabled === false) {
            return false;
        }

        // Check DB (cached) — only if table exists (cached per-request)
        if ($this->coreModulesTableExists()) {
            $dbStatus = $this->getModuleStatus($moduleId);
            if ($dbStatus !== null) {
                return $dbStatus === 'enabled';
            }
        }

        // Fallback: default to enabled if no config or DB entry
        return $configEnabled ?? true;
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
