<?php

namespace NewSolari\Core\Services;

use NewSolari\Core\Identity\Models\IdentityUser;
use NewSolari\Core\Identity\Models\PartitionApp;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PartitionAppService
{
    /**
     * The plugin registry instance.
     */
    protected PluginRegistry $registry;

    /**
     * Cache TTL in seconds (5 minutes).
     */
    protected int $cacheTtl = 300;

    /**
     * Create a new partition app service instance.
     */
    public function __construct(PluginRegistry $registry)
    {
        $this->registry = $registry;
    }

    /**
     * Get a plugin/module manifest by ID.
     * Checks PluginRegistry first, then ModuleRegistry, then remote services config.
     */
    protected function resolveManifest(string $pluginId): ?array
    {
        // Check legacy PluginRegistry first
        $manifest = $this->registry->getManifest($pluginId);
        if ($manifest) {
            return $manifest;
        }

        // Check ModuleRegistry (in-process + discovered modules) — IDs use format "{id}-{type}"
        $moduleRegistry = app(\NewSolari\Core\Module\ModuleRegistry::class);
        foreach ($moduleRegistry->getAllModulesWithManifest() as $mod) {
            $candidatePluginId = $mod['id'] . '-' . ($mod['type'] ?? 'mini-app');
            if ($candidatePluginId === $pluginId) {
                return [
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

        return null;
    }

    /**
     * Check if an app is enabled for a partition.
     */
    public function isEnabled(string $partitionId, string $pluginId): bool
    {
        $cacheKey = "partition_app:{$partitionId}:{$pluginId}";

        return Cache::remember($cacheKey, $this->cacheTtl, function () use ($partitionId, $pluginId) {
            $record = PartitionApp::where('partition_id', $partitionId)
                ->where('plugin_id', $pluginId)
                ->first();

            // If no record exists, default to enabled (backward compatibility)
            return $record ? $record->is_enabled : true;
        });
    }

    /**
     * Enable an app for a partition.
     *
     * @throws \Exception
     */
    public function enable(string $partitionId, string $pluginId, IdentityUser $user, bool $enableDependencies = true): array
    {
        DB::beginTransaction();

        try {
            // Validate plugin exists
            $plugin = $this->resolveManifest($pluginId);
            if (! $plugin) {
                throw new \Exception("Plugin not found: {$pluginId}");
            }

            $enabledDependencies = [];

            // Handle dependencies
            if ($enableDependencies) {
                $dependencies = $this->registry->resolveDependencyChain($pluginId);

                foreach ($dependencies as $depId) {
                    // Only enable if not already enabled
                    if (! $this->isEnabled($partitionId, $depId)) {
                        $this->enableSingle($partitionId, $depId, $user);
                        $enabledDependencies[] = $depId;
                        Log::info("Auto-enabled dependency: {$depId} for {$pluginId}");
                    }
                }
            } else {
                // Validate dependencies are already enabled
                $this->validateDependenciesEnabled($partitionId, $pluginId);
            }

            // Enable the app
            $this->enableSingle($partitionId, $pluginId, $user);

            DB::commit();

            // Clear cache
            $this->clearCache($partitionId, $pluginId);
            foreach ($enabledDependencies as $depId) {
                $this->clearCache($partitionId, $depId);
            }

            Log::info("Enabled app: {$pluginId} for partition: {$partitionId}", [
                'user' => $user->record_id,
                'dependencies_enabled' => $enabledDependencies,
            ]);

            return [
                'enabled' => true,
                'plugin_id' => $pluginId,
                'dependencies_enabled' => $enabledDependencies,
            ];
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Failed to enable app: {$pluginId}", [
                'partition_id' => $partitionId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Disable an app for a partition.
     *
     * @throws \Exception
     */
    public function disable(string $partitionId, string $pluginId, IdentityUser $user, bool $force = false): array
    {
        DB::beginTransaction();

        try {
            $cascadeDisabled = [];

            // Check for dependent apps
            if (! $force) {
                $enabledDependents = $this->getEnabledDependents($partitionId, $pluginId);

                if (! empty($enabledDependents)) {
                    $dependentNames = array_map(function ($id) {
                        $manifest = $this->resolveManifest($id);

                        return $manifest['name'] ?? $id;
                    }, $enabledDependents);

                    throw new \Exception(
                        "Cannot disable '{$pluginId}' - required by: ".implode(', ', $dependentNames)
                    );
                }
            } else {
                // Cascade disable
                $dependents = $this->getEnabledDependents($partitionId, $pluginId);

                foreach ($dependents as $depId) {
                    $this->disableSingle($partitionId, $depId, $user);
                    $cascadeDisabled[] = $depId;
                    Log::info("Cascade disabled dependent: {$depId} of {$pluginId}");
                }
            }

            // Disable the app
            $this->disableSingle($partitionId, $pluginId, $user);

            DB::commit();

            // Clear cache
            $this->clearCache($partitionId, $pluginId);
            foreach ($cascadeDisabled as $depId) {
                $this->clearCache($partitionId, $depId);
            }

            Log::info("Disabled app: {$pluginId} for partition: {$partitionId}", [
                'user' => $user->record_id,
                'cascade_disabled' => $cascadeDisabled,
            ]);

            return [
                'disabled' => true,
                'plugin_id' => $pluginId,
                'cascade_disabled' => $cascadeDisabled,
            ];
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Failed to disable app: {$pluginId}", [
                'partition_id' => $partitionId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Bulk update app status for a partition.
     * All changes are wrapped in a single transaction to prevent partial failures.
     *
     * @param  array  $apps  Array of plugin_id => is_enabled
     */
    public function bulkUpdate(string $partitionId, array $apps, IdentityUser $user): array
    {
        $enabled = [];
        $disabled = [];
        $errors = [];

        // Wrap entire bulk operation in a transaction to prevent partial updates
        // Note: Individual enable/disable methods have their own transactions,
        // but we use a savepoint pattern here for atomicity
        DB::beginTransaction();

        try {
            foreach ($apps as $pluginId => $shouldEnable) {
                try {
                    if ($shouldEnable) {
                        // enableSingle directly to avoid nested transaction issues
                        $this->enableWithDependencies($partitionId, $pluginId, $user);
                        $enabled[] = $pluginId;
                    } else {
                        // disableSingle directly to avoid nested transaction issues
                        $this->disableWithDependents($partitionId, $pluginId, $user);
                        $disabled[] = $pluginId;
                    }
                } catch (\Exception $e) {
                    $errors[$pluginId] = $e->getMessage();
                    // Continue processing other apps, but record the error
                }
            }

            // If there are any errors, rollback everything
            if (! empty($errors)) {
                // Track affected plugins before clearing arrays
                $affectedPlugins = array_merge($enabled, $disabled);

                DB::rollBack();
                Log::warning("Bulk update rolled back due to errors", [
                    'partition_id' => $partitionId,
                    'errors' => $errors,
                    'rolled_back_enabled' => $enabled,
                    'rolled_back_disabled' => $disabled,
                ]);

                // Clear cache for plugins that may have been read during transaction
                foreach ($affectedPlugins as $pluginId) {
                    $this->clearCache($partitionId, $pluginId);
                }

                // Clear success arrays since changes were rolled back
                $enabled = [];
                $disabled = [];
            } else {
                // Store data for after-commit callback
                $cacheData = [
                    'partition_id' => $partitionId,
                    'plugins' => array_merge($enabled, $disabled),
                ];

                // Clear cache only after transaction commits successfully
                DB::afterCommit(function () use ($cacheData) {
                    foreach ($cacheData['plugins'] as $pluginId) {
                        $this->clearCache($cacheData['partition_id'], $pluginId);
                    }
                });

                DB::commit();

                Log::info("Bulk update completed for partition: {$partitionId}", [
                    'user' => $user->record_id,
                    'enabled' => $enabled,
                    'disabled' => $disabled,
                ]);
            }
        } catch (\Exception $e) {
            DB::rollBack();

            // Clear cache on exception to prevent stale data
            foreach (array_keys($apps) as $pluginId) {
                $this->clearCache($partitionId, $pluginId);
            }

            Log::error("Bulk update failed for partition: {$partitionId}", [
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }

        return [
            'enabled' => $enabled,
            'disabled' => $disabled,
            'errors' => $errors,
        ];
    }

    /**
     * Enable an app with its dependencies (for use within a transaction).
     */
    protected function enableWithDependencies(string $partitionId, string $pluginId, IdentityUser $user): void
    {
        // Validate plugin exists
        $plugin = $this->resolveManifest($pluginId);
        if (! $plugin) {
            throw new \Exception("Plugin not found: {$pluginId}");
        }

        // Enable dependencies first
        $dependencies = $this->registry->resolveDependencyChain($pluginId);
        foreach ($dependencies as $depId) {
            if (! $this->isEnabled($partitionId, $depId)) {
                $this->enableSingle($partitionId, $depId, $user);
            }
        }

        // Enable the app itself
        $this->enableSingle($partitionId, $pluginId, $user);
    }

    /**
     * Disable an app and its dependents (for use within a transaction).
     */
    protected function disableWithDependents(string $partitionId, string $pluginId, IdentityUser $user): void
    {
        // Check for dependent apps
        $enabledDependents = $this->getEnabledDependents($partitionId, $pluginId);

        if (! empty($enabledDependents)) {
            $dependentNames = array_map(function ($id) {
                $manifest = $this->resolveManifest($id);
                return $manifest['name'] ?? $id;
            }, $enabledDependents);

            throw new \Exception(
                "Cannot disable '{$pluginId}' - required by: " . implode(', ', $dependentNames)
            );
        }

        // Disable the app
        $this->disableSingle($partitionId, $pluginId, $user);
    }

    /**
     * Get all enabled apps for a partition.
     */
    public function getEnabledApps(string $partitionId): Collection
    {
        $cacheKey = "partition_apps:{$partitionId}";

        return Cache::remember($cacheKey, $this->cacheTtl, function () use ($partitionId) {
            return PartitionApp::where('partition_id', $partitionId)
                ->where('is_enabled', true)
                ->get();
        });
    }

    /**
     * Initialize default apps for a new partition.
     *
     * @param  bool  $enabled  Whether apps should be enabled by default (default: false for new partitions)
     */
    public function initializeDefaults(string $partitionId, bool $enabled = false): void
    {
        $plugins = $this->registry->getAll();

        foreach ($plugins as $pluginId => $manifest) {
            PartitionApp::firstOrCreate(
                [
                    'partition_id' => $partitionId,
                    'plugin_id' => $pluginId,
                ],
                [
                    'id' => (string) Str::uuid(),
                    'is_enabled' => $enabled,
                    'enabled_at' => $enabled ? now() : null,
                    'configuration' => null,
                ]
            );
        }

        Log::info("Initialized default apps for partition: {$partitionId}", [
            'count' => count($plugins),
            'enabled' => $enabled,
        ]);
    }

    /**
     * Enable a single app without dependency checking.
     */
    protected function enableSingle(string $partitionId, string $pluginId, IdentityUser $user): void
    {
        // Use updateOrCreate for atomic operation - avoids race condition
        // Note: PartitionApp uses HasUuids trait for auto-generated UUIDs
        PartitionApp::updateOrCreate(
            [
                'partition_id' => $partitionId,
                'plugin_id' => $pluginId,
            ],
            [
                'is_enabled' => true,
                'enabled_by' => $user->record_id,
                'enabled_at' => now(),
                'disabled_at' => null,
            ]
        );
    }

    /**
     * Disable a single app without dependent checking.
     */
    protected function disableSingle(string $partitionId, string $pluginId, IdentityUser $user): void
    {
        // Use updateOrCreate for atomic operation - avoids race condition
        // Note: PartitionApp uses HasUuids trait for auto-generated UUIDs
        PartitionApp::updateOrCreate(
            [
                'partition_id' => $partitionId,
                'plugin_id' => $pluginId,
            ],
            [
                'is_enabled' => false,
                'disabled_at' => now(),
            ]
        );
    }

    /**
     * Get all enabled apps that depend on the given plugin.
     */
    protected function getEnabledDependents(string $partitionId, string $pluginId): array
    {
        $dependents = $this->registry->getDependents($pluginId);
        $enabledDependents = [];

        foreach ($dependents as $depId) {
            if ($this->isEnabled($partitionId, $depId)) {
                $enabledDependents[] = $depId;
            }
        }

        return $enabledDependents;
    }

    /**
     * Validate that all dependencies are enabled.
     *
     * @throws \Exception
     */
    protected function validateDependenciesEnabled(string $partitionId, string $pluginId): void
    {
        $dependencies = $this->registry->getDependencies($pluginId);
        $missing = [];

        foreach ($dependencies as $depId) {
            if (! $this->isEnabled($partitionId, $depId)) {
                $missing[] = $depId;
            }
        }

        if (! empty($missing)) {
            throw new \Exception(
                "Cannot enable '{$pluginId}' - missing dependencies: ".implode(', ', $missing)
            );
        }
    }

    /**
     * Clear cache for a partition app.
     */
    public function clearCache(string $partitionId, ?string $pluginId = null): void
    {
        if ($pluginId) {
            Cache::forget("partition_app:{$partitionId}:{$pluginId}");
            Cache::forget("partition_app_ui:{$partitionId}:{$pluginId}");
            Cache::forget("partition_app_dashboard:{$partitionId}:{$pluginId}");
            Cache::forget("partition_app_exclude_meta:{$partitionId}:{$pluginId}");
            Cache::forget("partition_app_admin_only:{$partitionId}:{$pluginId}");
        }

        Cache::forget("partition_apps:{$partitionId}");
    }

    /**
     * Get app record for a partition (includes show_in_ui).
     */
    public function getAppRecord(string $partitionId, string $pluginId): ?PartitionApp
    {
        return PartitionApp::where('partition_id', $partitionId)
            ->where('plugin_id', $pluginId)
            ->first();
    }

    /**
     * Set UI visibility for an app.
     */
    public function setUiVisibility(string $partitionId, string $pluginId, bool $showInUi, IdentityUser $user): array
    {
        try {
            // Validate plugin exists
            $plugin = $this->resolveManifest($pluginId);
            if (! $plugin) {
                throw new \Exception("Plugin not found: {$pluginId}");
            }

            // Update or create the record
            PartitionApp::updateOrCreate(
                [
                    'partition_id' => $partitionId,
                    'plugin_id' => $pluginId,
                ],
                [
                    'show_in_ui' => $showInUi,
                ]
            );

            // Clear cache
            $this->clearCache($partitionId, $pluginId);

            Log::info("Set UI visibility for app: {$pluginId} in partition: {$partitionId}", [
                'user' => $user->record_id,
                'show_in_ui' => $showInUi,
            ]);

            return [
                'plugin_id' => $pluginId,
                'show_in_ui' => $showInUi,
            ];
        } catch (\Exception $e) {
            Log::error("Failed to set UI visibility for app: {$pluginId}", [
                'partition_id' => $partitionId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Check if an app should be shown in UI for a partition.
     */
    public function isVisibleInUi(string $partitionId, string $pluginId): bool
    {
        $cacheKey = "partition_app_ui:{$partitionId}:{$pluginId}";

        return Cache::remember($cacheKey, $this->cacheTtl, function () use ($partitionId, $pluginId) {
            $record = PartitionApp::where('partition_id', $partitionId)
                ->where('plugin_id', $pluginId)
                ->first();

            // If no record exists, default to visible
            return $record ? $record->show_in_ui : true;
        });
    }

    /**
     * Set dashboard visibility for an app.
     */
    public function setDashboardVisibility(string $partitionId, string $pluginId, bool $showInDashboard, IdentityUser $user): array
    {
        try {
            // Validate plugin exists
            $plugin = $this->resolveManifest($pluginId);
            if (! $plugin) {
                throw new \Exception("Plugin not found: {$pluginId}");
            }

            // Update or create the record
            PartitionApp::updateOrCreate(
                [
                    'partition_id' => $partitionId,
                    'plugin_id' => $pluginId,
                ],
                [
                    'show_in_dashboard' => $showInDashboard,
                ]
            );

            // Clear cache
            $this->clearCache($partitionId, $pluginId);

            Log::info("Set dashboard visibility for app: {$pluginId} in partition: {$partitionId}", [
                'user' => $user->record_id,
                'show_in_dashboard' => $showInDashboard,
            ]);

            return [
                'plugin_id' => $pluginId,
                'show_in_dashboard' => $showInDashboard,
            ];
        } catch (\Exception $e) {
            Log::error("Failed to set dashboard visibility for app: {$pluginId}", [
                'partition_id' => $partitionId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Check if an app should be shown in dashboard for a partition.
     */
    public function isVisibleInDashboard(string $partitionId, string $pluginId): bool
    {
        $cacheKey = "partition_app_dashboard:{$partitionId}:{$pluginId}";

        return Cache::remember($cacheKey, $this->cacheTtl, function () use ($partitionId, $pluginId) {
            $record = PartitionApp::where('partition_id', $partitionId)
                ->where('plugin_id', $pluginId)
                ->first();

            // If no record exists, default to visible
            return $record ? $record->show_in_dashboard : true;
        });
    }

    /**
     * Set exclude_meta_app setting for an app.
     */
    public function setExcludeMetaApp(string $partitionId, string $pluginId, bool $excludeMetaApp, IdentityUser $user): array
    {
        try {
            // Validate plugin exists
            $plugin = $this->resolveManifest($pluginId);
            if (! $plugin) {
                throw new \Exception("Plugin not found: {$pluginId}");
            }

            // Update or create the record
            PartitionApp::updateOrCreate(
                [
                    'partition_id' => $partitionId,
                    'plugin_id' => $pluginId,
                ],
                [
                    'exclude_meta_app' => $excludeMetaApp,
                ]
            );

            // Clear cache
            $this->clearCache($partitionId, $pluginId);

            Log::info("Set exclude_meta_app for app: {$pluginId} in partition: {$partitionId}", [
                'user' => $user->record_id,
                'exclude_meta_app' => $excludeMetaApp,
            ]);

            return [
                'plugin_id' => $pluginId,
                'exclude_meta_app' => $excludeMetaApp,
            ];
        } catch (\Exception $e) {
            Log::error("Failed to set exclude_meta_app for app: {$pluginId}", [
                'partition_id' => $partitionId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Check if an app should exclude meta-app data.
     */
    public function shouldExcludeMetaApp(string $partitionId, string $pluginId): bool
    {
        $cacheKey = "partition_app_exclude_meta:{$partitionId}:{$pluginId}";

        return Cache::remember($cacheKey, $this->cacheTtl, function () use ($partitionId, $pluginId) {
            $record = PartitionApp::where('partition_id', $partitionId)
                ->where('plugin_id', $pluginId)
                ->first();

            // If no record exists, default to false (show all data)
            return $record ? (bool) $record->exclude_meta_app : false;
        });
    }

    /**
     * Set admin_only setting for an app.
     */
    public function setAdminOnly(string $partitionId, string $pluginId, bool $adminOnly, IdentityUser $user): array
    {
        try {
            // Validate plugin exists
            $plugin = $this->resolveManifest($pluginId);
            if (! $plugin) {
                throw new \Exception("Plugin not found: {$pluginId}");
            }

            // Update or create the record
            PartitionApp::updateOrCreate(
                [
                    'partition_id' => $partitionId,
                    'plugin_id' => $pluginId,
                ],
                [
                    'admin_only' => $adminOnly,
                ]
            );

            // Clear cache
            $this->clearCache($partitionId, $pluginId);

            Log::info("Set admin_only for app: {$pluginId} in partition: {$partitionId}", [
                'user' => $user->record_id,
                'admin_only' => $adminOnly,
            ]);

            return [
                'plugin_id' => $pluginId,
                'admin_only' => $adminOnly,
            ];
        } catch (\Exception $e) {
            Log::error("Failed to set admin_only for app: {$pluginId}", [
                'partition_id' => $partitionId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Check if an app is admin-only.
     */
    public function isAdminOnly(string $partitionId, string $pluginId): bool
    {
        $cacheKey = "partition_app_admin_only:{$partitionId}:{$pluginId}";

        return Cache::remember($cacheKey, $this->cacheTtl, function () use ($partitionId, $pluginId) {
            $record = PartitionApp::where('partition_id', $partitionId)
                ->where('plugin_id', $pluginId)
                ->first();

            // If no record exists, default to false (visible to all users)
            return $record ? (bool) $record->admin_only : false;
        });
    }
}
