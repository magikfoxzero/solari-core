<?php

namespace NewSolari\Core\Services;

use NewSolari\Core\Identity\Models\RelationshipTypeRegistry;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class RelationshipTypeRegistryService
{
    /**
     * Register a new relationship type.
     *
     * @param  array  $options  Additional options
     */
    public function register(
        string $typeKey,
        string $category,
        string $displayName,
        array $options = []
    ): RelationshipTypeRegistry {
        // Use updateOrCreate for atomic operation - avoids race condition
        $relType = RelationshipTypeRegistry::updateOrCreate(
            ['type_key' => $typeKey],
            [
                'category' => $category,
                'display_name' => $displayName,
                'description' => $options['description'] ?? null,
                'inverse_type' => $options['inverse_type'] ?? null,
                'allows_duplicates' => $options['allows_duplicates'] ?? false,
                'requires_metadata' => $options['requires_metadata'] ?? false,
                'metadata_schema' => $options['metadata_schema'] ?? null,
                'supports_priority' => $options['supports_priority'] ?? false,
                'supports_primary' => $options['supports_primary'] ?? false,
                'is_active' => $options['is_active'] ?? true,
                'plugin_id' => $options['plugin_id'] ?? null,
                'config' => $options['config'] ?? null,
            ]
        );

        // Set create-only fields and audit fields based on whether it was created or updated
        if ($relType->wasRecentlyCreated) {
            $relType->update([
                'is_system' => $options['is_system'] ?? false,
                'created_by' => $options['created_by'] ?? null,
            ]);
        } else {
            Log::warning("Relationship type already registered, updating: {$typeKey}");
            $relType->update([
                'updated_by' => $options['updated_by'] ?? null,
            ]);
        }

        return $relType;
    }

    /**
     * Unregister a relationship type.
     *
     * @throws \Exception
     */
    public function unregister(string $typeKey): bool
    {
        $relationshipType = RelationshipTypeRegistry::find($typeKey);

        if (! $relationshipType) {
            throw new \Exception("Relationship type not found: {$typeKey}");
        }

        return $relationshipType->unregister();
    }

    /**
     * Get all registered relationship types.
     */
    public function getAll(bool $activeOnly = true): Collection
    {
        return $activeOnly
            ? RelationshipTypeRegistry::getAllActive()
            : RelationshipTypeRegistry::all();
    }

    /**
     * Get relationship types grouped by category.
     */
    public function getAllByCategory(): Collection
    {
        return RelationshipTypeRegistry::getAllByCategory();
    }

    /**
     * Get relationship type by key.
     */
    public function get(string $typeKey): ?RelationshipTypeRegistry
    {
        return RelationshipTypeRegistry::findByTypeKey($typeKey);
    }

    /**
     * Check if a relationship type is registered.
     */
    public function isRegistered(string $typeKey): bool
    {
        return RelationshipTypeRegistry::findByTypeKey($typeKey) !== null;
    }

    /**
     * Get the category for a relationship type.
     */
    public function getCategory(string $typeKey): ?string
    {
        $type = RelationshipTypeRegistry::findByTypeKey($typeKey);

        return $type?->category;
    }

    /**
     * Get the metadata schema for a relationship type.
     */
    public function getMetadataSchema(string $typeKey): ?array
    {
        $type = RelationshipTypeRegistry::findByTypeKey($typeKey);

        return $type?->metadata_schema;
    }

    /**
     * Check if a relationship type allows duplicates.
     */
    public function allowsDuplicates(string $typeKey): bool
    {
        $type = RelationshipTypeRegistry::findByTypeKey($typeKey);

        return $type?->allows_duplicates ?? false;
    }

    /**
     * Check if a relationship type requires metadata.
     */
    public function requiresMetadata(string $typeKey): bool
    {
        $type = RelationshipTypeRegistry::findByTypeKey($typeKey);

        return $type?->requires_metadata ?? false;
    }

    /**
     * Get the inverse type for a relationship type.
     */
    public function getInverseType(string $typeKey): ?string
    {
        $type = RelationshipTypeRegistry::findByTypeKey($typeKey);

        return $type?->inverse_type;
    }

    /**
     * Validate metadata against a relationship type's schema.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function validateMetadata(string $typeKey, array $metadata): bool
    {
        $type = RelationshipTypeRegistry::findByTypeKey($typeKey);

        if (! $type) {
            throw new \Exception("Relationship type not found: {$typeKey}");
        }

        return $type->validateMetadata($metadata);
    }

    /**
     * Activate a relationship type.
     */
    public function activate(string $typeKey): bool
    {
        $type = RelationshipTypeRegistry::find($typeKey);

        if (! $type) {
            throw new \Exception("Relationship type not found: {$typeKey}");
        }

        return $type->update(['is_active' => true]);
    }

    /**
     * Deactivate a relationship type.
     */
    public function deactivate(string $typeKey): bool
    {
        $type = RelationshipTypeRegistry::find($typeKey);

        if (! $type) {
            throw new \Exception("Relationship type not found: {$typeKey}");
        }

        if ($type->is_system) {
            throw new \Exception("Cannot deactivate system relationship type: {$typeKey}");
        }

        return $type->update(['is_active' => false]);
    }

    /**
     * Update relationship type configuration.
     */
    public function updateConfig(string $typeKey, array $config): bool
    {
        $type = RelationshipTypeRegistry::find($typeKey);

        if (! $type) {
            throw new \Exception("Relationship type not found: {$typeKey}");
        }

        return $type->update(['config' => array_merge($type->config ?? [], $config)]);
    }

    /**
     * Get configuration value for a relationship type.
     */
    public function getConfig(string $typeKey, string $key, mixed $default = null): mixed
    {
        $type = RelationshipTypeRegistry::find($typeKey);

        if (! $type) {
            return $default;
        }

        return $type->getConfigValue($key, $default);
    }

    /**
     * Get all relationship types for a plugin.
     */
    public function getForPlugin(string $pluginId): Collection
    {
        return RelationshipTypeRegistry::forPlugin($pluginId)->get();
    }

    /**
     * Get relationship types in a specific category.
     */
    public function getByCategory(string $category): Collection
    {
        return RelationshipTypeRegistry::inCategory($category)->active()->get();
    }

    /**
     * Bulk register relationship types (useful for plugin installation).
     *
     * @param  array  $types  Array of type definitions
     * @return Collection Collection of created RelationshipTypeRegistry models
     */
    public function bulkRegister(array $types): Collection
    {
        $registered = collect();

        foreach ($types as $typeData) {
            try {
                $relType = $this->register(
                    $typeData['type_key'],
                    $typeData['category'],
                    $typeData['display_name'],
                    $typeData['options'] ?? []
                );
                $registered->push($relType);
            } catch (\Exception $e) {
                Log::error('Failed to register relationship type: '.$e->getMessage(), [
                    'type_key' => $typeData['type_key'] ?? 'unknown',
                ]);
            }
        }

        return $registered;
    }

    /**
     * Clear the relationship type registry cache.
     */
    public function clearCache(): void
    {
        Cache::forget(RelationshipTypeRegistry::CACHE_KEY);
        Cache::forget(RelationshipTypeRegistry::CACHE_KEY.':active');
        Cache::forget(RelationshipTypeRegistry::CACHE_KEY.':by_category');
    }

    /**
     * Validate that a type key exists and is active.
     *
     * @throws \Exception
     */
    public function validate(string $typeKey): bool
    {
        $type = RelationshipTypeRegistry::findByTypeKey($typeKey);

        if (! $type) {
            throw new \Exception("Unknown relationship type: {$typeKey}");
        }

        if (! $type->is_active) {
            throw new \Exception("Relationship type is not active: {$typeKey}");
        }

        return true;
    }

    /**
     * Get available categories.
     */
    public function getCategories(): array
    {
        return config('relationships.categories', [
            'classification' => 'Classification (Tags, Categories)',
            'participation' => 'Participation (People, Groups)',
            'membership' => 'Membership (Groups, Organizations)',
            'reference' => 'Reference (Links, Citations)',
            'containment' => 'Containment (Folders, Storage)',
            'evidence' => 'Evidence (Chain of Custody)',
            'dependency' => 'Dependency (Prerequisites)',
            'assignment' => 'Assignment (Ownership)',
        ]);
    }
}
