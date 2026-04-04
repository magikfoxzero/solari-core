<?php

namespace NewSolari\Core\Plugin;

use NewSolari\Core\Entity\BaseEntity;
use NewSolari\Core\Identity\Models\IdentityUser;
use NewSolari\Core\Identity\Models\EntityRelationship;
use NewSolari\Folders\Models\Folder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

abstract class MiniAppBase extends PluginBase
{
    /**
     * Data model class for this mini-app
     */
    protected $dataModel;

    /**
     * Whether this mini-app supports public data (partition-wide access)
     */
    protected $supportsPublicData = true;

    /**
     * MiniAppBase constructor
     */
    public function __construct()
    {
        parent::__construct();
        $this->pluginType = 'mini-app';
    }

    /**
     * Get the data model class
     */
    abstract public function getDataModel(): string;

    /**
     * Get data validation rules
     */
    abstract public function getValidationRules(): array;

    /**
     * Get public/private field name
     */
    protected function getPrivacyField(): string
    {
        return 'is_public';
    }

    /**
     * Check if user can access data item.
     * Includes share-based access for entities that use the Shareable trait.
     */
    public function checkDataAccess(BaseEntity $entity, IdentityUser $user, string $action = 'read'): bool
    {
        // System admins can do anything
        if ($user->is_system_user) {
            return true;
        }

        // Must be in same partition
        if ($user->partition_id !== $entity->partition_id) {
            Log::debug('Data access denied: partition mismatch', [
                'plugin' => $this->getId(),
                'user_id' => $user->record_id,
                'entity_id' => $entity->record_id,
                'action' => $action,
                'user_partition' => $user->partition_id,
                'entity_partition' => $entity->partition_id,
            ]);
            return false;
        }

        // Check partition admin status
        if ($user->isPartitionAdmin($entity->partition_id)) {
            return true;
        }

        // Check ownership
        if ($entity->created_by === $user->record_id) {
            return true;
        }

        // For read/view actions, check if data is public
        if ($action === 'read' && $this->supportsPublicData && $entity->{$this->getPrivacyField()}) {
            return true;
        }

        // Check share-based access for entities that support sharing
        if (method_exists($entity, 'userHasShareAccess')) {
            $shareAction = ($action === 'read') ? 'view' : $action;
            if ($entity->userHasShareAccess($user, $shareAction)) {
                return true;
            }
        }

        Log::debug('Data access denied', [
            'plugin' => $this->getId(),
            'user_id' => $user->record_id,
            'entity_id' => $entity->record_id,
            'action' => $action,
            'is_public' => $entity->{$this->getPrivacyField()} ?? false,
            'user_partition' => $user->partition_id,
            'entity_partition' => $entity->partition_id,
        ]);

        return false;
    }

    /**
     * Check if user can change privacy settings for an entity.
     * Only owner, partition admin, or system admin can make records public/private.
     */
    public function canUserChangePrivacy(BaseEntity $entity, IdentityUser $user): bool
    {
        // System admins can do anything
        if ($user->is_system_user) {
            return true;
        }

        // Must be in same partition
        if ($user->partition_id !== $entity->partition_id) {
            return false;
        }

        // Partition admin
        if ($user->isPartitionAdmin($entity->partition_id)) {
            return true;
        }

        // Owner
        return $entity->created_by === $user->record_id;
    }

