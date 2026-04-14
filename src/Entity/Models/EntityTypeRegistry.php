<?php

namespace NewSolari\Core\Entity\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class EntityTypeRegistry extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'entity_type_registry';

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
        'model_class',
        'table_name',
        'display_name',
        'display_name_plural',
        'icon',
        'category',
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
        'is_active' => 'boolean',
        'is_system' => 'boolean',
        'config' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Cache key prefix for entity types.
     *
     * @var string
     */
    const CACHE_KEY = 'entity_type_registry';

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

        // Clear cache when entity types are modified
        static::saved(function () {
            self::clearAllCaches();
        });

        static::deleted(function () {
            self::clearAllCaches();
        });
    }

    /**
     * Clear all related caches for entity type registry.
     */
    public static function clearAllCaches(): void
    {
        Cache::forget(self::CACHE_KEY);
        Cache::forget(self::CACHE_KEY.':active');
        Cache::forget(self::CACHE_KEY.':by_category');
    }

    /**
     * Scope a query to only include active entity types.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope a query to only include system entity types.
     */
    public function scopeSystem(Builder $query): Builder
    {
        return $query->where('is_system', true);
    }

    /**
     * Scope a query to only include custom (non-system) entity types.
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
     * Get the model class for this entity type.
     */
    public function getModelClass(): string
    {
        return $this->model_class;
    }

    /**
     * Get a new instance of the model class.
     */
    public function getModelInstance(): ?Model
    {
        if (! class_exists($this->model_class)) {
            return null;
        }

        return app($this->model_class);
    }

    /**
     * Check if the model class exists.
     */
    public function modelClassExists(): bool
    {
        return class_exists($this->model_class);
    }

    /**
     * Get the table name for this entity type.
     */
    public function getTableName(): string
    {
        return $this->table_name;
    }

    /**
     * Get the display name (singular or plural).
     */
    public function getDisplayName(bool $plural = false): string
    {
        if ($plural && $this->display_name_plural) {
            return $this->display_name_plural;
        }

        return $this->display_name;
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
     * Get all active entity types (cached).
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
     * Get all entity types grouped by category (cached).
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
     * Find an entity type by type key (cached).
     *
     * @return static|null
     */
    public static function findByTypeKey(string $typeKey): ?self
    {
        $allTypes = static::getAllActive();

        return $allTypes->firstWhere('type_key', $typeKey);
    }

    /**
     * Find an entity type by model class (cached).
     *
     * @return static|null
     */
    public static function findByModelClass(string $modelClass): ?self
    {
        $allTypes = static::getAllActive();

        return $allTypes->firstWhere('model_class', $modelClass);
    }

    /**
     * Find an entity type by table name (cached).
     *
     * @return static|null
     */
    public static function findByTableName(string $tableName): ?self
    {
        $allTypes = static::getAllActive();

        return $allTypes->firstWhere('table_name', $tableName);
    }

    /**
     * Register a new entity type.
     *
     * @return static
     */
    public static function register(array $attributes): self
    {
        return static::create($attributes);
    }

    /**
     * Unregister an entity type (only if not system type).
     *
     * @throws \Exception
     */
    public function unregister(): ?bool
    {
        if ($this->is_system) {
            throw new \Exception("Cannot unregister system entity type: {$this->type_key}");
        }

        return $this->delete();
    }
}
