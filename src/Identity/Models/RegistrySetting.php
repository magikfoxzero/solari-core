<?php

namespace NewSolari\Core\Identity\Models;

use NewSolari\Core\Entity\BaseEntity;
use NewSolari\Core\Identity\Models\IdentityPartition;
use NewSolari\Core\Identity\Models\IdentityUser;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class RegistrySetting extends BaseEntity
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'registry_settings';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'record_id',
        'key',
        'value',
        'scope',
        'scope_id',
        'partition_id',
        'created_by',
        'updated_by',
    ];

    /**
     * The attributes that should be encrypted.
     *
     * @var array
     */
    protected $encrypted = [
        'value',
    ];

    /**
     * Encrypt sensitive fields without double encryption.
     *
     * @return array
     */
    protected function encryptFields(array $data)
    {
        // Override to prevent double encryption
        // The BaseEntity's setAttribute method will handle encryption
        return $data;
    }

    /**
     * The validation rules for the entity.
     *
     * @var array
     */
    protected $validations = [
        'key' => 'required|string|max:255',
        'value' => 'required|string',
        'scope' => 'required|in:user,partition,system',
        'scope_id' => 'nullable|string',
        'partition_id' => 'nullable|string|exists:identity_partitions,record_id',
        'created_by' => 'nullable|string|exists:identity_users,record_id',
        'updated_by' => 'nullable|string|exists:identity_users,record_id',
    ];

    /**
     * Get the user that created this setting.
     */
    public function createdBy()
    {
        return $this->belongsTo(IdentityUser::class, 'created_by', 'record_id');
    }

    /**
     * Get the user that last updated this setting.
     */
    public function updatedBy()
    {
        return $this->belongsTo(IdentityUser::class, 'updated_by', 'record_id');
    }

    /**
     * Get the partition this setting belongs to.
     */
    public function partition()
    {
        return $this->belongsTo(IdentityPartition::class, 'partition_id', 'record_id');
    }

    /**
     * Check if the current user has permission to access this setting.
     */
    public function checkRegistryPermission(IdentityUser $user, string $action = 'read'): bool
    {
        // System admins can do anything
        \Log::debug('RegistrySetting permission check - system user check', [
            'user_id' => $user->record_id,
            'is_system_user' => $user->is_system_user,
            'setting_scope' => $this->scope,
            'setting_id' => $this->record_id,
        ]);

        if ($user->is_system_user) {
            \Log::debug('RegistrySetting permission granted - system admin access', [
                'user_id' => $user->record_id,
                'setting_id' => $this->record_id,
            ]);

            return true;
        }

        // Debug logging
        \Log::debug('Registry permission check', [
            'user_id' => $user->record_id,
            'user_partition_id' => $user->partition_id,
            'is_system_user' => $user->is_system_user,
            'setting_scope' => $this->scope,
            'setting_scope_id' => $this->scope_id,
            'setting_partition_id' => $this->partition_id,
            'action' => $action,
            'groups_loaded' => $user->relationLoaded('groups'),
            'permissions_loaded' => $user->relationLoaded('permissions'),
        ]);

        // Check scope-based permissions
        switch ($this->scope) {
            case 'user':
                // Users can only access their own user-level settings
                // Partition admins should NOT be able to access other users' settings
                $result = $this->scope_id === $user->record_id;

                \Log::debug('User scope permission result', [
                    'user_id' => $user->record_id,
                    'setting_scope_id' => $this->scope_id,
                    'match' => $result,
                ]);

                return $result;

            case 'partition':
                // For partition-level settings, check if user is in the same partition
                // and is a partition admin, OR if they're a system admin (already checked above)
                if ($this->partition_id === $user->partition_id) {
                    // Load groups if not already loaded
                    if (! $user->relationLoaded('groups')) {
                        $user->load('groups.permissions');
                    }

                    // In test environment, if user is marked as partition admin in test setup
                    // In production, this would check actual group permissions
                    $isPartitionAdmin = $user->isPartitionAdmin($user->partition_id);

                    \Log::debug('Partition scope permission check', [
                        'user_id' => $user->record_id,
                        'partition_id' => $user->partition_id,
                        'is_partition_admin' => $isPartitionAdmin,
                        'groups_count' => $user->groups->count(),
                        'permissions_count' => $user->groups->flatMap->permissions->count(),
                    ]);

                    return $isPartitionAdmin;
                }

                return false;

            case 'system':
                // Only system admins can access system-level settings
                return $user->is_system_user;

            default:
                return false;
        }
    }

    /**
     * Create a new registry setting with validation and permission checking.
     *
     * @return static
     *
     * @throws \Exception
     */
    public static function createWithPermission(array $data, IdentityUser $user)
    {
        // Validate scope and permission
        if (! self::validateScopePermission($data, $user)) {
            self::logFailedOperation('create', $user, $data, null, 'Permission denied: cannot create setting with this scope');
            throw new \Exception('Permission denied: cannot create setting with this scope');
        }

        // Set created_by and updated_by
        $data['created_by'] = $user->record_id;
        $data['updated_by'] = $user->record_id;

        // Set partition_id if not provided (inherit from user)
        if (empty($data['partition_id']) && ! $user->is_system_user) {
            $data['partition_id'] = $user->partition_id;
        }

        try {
            // Check if a setting with the same key+scope already exists (upsert)
            // Look for exact scope_id match first, then fall back to NULL scope_id
            // (PartitionController::create seeds records with scope_id=NULL)
            $existing = static::where('key', $data['key'])
                ->where('scope', $data['scope'])
                ->where(function ($query) use ($data) {
                    $query->where('scope_id', $data['scope_id'] ?? null);
                    if (! empty($data['scope_id'])) {
                        $query->orWhereNull('scope_id');
                    }
                })
                ->first();

            if ($existing) {
                $updateData = [
                    'value' => $data['value'],
                    'updated_by' => $user->record_id,
                ];
                // Fix scope_id if it was NULL (legacy records from partition creation)
                if ($existing->scope_id === null && ! empty($data['scope_id'])) {
                    $updateData['scope_id'] = $data['scope_id'];
                }
                // Fix partition_id if it was stored inconsistently
                if (! empty($data['partition_id']) && $existing->partition_id !== $data['partition_id']) {
                    $updateData['partition_id'] = $data['partition_id'];
                }
                $existing->update($updateData);
                self::logOperation('upsert', $user, $data, $existing->record_id);

                return $existing;
            }

            $setting = static::createWithValidation($data);
            self::logOperation('create', $user, $data, $setting->record_id);

            return $setting;
        } catch (\Exception $e) {
            self::logFailedOperation('create', $user, $data, null, $e->getMessage());
            throw $e;
        }
    }

    /**
     * Update a registry setting with validation and permission checking.
     *
     * @return bool
     *
     * @throws \Exception
     */
    public function updateWithPermission(array $data, IdentityUser $user)
    {
        // Check if user can update this setting
        if (! $this->checkRegistryPermission($user, 'update')) {
            self::logFailedOperation('update', $user, $data, $this->record_id, 'Permission denied');
            throw new \Exception('Permission denied');
        }

        // Validate scope changes
        if (isset($data['scope']) && $data['scope'] !== $this->scope) {
            self::logFailedOperation('update', $user, $data, $this->record_id, 'Cannot change the scope of an existing setting');
            throw new \Exception('Cannot change the scope of an existing setting');
        }

        // Set updated_by
        $data['updated_by'] = $user->record_id;

        try {
            $result = $this->updateWithValidationForUpdate($data);
            self::logOperation('update', $user, $data, $this->record_id);

            return $result;
        } catch (\Exception $e) {
            self::logFailedOperation('update', $user, $data, $this->record_id, $e->getMessage());
            throw $e;
        }
    }

    /**
     * Update the entity with validation for update operations.
     *
     * @return bool
     *
     * @throws ValidationException
     */
    protected function updateWithValidationForUpdate(array $data)
    {
        // For updates, make required fields optional and only validate fields that are present
        $updateValidations = [
            'key' => 'sometimes|string|max:255',
            'value' => 'sometimes|string',
            'scope' => 'sometimes|in:user,partition,system',
            'scope_id' => 'nullable|string',
            'partition_id' => 'nullable|string|exists:identity_partitions,record_id',
            'created_by' => 'nullable|string|exists:identity_users,record_id',
            'updated_by' => 'nullable|string|exists:identity_users,record_id',
        ];

        // Validate the data with update rules
        $validator = \Illuminate\Support\Facades\Validator::make($data, $updateValidations);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        // Encrypt sensitive fields
        $data = $this->encryptFields($data);

        return $this->update($data);
    }

    /**
     * Delete a registry setting with permission checking.
     *
     * @return bool
     *
     * @throws \Exception
     */
    public function deleteWithPermission(IdentityUser $user)
    {
        // Check if user can delete this setting
        if (! $this->checkRegistryPermission($user, 'delete')) {
            self::logFailedOperation('delete', $user, [], $this->record_id, 'Permission denied');
            throw new \Exception('Permission denied');
        }

        try {
            $result = $this->deleteWithValidation();
            self::logOperation('delete', $user, [], $this->record_id);

            return $result;
        } catch (\Exception $e) {
            self::logFailedOperation('delete', $user, [], $this->record_id, $e->getMessage());
            throw $e;
        }
    }

    /**
     * Validate if a user can create a setting with the given scope.
     */
    protected static function validateScopePermission(array $data, IdentityUser $user): bool
    {
        $scope = $data['scope'] ?? null;

        if (! $scope) {
            return false;
        }

        switch ($scope) {
            case 'user':
                // Users can create user-level settings for themselves
                return $data['scope_id'] === $user->record_id;

            case 'partition':
                // Partition admins can create partition-level settings
                return $user->isPartitionAdmin($data['partition_id'] ?? $user->partition_id);

            case 'system':
                // Only system admins can create system-level settings
                return $user->is_system_user;

            default:
                return false;
        }
    }

    /**
     * Get settings for a specific scope.
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public static function getByScope(
        string $scope,
        ?string $scopeId = null,
        ?string $partitionId = null,
        ?IdentityUser $user = null
    ) {
        $query = self::query()->where('scope', $scope);

        if ($scopeId) {
            $query->where('scope_id', $scopeId);
        }

        if ($partitionId) {
            $query->where('partition_id', $partitionId);
        }

        // Apply permission filtering if user is provided
        if ($user) {
            $query->where(function ($q) use ($user, $scope) {
                if ($scope === 'user') {
                    if (! $user->is_system_user) {
                        $q->where('scope_id', $user->record_id);
                    }
                } elseif ($scope === 'partition') {
                    if (! $user->is_system_user) {
                        $q->where('partition_id', $user->partition_id);
                    }
                } elseif ($scope === 'system') {
                    if (! $user->is_system_user) {
                        $q->where('1', '=', '0'); // No results for non-system users
                    }
                }
            });
        }

        return $query;
    }

    /**
     * Log a registry operation.
     */
    protected static function logOperation(
        string $action,
        IdentityUser $user,
        array $data = [],
        ?string $settingId = null
    ): void {
        $logData = [
            'action' => $action,
            'user_id' => $user->record_id,
            'user_type' => $user->is_system_user ? 'system_admin' : 'regular_user',
            'partition_id' => $user->partition_id,
            'setting_id' => $settingId,
            'scope' => $data['scope'] ?? null,
            'key' => $data['key'] ?? null,
            'success' => true,
        ];

        Log::channel('registry')->info('Registry operation', $logData);
    }

    /**
     * Log a failed registry operation.
     */
    protected static function logFailedOperation(
        string $action,
        ?IdentityUser $user,
        array $data = [],
        ?string $settingId = null,
        string $error = 'Permission denied'
    ): void {
        $logData = [
            'action' => $action,
            'user_id' => $user ? $user->record_id : 'unknown',
            'user_type' => $user ? ($user->is_system_user ? 'system_admin' : 'regular_user') : 'unknown',
            'partition_id' => $user ? $user->partition_id : null,
            'setting_id' => $settingId,
            'scope' => $data['scope'] ?? null,
            'key' => $data['key'] ?? null,
            'success' => false,
            'error' => $error,
        ];

        Log::channel('registry')->warning('Failed registry operation', $logData);
    }

    /**
     * Convert the model to its array representation with decrypted values.
     *
     * @return array
     */
    public function toArray()
    {
        $array = parent::toArray();

        // Decrypt encrypted fields for JSON serialization
        foreach ($this->encrypted as $field) {
            if (isset($array[$field])) {
                try {
                    $array[$field] = Crypt::decryptString($array[$field]);
                } catch (\Exception $e) {
                    // If decryption fails, return the original value
                    $array[$field] = $array[$field];
                }
            }
        }

        return $array;
    }
}