    /**
     * Validate data before creation/update
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function validateData(array $data, string $operation, IdentityUser $user, ?BaseEntity $existingEntity = null): array
    {
        $rules = $this->getValidationRules();

        // Add source tracking fields for meta-app created entities (e.g., from Investigations)
        // These fields are defined in HasSourcePlugin trait and should be validated for all mini-apps
        $rules['source_plugin'] = 'nullable|string|max:64';
        $rules['source_record_id'] = 'nullable|string|max:36';

        // Set default values before validation
        $dataWithDefaults = $data;

        // Capture the session partition from the request before clearing protected fields.
        // This partition_id comes from the X-Partition-ID header and has been validated
        // by the authentication middleware. It represents the partition the user is
        // currently logged into, which may differ from their default partition.
        $sessionPartitionId = $data['partition_id'] ?? null;

        // Protect system fields from user input
        $protectedFields = ['record_id', 'created_by', 'partition_id', 'updated_by'];
        foreach ($protectedFields as $field) {
            if (isset($dataWithDefaults[$field])) {
                unset($dataWithDefaults[$field]);
            }
        }

        // Prevent non-owner/non-admin users from changing privacy settings
        $privacyField = $this->getPrivacyField();
        if ($operation === 'update' && $existingEntity && array_key_exists($privacyField, $dataWithDefaults)) {
            $newPrivacyValue = (bool) $dataWithDefaults[$privacyField];
            $currentPrivacyValue = (bool) $existingEntity->{$privacyField};

            // Only check permission if privacy value is actually changing
            if ($newPrivacyValue !== $currentPrivacyValue && ! $this->canUserChangePrivacy($existingEntity, $user)) {
                throw new \Exception('Permission denied: only the owner or an administrator can change privacy settings');
            }
        }

        // For update operations, preserve existing values for fields not provided
        if ($operation === 'update' && $existingEntity) {
            foreach ($rules as $field => $rule) {
                // If field is not in the update data, preserve existing value
                if (! array_key_exists($field, $data)) {
                    $dataWithDefaults[$field] = $existingEntity->$field ?? null;
                }
            }
        }

        // Set partition ID: use session partition (from X-Partition-ID header) first,
        // then fall back to user's default partition
        if (empty($dataWithDefaults['partition_id'])) {
            $dataWithDefaults['partition_id'] = $sessionPartitionId ?? $user->partition_id;
        }

        // Set created_by/updated_by if not provided
        if ($operation === 'create' && empty($dataWithDefaults['created_by'])) {
            $dataWithDefaults['created_by'] = $user->record_id;
        }

        $dataWithDefaults['updated_by'] = $user->record_id;

        // Set privacy field default
        if ($this->supportsPublicData && ! isset($dataWithDefaults[$this->getPrivacyField()])) {
            $dataWithDefaults[$this->getPrivacyField()] = false; // Default to private
        }

        // For update operations, make most fields optional and prevent changing immutable fields
        if ($operation === 'update') {
            // These fields cannot be changed after creation - remove from data and rules
            $immutableFields = ['partition_id', 'record_id', 'created_by', 'created_at'];

            foreach ($immutableFields as $field) {
                unset($dataWithDefaults[$field]);
                unset($rules[$field]);
            }

            // Make remaining required fields optional on update
            // Only replace standalone 'required', not 'required_with', 'required_if', etc.
            foreach ($rules as $field => $rule) {
                // Handle both string rules and array rules
                if (is_array($rule)) {
                    // For array rules, replace standalone 'required' with 'sometimes' if present
                    $rules[$field] = array_map(function ($r) {
                        if ($r === 'required') {
                            return 'sometimes';
                        }
                        if (is_string($r) && preg_match('/^required(\||$)/', $r)) {
                            return preg_replace('/^required(\||$)/', 'sometimes$1', $r);
                        }
                        return $r;
                    }, $rule);
                } elseif (is_string($rule)) {
                    // Only replace standalone 'required' at start of rule string, not 'required_with' etc.
                    $rules[$field] = preg_replace('/^required(\||$)/', 'sometimes$1', $rule);
                }
            }
        }

        $validator = Validator::make($dataWithDefaults, $rules);

        if ($validator->fails()) {
            Log::warning('Data validation failed', [
                'plugin' => $this->getId(),
                'errors' => $validator->errors()->toArray(),
                'data' => $dataWithDefaults,
            ]);
            throw new \Illuminate\Validation\ValidationException($validator);
        }

        // Return validated data
        $validatedData = $validator->validated();

        // Convert empty strings to null for nullable fields (except protected fields like created_by, partition_id)
        $protectedFields = ['record_id', 'created_by', 'partition_id', 'updated_by'];
        foreach ($validatedData as $field => $value) {
            if ($value === '' && $this->isFieldNullable($field) && ! in_array($field, $protectedFields)) {
                $validatedData[$field] = null;
            }
        }

        return $validatedData;
    }

    /**
     * Check if a field is nullable based on validation rules
     */
    protected function isFieldNullable(string $field): bool
    {
        $rules = $this->getValidationRules();
        if (! isset($rules[$field])) {
            return false;
        }

        $ruleString = $rules[$field];
        if (is_array($ruleString)) {
            $ruleString = implode('|', $ruleString);
        }

        return strpos($ruleString, 'nullable') !== false;
    }

    /**
     * Get the permission prefix for this plugin.
     *
     * Transforms plugin IDs like 'tasks-mini-app' to permission prefixes like 'tasks'.
     * This ensures consistent permission naming across all plugins.
     */
    public function getPermissionPrefix(): string
    {
        // Transform: tasks-mini-app -> tasks_mini_app -> tasks
        // Transform: private-messages-mini-app -> private_messages_mini_app -> private_messages
        return str_replace(['-', '_mini_app'], ['_', ''], $this->getId());
    }

