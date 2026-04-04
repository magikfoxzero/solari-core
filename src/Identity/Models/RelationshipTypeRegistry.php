<?php

namespace NewSolari\Core\Identity\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;

class RelationshipTypeRegistry extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'relationship_type_registry';

    /**
     * The primary key for the model.
     *
     * @var string
     */
    protected $primaryKey = 'type_key';

    /**
     * The "type" of the primary key ID.
     *
     * @var string
     */
    protected $keyType = 'string';

    /**
     * Indicates if the IDs are auto-incrementing.
     *
     * @var bool
     */
    public $incrementing = false;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'type_key',
        'category',
        'display_name',
        'description',
        'inverse_type',
        'allows_duplicates',
        'requires_metadata',
        'metadata_schema',
        'supports_priority',
        'supports_primary',
        'is_active',
        'is_system',
        'plugin_id',
        'config',
        'created_by',
        'updated_by',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'allows_duplicates' => 'boolean',
        'requires_metadata' => 'boolean',
        'metadata_schema' => 'array',
        'supports_priority' => 'boolean',
        'supports_primary' => 'boolean',
        'is_active' => 'boolean',
        'is_system' => 'boolean',
        'config' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Cache key prefix for relationship types.
     *
     * @var string
     */
    const CACHE_KEY = 'relationship_type_registry';

    /**
     * Cache TTL in seconds (1 hour).
     *
     * @var int
     */
    const CACHE_TTL = 3600;

    /**
     * Boot the model.
     */
    protected static function boot()
    {
        parent::boot();

        // Clear cache when relationship types are modified
        static::saved(function () {
            Cache::forget(self::CACHE_KEY);
        });

        static::deleted(function () {
            Cache::forget(self::CACHE_KEY);
        });
    }

    /**
     * Get the inverse relationship type.
     */
    public function inverseType()
    {
        return $this->belongsTo(RelationshipTypeRegistry::class, 'inverse_type', 'type_key');
    }

    /**
     * Scope a query to only include active relationship types.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope a query to only include system relationship types.
     */
    public function scopeSystem(Builder $query): Builder
    {
        return $query->where('is_system', true);
    }

    /**
     * Scope a query to only include custom (non-system) relationship types.
     */
    public function scopeCustom(Builder $query): Builder
    {
        return $query->where('is_system', false);
    }

    /**
     * Scope a query to filter by category.
     */
    public function scopeInCategory(Builder $query, string $category): Builder
    {
        return $query->where('category', $category);
    }

    /**
     * Scope a query to filter by plugin.
     */
    public function scopeForPlugin(Builder $query, string $pluginId): Builder
    {
        return $query->where('plugin_id', $pluginId);
    }

    /**
     * Get the category for this relationship type.
     */
    public function getCategory(): string
    {
        return $this->category;
    }

    /**
     * Get the metadata schema.
     */
    public function getMetadataSchema(): ?array
    {
        return $this->metadata_schema;
    }

    /**
     * Check if this relationship type allows duplicates.
     */
    public function allowsDuplicates(): bool
    {
        return $this->allows_duplicates;
    }

    /**
     * Check if this relationship type requires metadata.
     */
    public function requiresMetadata(): bool
    {
        return $this->requires_metadata;
    }

    /**
     * Check if this relationship type supports priority.
     */
    public function supportsPriority(): bool
    {
        return $this->supports_priority;
    }

    /**
     * Check if this relationship type supports primary designation.
     */
    public function supportsPrimary(): bool
    {
        return $this->supports_primary;
    }

    /**
     * Get the inverse type key.
     */
    public function getInverseType(): ?string
    {
        return $this->inverse_type;
    }

    /**
     * Validate metadata against the schema.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function validateMetadata(array $metadata): bool
    {
        if (! $this->metadata_schema) {
            return true;
        }

        // Convert JSON Schema to Laravel validation rules
        $rules = $this->jsonSchemaToValidationRules($this->metadata_schema);

        if (empty($rules)) {
            return true;
        }

        $validator = Validator::make($metadata, $rules);

        if ($validator->fails()) {
            throw new \Illuminate\Validation\ValidationException($validator);
        }

        return true;
    }

    /**
     * Convert JSON Schema to Laravel validation rules.
     */
    protected function jsonSchemaToValidationRules(array $schema): array
    {
        $rules = [];

        if (! isset($schema['properties'])) {
            return $rules;
        }

        foreach ($schema['properties'] as $field => $definition) {
            $fieldRules = [];

            // Check if field is required
            if (isset($schema['required']) && in_array($field, $schema['required'])) {
                $fieldRules[] = 'required';
            } elseif (isset($definition['required']) && $definition['required']) {
                $fieldRules[] = 'required';
            } else {
                $fieldRules[] = 'nullable';
            }

            // Add type validation
            if (isset($definition['type'])) {
                switch ($definition['type']) {
                    case 'string':
                        $fieldRules[] = 'string';
                        if (isset($definition['format'])) {
                            if ($definition['format'] === 'date-time') {
                                $fieldRules[] = 'date';
                            }
                        }
                        break;
                    case 'integer':
                        $fieldRules[] = 'integer';
                        break;
                    case 'number':
                        $fieldRules[] = 'numeric';
                        break;
                    case 'boolean':
                        $fieldRules[] = 'boolean';
                        break;
                    case 'array':
                        $fieldRules[] = 'array';
                        break;
                }
            }

            $rules[$field] = $fieldRules;
        }

        return $rules;
    }

    /**
     * Get a configuration value by key.
     */
    public function getConfigValue(string $key, mixed $default = null): mixed
    {
        return data_get($this->config, $key, $default);
    }

    /**
     * Set a configuration value by key.
     *
     * @return $this
     */
    public function setConfigValue(string $key, mixed $value): self
    {
        $config = $this->config ?? [];
        data_set($config, $key, $value);
        $this->config = $config;

        return $this;
    }

    /**
     * Get all active relationship types (cached).
     *
     * @return \Illuminate\Support\Collection
     */
    public static function getAllActive()
    {
        return Cache::remember(
            self::CACHE_KEY.':active',
            self::CACHE_TTL,
            fn () => static::active()->get()
        );
    }

    /**
     * Get all relationship types grouped by category (cached).
     *
     * @return \Illuminate\Support\Collection
     */
    public static function getAllByCategory()
    {
        return Cache::remember(
            self::CACHE_KEY.':by_category',
            self::CACHE_TTL,
            fn () => static::active()->get()->groupBy('category')
        );
    }

    /**
     * Find a relationship type by type key (cached).
     *
     * @return static|null
     */
    public static function findByTypeKey(string $typeKey): ?self
    {
        $allTypes = static::getAllActive();

        return $allTypes->firstWhere('type_key', $typeKey);
    }

    /**
     * Register a new relationship type.
     *
     * @return static
     */
    public static function register(array $attributes): self
    {
        return static::create($attributes);
    }

    /**
     * Unregister a relationship type (only if not system type).
     *
     * @throws \Exception
     */
    public function unregister(): ?bool
    {
        if ($this->is_system) {
            throw new \Exception("Cannot unregister system relationship type: {$this->type_key}");
        }

        return $this->delete();
    }
}
