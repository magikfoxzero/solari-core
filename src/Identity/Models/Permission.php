<?php

namespace NewSolari\Core\Identity\Models;

use NewSolari\Core\Entity\BaseEntity;
use Illuminate\Support\Str;

class Permission extends BaseEntity
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'permissions';

    /**
     * Boot the model.
     * Ensures UUID generation works correctly for permissions.
     */
    protected static function boot()
    {
        parent::boot();

        // Ensure UUID is generated for record_id
        static::creating(function ($model) {
            if (empty($model->record_id)) {
                $model->record_id = (string) Str::uuid();
            }
        });

        // Handle cleanup when soft deleting a permission
        static::deleting(function ($permission) {
            // Only run this logic on soft delete, not force delete
            if (method_exists($permission, 'isForceDeleting') && $permission->isForceDeleting()) {
                return;
            }

            // Detach all pivot table relationships (belongsToMany use detach)
            $permission->users()->detach();
            $permission->groups()->detach();
        });
    }

    /**
     * Find or create a permission with proper UUID generation.
     *
     * @param  array  $attributes  The attributes to search for
     * @param  array  $values  Additional values for creation
     * @return static
     */
    public static function findOrCreateByName(array $attributes, array $values = [])
    {
        $instance = static::where($attributes)->first();

        if ($instance) {
            return $instance;
        }

        // Merge attributes and values, ensuring record_id is set
        $data = array_merge($attributes, $values);
        if (empty($data['record_id'])) {
            $data['record_id'] = (string) Str::uuid();
        }

        return static::create($data);
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'record_id',
        'partition_id',
        'name',
        'description',
        'permission_type',
        'entity_type',
        'plugin_id',
        'created_at',
        'updated_at',
    ];

    /**
     * The validation rules for the entity.
     *
     * @var array
     */
    protected $validations = [
        'name' => 'required|string|max:255',
        'description' => 'nullable|string',
        'permission_type' => 'required|string|in:View,Create,Update,Delete,ViewAll,Admin,Manage,system,plugin',
        'entity_type' => 'required|string|max:255',
        'partition_id' => 'nullable|string|exists:identity_partitions,record_id',
    ];

    /**
     * Get the users with this permission.
     */
    public function users()
    {
        return $this->belongsToMany(
            IdentityUser::class,
            'identity_user_permissions',
            'permission_id',
            'user_id',
            'record_id',  // Local primary key (Permission)
            'record_id'   // Related primary key (IdentityUser)
        );
    }

    /**
     * Get the groups with this permission.
     */
    public function groups()
    {
        return $this->belongsToMany(
            Group::class,
            'group_permissions',
            'permission_id',
            'group_id',
            'record_id',  // Local primary key (Permission)
            'record_id'   // Related primary key (Group)
        );
    }

    /**
     * Check if a user has this permission.
     *
     * @param  string  $userId
     * @return bool
     */
    public function hasUser($userId)
    {
        return $this->users()->where('user_id', $userId)->exists();
    }

    /**
     * Check if a group has this permission.
     *
     * @param  string  $groupId
     * @return bool
     */
    public function hasGroup($groupId)
    {
        return $this->groups()->where('group_id', $groupId)->exists();
    }
}
