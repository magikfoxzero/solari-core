<?php

namespace NewSolari\Core\Services;

use NewSolari\Core\Identity\Models\EntityTypeRegistry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class EntityTypeRegistryService
{
    /**
     * Register a new entity type.
     *
     * @param  array  $options  Additional options (table_name, icon, category, etc.)
     */
    public function register(
        string $typeKey,
        string $modelClass,
        string $displayName,
        array $options = []
    ): EntityTypeRegistry {
        // Validate model class exists
        if (! class_exists($modelClass)) {
            throw new \InvalidArgumentException("Model class does not exist: {$modelClass}");
        }

        // Get table name from model if not provided
        // Use try-catch to handle models with required constructor parameters
        if (isset($options['table_name'])) {
            $tableName = $options['table_name'];
        } else {
            try {
                $tableName = (new $modelClass)->getTable();
            } catch (\Throwable $e) {
                // Model cannot be instantiated without parameters
                // Try using reflection to get the table name
                try {
                    $reflection = new \ReflectionClass($modelClass);
                    $defaultProperties = $reflection->getDefaultProperties();
                    $tableName = $defaultProperties['table'] ?? null;

                    // If table property not found, derive from class name
                    if (! $tableName) {
                        $className = class_basename($modelClass);
                        $tableName = \Illuminate\Support\Str::snake(\Illuminate\Support\Str::pluralStudly($className));
                    }
                } catch (\ReflectionException $re) {
                    throw new \InvalidArgumentException(
                        "Cannot determine table name for model: {$modelClass}. ".
                        "Please provide 'table_name' in options. Error: ".$e->getMessage()
                    );
                }
            }
        }

        // Use updateOrCreate for atomic operation - avoids race condition
        $wasRecentlyCreated = false;
        $entityType = EntityTypeRegistry::updateOrCreate(
            ['type_key' => $typeKey],
            [
                'model_class' => $modelClass,
                'table_name' => $tableName,
                'display_name' => $displayName,
                'display_name_plural' => $options['display_name_plural'] ?? $displayName.'s',
                'icon' => $options['icon'] ?? null,
                'category' => $options['category'] ?? 'custom',
                'is_active' => $options['is_active'] ?? true,
                'plugin_id' => $options['plugin_id'] ?? null,
                'config' => $options['config'] ?? null,
            ]
        );

        // Set create-only fields and audit fields based on whether it was created or updated
        if ($entityType->wasRecentlyCreated) {
            $entityType->update([
                'is_system' => $options['is_system'] ?? false,
                'created_by' => $options['created_by'] ?? null,
            ]);
        } else {
            Log::warning("Entity type already registered, updating: {$typeKey}");
            $entityType->update([
                'updated_by' => $options['updated_by'] ?? null,
            ]);
        }

        return $entityType;
    }

    /**
     * Unregister an entity type.
     *
     * @throws \Exception
     */
    public function unregister(string $typeKey): bool
    {
        $entityType = EntityTypeRegistry::find($typeKey);

        if (! $entityType) {
            throw new \Exception("Entity type not found: {$typeKey}");
        }

        return $entityType->unregister();
    }

    /**
     * Resolve a type key to its model class.
     */
    public function resolve(string $typeKey): ?string
    {
        $entityType = EntityTypeRegistry::findByTypeKey($typeKey);

        return $entityType?->model_class;
    }

    /**
     * Get a model instance for a type key.
     */
    public function getModelInstance(string $typeKey): ?Model
    {
        $entityType = EntityTypeRegistry::findByTypeKey($typeKey);

        return $entityType?->getModelInstance();
    }

    /**
     * Get all registered entity types.
     */
    public function getAll(bool $activeOnly = true): Collection
    {
        return $activeOnly
            ? EntityTypeRegistry::getAllActive()
            : EntityTypeRegistry::all();
    }

    /**
     * Get entity types grouped by category.
     */
    public function getAllByCategory(): Collection
    {
        return EntityTypeRegistry::getAllByCategory();
    }

    /**
     * Check if a type is registered.
     */
    public function isRegistered(string $typeKey): bool
    {
        return EntityTypeRegistry::findByTypeKey($typeKey) !== null;
    }

    /**
     * Get the table name for a type.
     */
    public function getTableName(string $typeKey): ?string
    {
        $entityType = EntityTypeRegistry::findByTypeKey($typeKey);

        return $entityType?->table_name;
    }

    /**
     * Get the type key for a model class.
     *
     * @param  string|Model  $model  Model class name or instance
     */
    public function getTypeKeyForModel($model): ?string
    {
        $modelClass = is_string($model) ? $model : get_class($model);
        $entityType = EntityTypeRegistry::findByModelClass($modelClass);

        return $entityType?->type_key;
    }

    /**
     * Get the type key for a table name.
     */
    public function getTypeKeyForTable(string $tableName): ?string
    {
        $entityType = EntityTypeRegistry::findByTableName($tableName);

        return $entityType?->type_key;
    }

    /**
     * Activate an entity type.
     */
    public function activate(string $typeKey): bool
    {
        $entityType = EntityTypeRegistry::find($typeKey);

        if (! $entityType) {
            throw new \Exception("Entity type not found: {$typeKey}");
        }

        return $entityType->update(['is_active' => true]);
    }

    /**
     * Deactivate an entity type.
     */
    public function deactivate(string $typeKey): bool
    {
        $entityType = EntityTypeRegistry::find($typeKey);

        if (! $entityType) {
            throw new \Exception("Entity type not found: {$typeKey}");
        }

        if ($entityType->is_system) {
            throw new \Exception("Cannot deactivate system entity type: {$typeKey}");
        }

        return $entityType->update(['is_active' => false]);
    }

    /**
     * Update entity type configuration.
     */
    public function updateConfig(string $typeKey, array $config): bool
    {
        $entityType = EntityTypeRegistry::find($typeKey);

        if (! $entityType) {
            throw new \Exception("Entity type not found: {$typeKey}");
        }

        return $entityType->update(['config' => array_merge($entityType->config ?? [], $config)]);
    }

    /**
     * Get configuration value for an entity type.
     */
    public function getConfig(string $typeKey, string $key, mixed $default = null): mixed
    {
        $entityType = EntityTypeRegistry::find($typeKey);

        if (! $entityType) {
            return $default;
        }

        return $entityType->getConfigValue($key, $default);
    }

    /**
     * Get all entity types for a plugin.
     */
    public function getForPlugin(string $pluginId): Collection
    {
        return EntityTypeRegistry::forPlugin($pluginId)->get();
    }

    /**
     * Bulk register entity types (useful for plugin installation).
     *
     * @param  array  $types  Array of type definitions
     * @return Collection Collection of created EntityTypeRegistry models
     */
    public function bulkRegister(array $types): Collection
    {
        $registered = collect();

        foreach ($types as $typeData) {
            try {
                $entityType = $this->register(
                    $typeData['type_key'],
                    $typeData['model_class'],
                    $typeData['display_name'],
                    $typeData['options'] ?? []
                );
                $registered->push($entityType);
            } catch (\Exception $e) {
                Log::error('Failed to register entity type: '.$e->getMessage(), [
                    'type_key' => $typeData['type_key'] ?? 'unknown',
                ]);
            }
        }

        return $registered;
    }

    /**
     * Clear the entity type registry cache.
     */
    public function clearCache(): void
    {
        // Use the centralized cache clearing method from the model
        EntityTypeRegistry::clearAllCaches();
    }

    /**
     * Validate that a type key exists and is active.
     *
     * @throws \Exception
     */
    public function validate(string $typeKey): bool
    {
        $entityType = EntityTypeRegistry::findByTypeKey($typeKey);

        if (! $entityType) {
            throw new \Exception("Unknown entity type: {$typeKey}");
        }

        if (! $entityType->is_active) {
            throw new \Exception("Entity type is not active: {$typeKey}");
        }

        if (! $entityType->modelClassExists()) {
            throw new \Exception("Model class does not exist for entity type: {$typeKey}");
        }

        return true;
    }
}
