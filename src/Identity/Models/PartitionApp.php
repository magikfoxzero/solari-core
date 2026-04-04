<?php

namespace NewSolari\Core\Identity\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class PartitionApp extends Model
{
    use HasUuids;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'partition_apps';

    /**
     * The primary key for the model.
     *
     * @var string
     */
    protected $primaryKey = 'id';

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
     * @var array<int, string>
     */
    protected $fillable = [
        'id',
        'partition_id',
        'plugin_id',
        'is_enabled',
        'show_in_ui',
        'show_in_dashboard',
        'exclude_meta_app',
        'admin_only',
        'enabled_by',
        'enabled_at',
        'disabled_at',
        'configuration',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'is_enabled' => 'boolean',
        'show_in_ui' => 'boolean',
        'show_in_dashboard' => 'boolean',
        'exclude_meta_app' => 'boolean',
        'admin_only' => 'boolean',
        'enabled_at' => 'datetime',
        'disabled_at' => 'datetime',
        'configuration' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the partition that owns this app configuration.
     */
    public function partition(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(IdentityPartition::class, 'partition_id', 'record_id');
    }

    /**
     * Get the user who last enabled/disabled this app.
     */
    public function enabledByUser(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(IdentityUser::class, 'enabled_by', 'record_id');
    }

    /**
     * Scope a query to only include enabled apps.
     */
    public function scopeEnabled(Builder $query): Builder
    {
        return $query->where('is_enabled', true);
    }

    /**
     * Scope a query to only include disabled apps.
     */
    public function scopeDisabled(Builder $query): Builder
    {
        return $query->where('is_enabled', false);
    }

    /**
     * Scope a query for a specific partition.
     */
    public function scopeForPartition(Builder $query, string $partitionId): Builder
    {
        return $query->where('partition_id', $partitionId);
    }

    /**
     * Scope a query for a specific plugin.
     */
    public function scopeForPlugin(Builder $query, string $pluginId): Builder
    {
        return $query->where('plugin_id', $pluginId);
    }

    /**
     * Scope a query to only include apps visible in UI.
     */
    public function scopeVisibleInUi(Builder $query): Builder
    {
        return $query->where('show_in_ui', true);
    }

    /**
     * Scope a query to only include apps hidden from UI.
     */
    public function scopeHiddenFromUi(Builder $query): Builder
    {
        return $query->where('show_in_ui', false);
    }

    /**
     * Scope a query to only include apps visible in dashboard.
     */
    public function scopeVisibleInDashboard(Builder $query): Builder
    {
        return $query->where('show_in_dashboard', true);
    }

    /**
     * Scope a query to only include apps hidden from dashboard.
     */
    public function scopeHiddenFromDashboard(Builder $query): Builder
    {
        return $query->where('show_in_dashboard', false);
    }
}
