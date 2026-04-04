<?php

namespace NewSolari\Core\Identity\Models;

use NewSolari\Core\Entity\BaseEntity;

class Group extends BaseEntity
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'groups';

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
        'is_active',
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
        'is_active' => 'boolean',
    ];

    /**
     * Boot the model.
     */
    protected static function boot(): void
    {
        parent::boot();

        // Handle cleanup when soft deleting a group
        static::deleting(function ($group) {
            // Only run this logic on soft delete, not force delete
            if (method_exists($group, 'isForceDeleting') && $group->isForceDeleting()) {
                return;
            }

            // Detach all pivot table relationships (belongsToMany use detach)
            $group->users()->detach();
            $group->permissions()->detach();
        });
    }

    /**
     * Get the users in this group.
     */
    public function users()
    {
        return $this->belongsToMany(
            IdentityUser::class,
            'identity_user_groups',
            'group_id',
            'user_id'
        );
    }

    /**
     * Get the permissions for this group.
     */
    public function permissions()
    {
        return $this->belongsToMany(
            Permission::class,
            'group_permissions',
            'group_id',
            'permission_id'
        );
    }

    /**
     * Add a user to the group.
     *
     * @param  string  $userId
     * @return bool
     */
    public function addUser($userId)
    {
        // Use syncWithoutDetaching for atomic operation - avoids race condition
        $changes = $this->users()->syncWithoutDetaching([$userId]);

        return ! empty($changes['attached']);
    }

    /**
     * Remove a user from the group.
     *
     * @param  string  $userId
     * @return bool
     */
    public function removeUser($userId)
    {
        return $this->users()->detach($userId) > 0;
    }

    /**
     * Check if the group has a specific permission.
     *
     * @param  string  $permissionName  The full permission name in entity.action format (e.g., 'notes.read', 'users.create')
     * @return bool
     */
    public function hasPermission(string $permissionName): bool
    {
        return $this->permissions()
            ->where('name', $permissionName)
            ->exists();
    }

    /**
     * Assign a permission to the group.
     *
     * @param  string  $permissionId
     * @return bool
     */
    public function assignPermission($permissionId)
    {
        // Use syncWithoutDetaching for atomic operation - avoids race condition
        $changes = $this->permissions()->syncWithoutDetaching([$permissionId]);

        return ! empty($changes['attached']);
    }

    /**
     * Revoke a permission from the group.
     *
     * @param  string  $permissionId
     * @return bool
     */
    public function revokePermission($permissionId)
    {
        return $this->permissions()->detach($permissionId) > 0;
    }

    /**
     * Update the entity with validation.
     * Override to make required fields optional for updates.
     *
     * @return bool
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function updateWithValidation(array $data)
    {
        // For updates, make required fields optional
        $updateValidations = $this->validations;
        foreach ($updateValidations as $field => $rules) {
            $rules = str_replace('required|', '', $rules);
            $rules = str_replace('required', '', $rules);
            $rules = trim($rules, '|');
            // Handle unique constraint - exclude current record
            if (str_contains($rules, 'unique:')) {
                $rules = preg_replace(
                    '/unique:(\w+)/',
                    'unique:$1,name,'.$this->record_id.',record_id',
                    $rules
                );
            }
            $updateValidations[$field] = $rules;
        }

        // Validate the data with update rules
        if (! empty($updateValidations)) {
            $validator = \Illuminate\Support\Facades\Validator::make($data, $updateValidations);

            if ($validator->fails()) {
                throw new \Illuminate\Validation\ValidationException($validator);
            }
        }

        return $this->update($data);
    }
}