    /**
     * Get a fully qualified permission name for an action.
     *
     * @param  string  $action  The action (e.g., 'create', 'read', 'update', 'delete')
     * @return string The full permission name (e.g., 'tasks.create')
     */
    public function getPermissionName(string $action): string
    {
        return $this->getPermissionPrefix().'.'.$action;
    }

    /**
     * Get the entity type name for this plugin.
     *
     * Used for relationship queries and entity identification.
     * Transforms plugin IDs like 'people-mini-app' to 'people'.
     */
    public function getEntityType(): string
    {
        return $this->getPermissionPrefix();
    }

    /**
     * Create a new data item
     *
     * @throws \Exception
     */
    public function createDataItem(array $data, IdentityUser $user): BaseEntity
    {
        try {
            // Validate data
            $validatedData = $this->validateData($data, 'create', $user);

            // Check if user has create permission
            $permissionName = $this->getPermissionName('create');
            if (! $this->checkUserPermission($user, $permissionName)) {
                throw new \Exception('Permission denied: cannot create data');
            }

            // Create the entity
            $modelClass = $this->getDataModel();
            $entity = $modelClass::createWithValidation($validatedData);

            Log::info('Data item created', [
                'plugin' => $this->getId(),
                'entity_id' => $entity->record_id,
                'user_id' => $user->record_id,
                'partition_id' => $entity->partition_id,
            ]);

            return $entity;
        } catch (\Exception $e) {
            Log::error('Failed to create data item', [
                'plugin' => $this->getId(),
                'error' => $e->getMessage(),
                'user_id' => $user->record_id,
            ]);
            throw $e;
        }
    }

    /**
     * Update a data item
     *
     * @throws \Exception
     */
    public function updateDataItem(BaseEntity $entity, array $data, IdentityUser $user): bool
    {
        try {
            // Check if user can access this entity
            if (! $this->checkDataAccess($entity, $user, 'update')) {
                throw new \Exception('Permission denied: cannot access this data');
            }

            // Validate data
            $validatedData = $this->validateData($data, 'update', $user, $entity);

            // Update the entity
            $result = $entity->updateWithValidation($validatedData);

            Log::info('Data item updated', [
                'plugin' => $this->getId(),
                'entity_id' => $entity->record_id,
                'user_id' => $user->record_id,
            ]);

            return $result;
        } catch (\Exception $e) {
            Log::error('Failed to update data item', [
                'plugin' => $this->getId(),
                'entity_id' => $entity->record_id,
                'error' => $e->getMessage(),
                'user_id' => $user->record_id,
            ]);
            throw $e;
        }
    }

    /**
     * Delete a data item
     *
     * @throws \Exception
     */
    public function deleteDataItem(BaseEntity $entity, IdentityUser $user): bool
    {
        try {
            // Check if user can access this entity
            if (! $this->checkDataAccess($entity, $user, 'delete')) {
                throw new \Exception('Permission denied: cannot delete this data');
            }

            // deleteWithValidation uses soft delete
            $entity->deleteWithValidation($user->record_id);

            Log::info('Data item deleted', [
                'plugin' => $this->getId(),
                'entity_id' => $entity->record_id,
                'user_id' => $user->record_id,
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error('Failed to delete data item', [
                'plugin' => $this->getId(),
                'entity_id' => $entity->record_id,
                'error' => $e->getMessage(),
                'user_id' => $user->record_id,
            ]);
            throw $e;
        }
    }

    /**
     * Get data items for user with proper access control
     *
     * @param  string|null  $partitionId  Override partition context (from X-Partition-ID header)
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function getDataQuery(IdentityUser $user, array $filters = [], ?string $partitionId = null)
    {
        $modelClass = $this->getDataModel();
        $query = $modelClass::query();

        // Get the table name for later use
        $modelInstance = new $modelClass;
        $tableName = $modelInstance->getTable();

        // Extract partition context from filters if provided (set by controller trait)
        if (isset($filters['_partition_context'])) {
            $partitionId = $filters['_partition_context'];
            unset($filters['_partition_context']); // Remove from filters so it's not applied as a where clause
        }

        // Use the provided partition ID (from request context) or fall back to user's partition
        $effectivePartitionId = $partitionId ?? $user->partition_id;

        // Apply partition filter (system admins bypass partition filtering)
        if (!$user->is_system_user) {
            $query->where('partition_id', $effectivePartitionId);
        }

        // Apply access control based on user permissions
        if (! $user->is_system_user && ! $user->isPartitionAdmin($effectivePartitionId)) {
            // Get the morph class for share lookup
            $morphType = $modelInstance->getMorphClass();

            // Regular users can see: own records + public records + shared records
            $query->where(function ($q) use ($user, $tableName, $morphType) {
                // Own records
                $q->where('created_by', $user->record_id);

                // Public records
                if ($this->supportsPublicData) {
                    $q->orWhere($this->getPrivacyField(), true);
                }

                // Shared records
                $q->orWhereExists(function ($subQuery) use ($user, $tableName, $morphType) {
                    $subQuery->select(DB::raw(1))
                        ->from('record_shares')
                        ->whereColumn('record_shares.shareable_id', "{$tableName}.record_id")
                        ->where('record_shares.shareable_type', $morphType)
                        ->where('record_shares.shared_with_user_id', $user->record_id)
                        ->where('record_shares.deleted', false)
                        ->where(function ($expQ) {
                            $expQ->whereNull('record_shares.expires_at')
                                ->orWhere('record_shares.expires_at', '>', now());
                        });
                });
            });
        }

        // Handle folder_id filter via relationship query
        if (isset($filters['folder_id']) && ! empty($filters['folder_id'])) {
            $folderId = $filters['folder_id'];
            unset($filters['folder_id']);

            // Security: Verify user has access to this folder before filtering
            $folderQuery = Folder::where('record_id', $folderId);
            if (!$user->is_system_user) {
                $folderQuery->where('partition_id', $effectivePartitionId);
            }
            $folder = $folderQuery->first();

            if (! $folder) {
                // Folder doesn't exist or is in a different partition - return empty results
                Log::debug('Folder filter denied: folder not found or wrong partition', [
                    'folder_id' => $folderId,
                    'partition_id' => $effectivePartitionId,
                    'user_id' => $user->record_id,
                ]);
                $query->whereRaw('1 = 0');

                return $query;
            }

            // Check if user can access this folder (public, owned by user, admin, or shared)
            $canAccessFolder = $user->is_system_user
                || $user->isPartitionAdmin($effectivePartitionId)
                || $folder->created_by === $user->record_id
                || $folder->is_public
                || (method_exists($folder, 'userHasShareAccess') && $folder->userHasShareAccess($user, 'view'));

            if (! $canAccessFolder) {
                Log::debug('Folder filter denied: access denied', [
                    'folder_id' => $folderId,
                    'user_id' => $user->record_id,
                ]);
                $query->whereRaw('1 = 0');

                return $query;
            }

            // Get the entity type for this plugin (e.g., 'people', 'notes', 'files')
            // Also get singular form from EntityTypeRegistry for backwards compatibility
            $entityTypePlural = $this->getEntityType();
            $modelClass = $this->getDataModel();
            $entityTypeSingular = null;
            if ($modelClass && class_exists($modelClass)) {
                $registry = \NewSolari\Core\Identity\Models\EntityTypeRegistry::findByModelClass($modelClass);
                if ($registry) {
                    $entityTypeSingular = $registry->type_key;
                }
            }

            // Find all record IDs that have a relationship with this folder
            // Relationships can be stored in either direction, so check both
            // Support both singular ('folder', 'note') and plural ('folders', 'notes') forms
            $relatedIds = EntityRelationship::where('partition_id', $effectivePartitionId)
                ->where(function ($q) use ($folderId, $entityTypePlural, $entityTypeSingular) {
                    // Folder is the source, entity is the target
                    $q->where(function ($sub) use ($folderId, $entityTypePlural, $entityTypeSingular) {
                        $sub->where('source_id', $folderId)
                            ->whereIn('source_type', ['folder', 'folders'])
                            ->where(function ($typeQ) use ($entityTypePlural, $entityTypeSingular) {
                                $types = array_filter([$entityTypePlural, $entityTypeSingular]);
                                $typeQ->whereIn('target_type', $types);
                            });
                    })
                    // Or entity is the source, folder is the target
                    ->orWhere(function ($sub) use ($folderId, $entityTypePlural, $entityTypeSingular) {
                        $sub->where('target_id', $folderId)
                            ->whereIn('target_type', ['folder', 'folders'])
                            ->where(function ($typeQ) use ($entityTypePlural, $entityTypeSingular) {
                                $types = array_filter([$entityTypePlural, $entityTypeSingular]);
                                $typeQ->whereIn('source_type', $types);
                            });
                    });
                })
                ->get()
                ->map(function ($rel) use ($entityTypePlural, $entityTypeSingular) {
                    // Return the entity ID (not the folder ID)
                    // Check both singular and plural forms
                    $isEntitySource = $rel->source_type === $entityTypePlural
                        || $rel->source_type === $entityTypeSingular;
                    return $isEntitySource ? $rel->source_id : $rel->target_id;
                })
                ->unique()
                ->values()
                ->toArray();

            // Filter to only include items with folder relationship
            $query->whereIn('record_id', $relatedIds);
        }

        // Extract and apply export limit if set (for DoS prevention)
        $exportLimit = null;
        if (isset($filters['_export_limit'])) {
            $exportLimit = (int) $filters['_export_limit'];
            unset($filters['_export_limit']);
        }

        // Handle source_plugin filtering:
        // - If 'source_plugin' is explicitly provided, filter by that value
        // - If 'include_all_sources' is true, don't filter by source (show everything)
        // - If 'exclude_meta_app' is true, filter out meta-app items (show only native)
        // - If 'exclude_meta_app' is false, show all items including meta-app created ones
        // - If neither flag is provided, check the partition app setting
        // Note: Only apply if the table has the source_plugin column
        $hasSourcePluginFilter = isset($filters['source_plugin']);
        $includeAllSources = isset($filters['include_all_sources']) && $filters['include_all_sources'];

        // Determine exclude_meta_app value: request > partition setting > default (false)
        if (isset($filters['exclude_meta_app'])) {
            $excludeMetaApp = (bool) $filters['exclude_meta_app'];
        } else {
            // Check partition setting for this app (use effective partition, not user's default)
            $excludeMetaApp = $this->getPartitionExcludeMetaAppSetting($effectivePartitionId);
        }
        unset($filters['include_all_sources']); // Remove from filters
        unset($filters['exclude_meta_app']); // Remove from filters - it's a control flag, not a column

        // Apply source_plugin filter only when exclude_meta_app is true
        if (!$hasSourcePluginFilter && !$includeAllSources && $excludeMetaApp && Schema::hasColumn($tableName, 'source_plugin')) {
            // Hide entities created by meta-apps from native mini-app views
            $query->whereNull('source_plugin');
        }

        // Apply additional filters
        foreach ($filters as $field => $value) {
            if ($field === 'search') {
                // Handle search across multiple fields
                $this->applySearchFilter($query, $value);
            } else {
                $query->where($field, $value);
            }
        }

        // Apply export limit if set (used by export endpoints to prevent DoS)
        if ($exportLimit !== null && $exportLimit > 0) {
            $query->limit($exportLimit);
        }

        return $query;
    }

    /**
     * Apply search filter to query
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     */
    protected function applySearchFilter($query, string $searchTerm): void
    {
        // This should be overridden by specific mini-apps
        // to search relevant fields
    }

    /**
     * Escape LIKE pattern special characters to prevent pattern injection.
     *
     * Users could otherwise use % or _ to manipulate search behavior.
     * This ensures the search term is treated as a literal string.
     *
     * @param string $searchTerm The raw search term from user input
     * @return string The escaped search term safe for LIKE queries
     */
    protected function escapeLikePattern(string $searchTerm): string
    {
        return str_replace(['%', '_'], ['\%', '\_'], $searchTerm);
    }

    /**
     * Get count of data items accessible to user
     */
    public function getDataCount(IdentityUser $user, array $filters = []): int
    {
        return $this->getDataQuery($user, $filters)->count();
    }

    /**
     * Check if mini-app supports public data
     */
    public function supportsPublicData(): bool
    {
        return $this->supportsPublicData;
    }

    /**
     * Set public data support
     */
    public function setSupportsPublicData(bool $supports): void
    {
        $this->supportsPublicData = $supports;
    }

    /**
     * Get the partition's exclude_meta_app setting for this plugin.
     */
    protected function getPartitionExcludeMetaAppSetting(string $partitionId): bool
    {
        try {
            $service = app(\NewSolari\Core\Services\PartitionAppService::class);

            return $service->shouldExcludeMetaApp($partitionId, $this->getId());
        } catch (\Exception $e) {
            // If service is unavailable or error occurs, default to false (show all data)
            Log::debug('Failed to get exclude_meta_app setting, defaulting to false', [
                'plugin' => $this->getId(),
                'partition_id' => $partitionId,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
