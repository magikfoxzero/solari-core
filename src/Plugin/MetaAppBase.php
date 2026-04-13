<?php

namespace NewSolari\Core\Plugin;

use NewSolari\Core\Entity\BaseEntity;
use NewSolari\Core\Identity\Contracts\AuthenticatedUserInterface;
use NewSolari\Core\Identity\Models\EntityRelationship;
use NewSolari\Core\Identity\Models\EntityTypeRegistry;
use NewSolari\Folders\Models\Folder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

/**
 * Base class for meta-apps.
 *
 * Meta-apps aggregate, visualize, and contextualize data from multiple mini-apps.
 * Unlike mini-apps which manage their own data (CRUD for Tasks, Notes, People, etc.),
 * meta-apps create a higher-level abstraction layer that links to existing entities.
 *
 * Key features:
 * - Container model (Investigation, Project) that holds references to other entities
 * - Canvas/visualization state storage (positions, layouts, zoom levels)
 * - Links TO existing entities rather than creating new ones
 * - Timeline generation from linked entities with date fields
 * - Relationship graphs for visualization
 * - Share-based access control via Shareable trait
 */
abstract class MetaAppBase extends PluginBase
{
    /**
     * Mini-app dependencies for this meta-app
     */
    protected $miniAppDependencies = [];

    /**
     * Integration logic handler
     */
    protected $integrationLogic;

    /**
     * Whether this meta-app supports public data (partition-wide access)
     */
    protected $supportsPublicData = true;

    /**
     * MetaAppBase constructor
     */
    public function __construct()
    {
        parent::__construct();
        $this->pluginType = 'meta-app';
    }

    /**
     * Get mini-app dependencies
     */
    public function getMiniAppDependencies(): array
    {
        return $this->miniAppDependencies;
    }

    /**
     * Check if all dependencies are available
     */
    public function checkDependencies(array $availablePlugins): bool
    {
        foreach ($this->miniAppDependencies as $dependency) {
            if (! isset($availablePlugins[$dependency])) {
                Log::warning('Missing dependency for meta-app', [
                    'meta_app' => $this->getId(),
                    'missing_dependency' => $dependency,
                ]);

                return false;
            }

            $plugin = $availablePlugins[$dependency];
            if (! $plugin->isEnabled()) {
                Log::warning('Dependency disabled for meta-app', [
                    'meta_app' => $this->getId(),
                    'disabled_dependency' => $dependency,
                ]);

                return false;
            }
        }

        return true;
    }

    /**
     * Get integration routes
     */
    abstract public function getIntegrationRoutes(): array;

    /**
     * Initialize integration logic
     */
    abstract protected function initializeIntegrationLogic();

    /**
     * Get the container model class (e.g., Investigation, Project).
     * This is the main model that holds references to linked entities.
     */
    abstract public function getContainerModel(): string;

    /**
     * Get container data validation rules.
     */
    abstract public function getValidationRules(): array;

    /**
     * Get the list of entity types that can be linked to this meta-app's containers.
     *
     * @return array Array of entity type keys (e.g., ['person', 'place', 'event'])
     */
    abstract public function getLinkableEntityTypes(): array;

    /**
     * Get timeline date fields for each linkable entity type.
     *
     * @return array Associative array of entity_type => [date_field1, date_field2, ...]
     */
    abstract public function getTimelineDateFields(): array;

    /**
     * Get visual configuration for each linkable entity type.
     *
     * @return array Associative array of entity_type => ['icon' => '...', 'color' => '...']
     */
    abstract public function getEntityVisualConfig(): array;

    /**
     * Get public/private field name.
     */
    protected function getPrivacyField(): string
    {
        return 'is_public';
    }

    /**
     * Get the permission prefix for this plugin.
     *
     * Transforms plugin IDs like 'investigations-meta-app' to permission prefixes like 'investigations'.
     */
    public function getPermissionPrefix(): string
    {
        // Transform: investigations-meta-app -> investigations_meta_app -> investigations
        return str_replace(['-', '_meta_app'], ['_', ''], $this->getId());
    }

    /**
     * Get a fully qualified permission name for an action.
     *
     * @param  string  $action  The action (e.g., 'create', 'read', 'update', 'delete')
     * @return string The full permission name (e.g., 'investigations.create')
     */
    public function getPermissionName(string $action): string
    {
        return $this->getPermissionPrefix() . '.' . $action;
    }

    /**
     * Get the entity type name for this plugin.
     * Used for relationship queries and entity identification.
     */
    public function getEntityType(): string
    {
        return $this->getPermissionPrefix();
    }

    /**
     * Check if mini-app supports public data.
     */
    public function supportsPublicData(): bool
    {
        return $this->supportsPublicData;
    }

    /**
     * Get data from multiple mini-apps with proper access control
     */
    public function getIntegratedData(AuthenticatedUserInterface $user, array $miniAppDataRequests): array
    {
        $integratedData = [];
        $pluginManager = app('plugin.manager');

        foreach ($miniAppDataRequests as $miniAppId => $request) {
            if (! isset($this->miniAppDependencies[$miniAppId])) {
                Log::warning('Invalid mini-app requested', [
                    'meta_app' => $this->getId(),
                    'requested_mini_app' => $miniAppId,
                ]);

                continue;
            }

            try {
                $miniApp = $pluginManager->getPlugin($miniAppId);

                if (! $miniApp instanceof MiniAppBase) {
                    Log::warning('Dependency is not a mini-app', [
                        'meta_app' => $this->getId(),
                        'dependency' => $miniAppId,
                        'type' => $miniApp->getType(),
                    ]);

                    continue;
                }

                // Get data from the mini-app with proper access control
                $query = $miniApp->getDataQuery($user, $request['filters'] ?? []);

                // Apply limit with bounds validation (min 1, max 1000)
                if (isset($request['limit'])) {
                    $limit = max(1, min((int) $request['limit'], 1000));
                    $query->limit($limit);
                }

                // Apply offset with validation (must be non-negative)
                if (isset($request['offset'])) {
                    $offset = max(0, (int) $request['offset']);
                    $query->offset($offset);
                }

                $data = $query->get();
                $integratedData[$miniAppId] = $data;

            } catch (\Exception $e) {
                Log::error('Failed to get data from mini-app', [
                    'meta_app' => $this->getId(),
                    'mini_app' => $miniAppId,
                    'error' => $e->getMessage(),
                ]);
                $integratedData[$miniAppId] = [];
            }
        }

        // Apply integration logic
        $processedData = $this->applyIntegrationLogic($integratedData, $user);

        return $processedData;
    }

    /**
     * Apply integration logic to combined data
     */
    protected function applyIntegrationLogic(array $integratedData, AuthenticatedUserInterface $user): array
    {
        // This should be implemented by specific meta-apps
        // to process and combine data from multiple mini-apps
        return $integratedData;
    }

    /**
     * Create a meta-app entity that references mini-app data
     */
    public function createMetaEntity(array $data, AuthenticatedUserInterface $user): BaseEntity
    {
        try {
            // Validate that all referenced mini-app data is accessible to the user
            $this->validateMetaEntityData($data, $user);

            // Create the meta entity
            $modelClass = $this->getDataModel();
            $validatedData = $this->validateMetaData($data);
            $entity = $modelClass::createWithValidation($validatedData);

            // Create relationship records
            $this->createRelationshipRecords($entity, $data, $user);

            Log::info('Meta entity created', [
                'meta_app' => $this->getId(),
                'entity_id' => $entity->record_id,
                'user_id' => $user->record_id,
            ]);

            return $entity;
        } catch (\Exception $e) {
            Log::error('Failed to create meta entity', [
                'meta_app' => $this->getId(),
                'error' => $e->getMessage(),
                'user_id' => $user->record_id,
            ]);
            throw $e;
        }
    }

    /**
     * Validate that all referenced mini-app data is accessible
     *
     * @throws \Exception
     */
    protected function validateMetaEntityData(array $data, AuthenticatedUserInterface $user): void
    {
        $pluginManager = app('plugin.manager');

        foreach ($this->miniAppDependencies as $miniAppId) {
            if (isset($data[$miniAppId])) {
                $miniApp = $pluginManager->getPlugin($miniAppId);

                if ($miniApp instanceof MiniAppBase) {
                    $modelClass = $miniApp->getDataModel();

                    foreach ($data[$miniAppId] as $itemId) {
                        $entity = $modelClass::find($itemId);
                        if (! $entity) {
                            throw new \Exception("Referenced {$miniAppId} data not found: {$itemId}");
                        }

                        if (! $miniApp->checkDataAccess($entity, $user, 'read')) {
                            throw new \Exception("Access denied to referenced {$miniAppId} data: {$itemId}");
                        }
                    }
                }
            }
        }
    }

    /**
     * Validate meta data before creation
     */
    protected function validateMetaData(array $data): array
    {
        // Basic validation - should be overridden by specific meta-apps
        $validatedData = [
            'record_id' => $data['record_id'] ?? \Illuminate\Support\Str::uuid(),
            'partition_id' => $data['partition_id'] ?? auth()->user()->partition_id,
            'created_by' => $data['created_by'] ?? auth()->user()->record_id,
            'updated_by' => $data['updated_by'] ?? auth()->user()->record_id,
            'title' => $data['title'] ?? 'Untitled',
            'description' => $data['description'] ?? '',
        ];

        return $validatedData;
    }

    /**
     * Create relationship records between meta entity and mini-app data
     */
    protected function createRelationshipRecords(BaseEntity $metaEntity, array $data, AuthenticatedUserInterface $user): void
    {
        // This should be implemented by specific meta-apps
        // to create pivot tables or relationship records
    }

    /**
     * Get meta entity with all related mini-app data
     */
    public function getMetaEntityWithRelations(BaseEntity $metaEntity, AuthenticatedUserInterface $user): array
    {
        // Check if user can access this meta entity
        if (! $this->checkDataAccess($metaEntity, $user, 'read')) {
            throw new \Exception('Access denied to meta entity');
        }

        // Get the meta entity data
        $result = [
            'meta' => $metaEntity->toArray(),
            'relations' => [],
        ];

        // Get related mini-app data
        $pluginManager = app('plugin.manager');

        foreach ($this->miniAppDependencies as $miniAppId) {
            $miniApp = $pluginManager->getPlugin($miniAppId);

            if ($miniApp instanceof MiniAppBase) {
                $relationData = $this->getRelatedMiniAppData($metaEntity, $miniApp, $user);
                $result['relations'][$miniAppId] = $relationData;
            }
        }

        return $result;
    }

    /**
     * Get related mini-app data for a meta entity
     */
    protected function getRelatedMiniAppData(BaseEntity $metaEntity, MiniAppBase $miniApp, AuthenticatedUserInterface $user): array
    {
        // This should be implemented by specific meta-apps
        // to query the pivot tables and get related data
        return [];
    }

    /**
     * Check if user can access meta entity.
     * Includes share-based access for entities that use the Shareable trait.
     */
    public function checkDataAccess(BaseEntity $entity, AuthenticatedUserInterface $user, string $action = 'read'): bool
    {
        // System admins can do anything
        if ($user->is_system_user) {
            return true;
        }

        // Must be in same partition
        if ($user->partition_id !== $entity->partition_id) {
            Log::debug('Meta-app data access denied: partition mismatch', [
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

        // Check if user is part of the meta-app team
        if ($this->isUserInMetaAppTeam($entity, $user)) {
            return true;
        }

        Log::debug('Meta-app data access denied', [
            'plugin' => $this->getId(),
            'user_id' => $user->record_id,
            'entity_id' => $entity->record_id,
            'action' => $action,
            'is_public' => $entity->{$this->getPrivacyField()} ?? false,
        ]);

        return false;
    }

    /**
     * Check if user can change privacy settings for an entity.
     * Only owner, partition admin, or system admin can make records public/private.
     */
    public function canUserChangePrivacy(BaseEntity $entity, AuthenticatedUserInterface $user): bool
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
     * Check if user is part of the meta-app team
     */
    protected function isUserInMetaAppTeam(BaseEntity $entity, AuthenticatedUserInterface $user): bool
    {
        // This can be overridden by specific meta-apps
        // to implement team-based access control
        return false;
    }

    /**
     * Get cache key for meta-app data
     *
     * @param  mixed  $id
     */
    protected function getCacheKey(string $scope, $id = null): string
    {
        $key = 'metaapp_'.$this->getId().'_'.$scope;
        if ($id) {
            $key .= '_'.$id;
        }

        return $key;
    }

    /**
     * Cache meta-app data
     *
     * @param  mixed  $id
     * @param  mixed  $data
     */
    protected function cacheData(string $scope, $id, $data, int $ttl = 3600): void
    {
        $cacheKey = $this->getCacheKey($scope, $id);
        Cache::put($cacheKey, $data, $ttl);
    }

    /**
     * Get cached meta-app data
     *
     * @param  mixed  $id
     * @return mixed
     */
    protected function getCachedData(string $scope, $id)
    {
        $cacheKey = $this->getCacheKey($scope, $id);

        return Cache::get($cacheKey);
    }

    /**
     * Clear meta-app scoped cache
     *
     * @param  mixed  $id
     */
    public function clearScopedCache(string $scope, $id = null): void
    {
        $cacheKey = $this->getCacheKey($scope, $id);
        Cache::forget($cacheKey);
    }

    // =========================================================================
    // Container CRUD Operations
    // =========================================================================

    /**
     * Validate container data before creation/update.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function validateContainerData(array $data, string $operation, AuthenticatedUserInterface $user, ?BaseEntity $existingEntity = null): array
    {
        $rules = $this->getValidationRules();
        $dataWithDefaults = $data;

        // Capture the session partition from the request before clearing protected fields.
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

            if ($newPrivacyValue !== $currentPrivacyValue && ! $this->canUserChangePrivacy($existingEntity, $user)) {
                throw new \Exception('Permission denied: only the owner or an administrator can change privacy settings');
            }
        }

        // For update operations, preserve existing values for fields not provided
        if ($operation === 'update' && $existingEntity) {
            foreach ($rules as $field => $rule) {
                if (! array_key_exists($field, $data)) {
                    $dataWithDefaults[$field] = $existingEntity->$field ?? null;
                }
            }
        }

        // Set partition ID
        if (empty($dataWithDefaults['partition_id'])) {
            $dataWithDefaults['partition_id'] = $sessionPartitionId ?? $user->partition_id;
        }

        // Set created_by/updated_by
        if ($operation === 'create' && empty($dataWithDefaults['created_by'])) {
            $dataWithDefaults['created_by'] = $user->record_id;
        }
        $dataWithDefaults['updated_by'] = $user->record_id;

        // Set privacy field default
        if ($this->supportsPublicData && ! isset($dataWithDefaults[$this->getPrivacyField()])) {
            $dataWithDefaults[$this->getPrivacyField()] = false;
        }

        // For update operations, make most fields optional
        if ($operation === 'update') {
            $immutableFields = ['partition_id', 'record_id', 'created_by', 'created_at'];
            foreach ($immutableFields as $field) {
                unset($dataWithDefaults[$field]);
                unset($rules[$field]);
            }

            foreach ($rules as $field => $rule) {
                if (strpos($rule, 'required') !== false) {
                    $rules[$field] = str_replace('required', 'sometimes', $rule);
                }
            }
        }

        $validator = Validator::make($dataWithDefaults, $rules);

        if ($validator->fails()) {
            Log::warning('Meta-app container validation failed', [
                'plugin' => $this->getId(),
                'errors' => $validator->errors()->toArray(),
            ]);
            throw new \Illuminate\Validation\ValidationException($validator);
        }

        return $validator->validated();
    }

    /**
     * Create a new container item.
     *
     * @throws \Exception
     */
    public function createContainerItem(array $data, AuthenticatedUserInterface $user): BaseEntity
    {
        try {
            $validatedData = $this->validateContainerData($data, 'create', $user);

            $permissionName = $this->getPermissionName('create');
            if (! $this->checkUserPermission($user, $permissionName)) {
                throw new \Exception('Permission denied: cannot create container');
            }

            $modelClass = $this->getContainerModel();
            $entity = $modelClass::createWithValidation($validatedData);

            Log::info('Meta-app container created', [
                'plugin' => $this->getId(),
                'entity_id' => $entity->record_id,
                'user_id' => $user->record_id,
                'partition_id' => $entity->partition_id,
            ]);

            return $entity;
        } catch (\Exception $e) {
            Log::error('Failed to create meta-app container', [
                'plugin' => $this->getId(),
                'error' => $e->getMessage(),
                'user_id' => $user->record_id,
            ]);
            throw $e;
        }
    }

    /**
     * Update a container item.
     *
     * @throws \Exception
     */
    public function updateContainerItem(BaseEntity $entity, array $data, AuthenticatedUserInterface $user): bool
    {
        try {
            if (! $this->checkDataAccess($entity, $user, 'update')) {
                throw new \Exception('Permission denied: cannot update this container');
            }

            $validatedData = $this->validateContainerData($data, 'update', $user, $entity);
            $result = $entity->updateWithValidation($validatedData);

            Log::info('Meta-app container updated', [
                'plugin' => $this->getId(),
                'entity_id' => $entity->record_id,
                'user_id' => $user->record_id,
            ]);

            return $result;
        } catch (\Exception $e) {
            Log::error('Failed to update meta-app container', [
                'plugin' => $this->getId(),
                'entity_id' => $entity->record_id,
                'error' => $e->getMessage(),
                'user_id' => $user->record_id,
            ]);
            throw $e;
        }
    }

    /**
     * Delete a container item.
     * Also cascade deletes all mini-app records that were created from this container.
     *
     * @throws \Exception
     */
    public function deleteContainerItem(BaseEntity $entity, AuthenticatedUserInterface $user): bool
    {
        try {
            if (! $this->checkDataAccess($entity, $user, 'delete')) {
                throw new \Exception('Permission denied: cannot delete this container');
            }

            // Cascade delete all mini-app records created from this container
            $deletedCount = $this->deleteSourceLinkedRecords($entity->record_id, $user);

            $entity->deleteWithValidation($user->record_id);

            Log::info('Meta-app container deleted', [
                'plugin' => $this->getId(),
                'entity_id' => $entity->record_id,
                'user_id' => $user->record_id,
                'cascade_deleted_count' => $deletedCount,
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error('Failed to delete meta-app container', [
                'plugin' => $this->getId(),
                'entity_id' => $entity->record_id,
                'error' => $e->getMessage(),
                'user_id' => $user->record_id,
            ]);
            throw $e;
        }
    }

    /**
     * Delete all mini-app records that were created from this meta-app container.
     * Uses source_plugin and source_record_id to identify linked records.
     *
     * @param  string  $containerRecordId  The record ID of the container being deleted
     * @param  AuthenticatedUserInterface  $user  The user performing the delete
     * @return int Number of records deleted
     */
    protected function deleteSourceLinkedRecords(string $containerRecordId, AuthenticatedUserInterface $user): int
    {
        $sourcePlugin = $this->getId();
        $deletedCount = 0;

        Log::info('Starting cascade delete for source-linked records', [
            'source_plugin' => $sourcePlugin,
            'source_record_id' => $containerRecordId,
            'user_id' => $user->record_id,
        ]);

        // List of all mini-app models that support source tracking
        $sourceTrackingModels = [
            \NewSolari\Notes\Models\Note::class,
            \NewSolari\Tasks\Models\Task::class,
            \NewSolari\Events\Models\Event::class,
            \NewSolari\People\Models\Person::class,
            \NewSolari\Entities\Models\Entity::class,
            \NewSolari\Places\Models\Place::class,
            \NewSolari\Files\Models\File::class,
            \NewSolari\Tags\Models\Tag::class,
            \NewSolari\Hypotheses\Models\Hypothesis::class,
            \NewSolari\Motives\Models\Motive::class,
            \NewSolari\InventoryObjects\Models\InventoryObject::class,
            \NewSolari\Budgets\Models\Budget::class,
            \NewSolari\ReferenceMaterials\Models\ReferenceMaterial::class,
            \NewSolari\BlockNotes\Models\BlockNote::class,
            \NewSolari\Folders\Models\Folder::class,
            \NewSolari\Invoices\Models\Invoice::class,
        ];

        foreach ($sourceTrackingModels as $modelClass) {
            if (! class_exists($modelClass)) {
                continue;
            }

            try {
                // Find all records created from this container
                $records = $modelClass::where('source_plugin', $sourcePlugin)
                    ->where('source_record_id', $containerRecordId)
                    ->get();

                foreach ($records as $record) {
                    $record->deleteWithValidation($user->record_id);
                    $deletedCount++;
                }

                if ($records->count() > 0) {
                    Log::info('Cascade deleted source-linked records', [
                        'model' => $modelClass,
                        'source_plugin' => $sourcePlugin,
                        'source_record_id' => $containerRecordId,
                        'count' => $records->count(),
                    ]);
                }
            } catch (\Exception $e) {
                Log::warning('Failed to cascade delete from model', [
                    'model' => $modelClass,
                    'source_plugin' => $sourcePlugin,
                    'source_record_id' => $containerRecordId,
                    'error' => $e->getMessage(),
                ]);
                // Continue with other models even if one fails
            }
        }

        return $deletedCount;
    }

    /**
     * Get container items for user with proper access control.
     *
     * @param  string|null  $partitionId  Override partition context
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function getContainerQuery(AuthenticatedUserInterface $user, array $filters = [], ?string $partitionId = null)
    {
        $modelClass = $this->getContainerModel();
        $query = $modelClass::query();

        if (isset($filters['_partition_context'])) {
            $partitionId = $filters['_partition_context'];
            unset($filters['_partition_context']);
        }

        $effectivePartitionId = $partitionId ?? $user->partition_id;
        if (!$user->is_system_user) {
            $query->where('partition_id', $effectivePartitionId);
        }

        if (! $user->is_system_user && ! $user->isPartitionAdmin($effectivePartitionId)) {
            $modelInstance = new $modelClass;
            $tableName = $modelInstance->getTable();
            $morphType = $modelInstance->getMorphClass();

            $query->where(function ($q) use ($user, $tableName, $morphType) {
                $q->where('created_by', $user->record_id);

                if ($this->supportsPublicData) {
                    $q->orWhere($this->getPrivacyField(), true);
                }

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
                Log::debug('Meta-app folder filter denied: folder not found or wrong partition', [
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
                Log::debug('Meta-app folder filter denied: access denied', [
                    'folder_id' => $folderId,
                    'user_id' => $user->record_id,
                ]);
                $query->whereRaw('1 = 0');

                return $query;
            }

            // Get the entity type for this meta-app (e.g., 'investigations')
            // Also get singular form from EntityTypeRegistry for backwards compatibility
            $entityTypePlural = $this->getEntityType();
            $containerModel = $this->getContainerModel();
            $entityTypeSingular = null;
            if ($containerModel && class_exists($containerModel)) {
                $registry = EntityTypeRegistry::findByModelClass($containerModel);
                if ($registry) {
                    $entityTypeSingular = $registry->type_key;
                }
            }
            // If no registry match, try singular form (remove trailing 's')
            if (! $entityTypeSingular && str_ends_with($entityTypePlural, 's')) {
                $entityTypeSingular = rtrim($entityTypePlural, 's');
            }

            // Find all record IDs that have a relationship with this folder
            // Relationships can be stored in either direction, so check both
            // Support both singular ('folder', 'investigation') and plural ('folders', 'investigations') forms
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

        foreach ($filters as $field => $value) {
            if ($field === 'search') {
                $this->applySearchFilter($query, $value);
            } else {
                $query->where($field, $value);
            }
        }

        return $query;
    }

    /**
     * Apply search filter to query.
     * Should be overridden by specific meta-apps.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     */
    protected function applySearchFilter($query, string $searchTerm): void
    {
        // Override in specific meta-apps
    }

    // =========================================================================
    // Entity Resolution & Timeline Helpers
    // =========================================================================

    /**
     * Find existing entity_relationships between entities already on a container.
     * Used for auto-link prompting when adding new entities.
     *
     * @param  array  $existingEntityIds  Array of ['type' => 'id'] pairs already on container
     * @param  string  $newEntityType  Type of the new entity being added
     * @param  string  $newEntityId  ID of the new entity being added
     * @param  string  $partitionId  Partition ID for filtering
     * @return Collection Collection of EntityRelationship records
     */
    public function findExistingRelationships(
        array $existingEntities,
        string $newEntityType,
        string $newEntityId,
        string $partitionId
    ): Collection {
        if (empty($existingEntities)) {
            return collect();
        }

        $existingIds = array_column($existingEntities, 'id');

        return EntityRelationship::where('partition_id', $partitionId)
            ->where(function ($q) use ($existingIds, $newEntityType, $newEntityId) {
                $q->where(function ($sub) use ($existingIds, $newEntityType, $newEntityId) {
                    $sub->where('source_type', $newEntityType)
                        ->where('source_id', $newEntityId)
                        ->whereIn('target_id', $existingIds);
                })
                ->orWhere(function ($sub) use ($existingIds, $newEntityType, $newEntityId) {
                    $sub->where('target_type', $newEntityType)
                        ->where('target_id', $newEntityId)
                        ->whereIn('source_id', $existingIds);
                });
            })
            ->get();
    }

    /**
     * Resolve an entity by type and ID.
     *
     * @param  string  $entityType  Entity type key (e.g., 'person', 'place')
     * @param  string  $entityId  Entity record ID
     * @return BaseEntity|null The resolved entity or null if not found
     */
    public function resolveEntity(string $entityType, string $entityId): ?BaseEntity
    {
        $registry = EntityTypeRegistry::where('type_key', $entityType)->first();
        if (! $registry || ! $registry->model_class) {
            return null;
        }

        $modelClass = $registry->model_class;
        if (! class_exists($modelClass)) {
            return null;
        }

        return $modelClass::find($entityId);
    }

    /**
     * Get the display label for an entity.
     *
     * @param  string  $entityType  Entity type key
     * @param  BaseEntity  $entity  The entity instance
     * @return string The display label
     */
    public function getEntityLabel(string $entityType, BaseEntity $entity): string
    {
        $labelFields = ['title', 'name', 'subject', 'description', 'record_id'];

        foreach ($labelFields as $field) {
            if (! empty($entity->$field)) {
                return $entity->$field;
            }
        }

        return $entity->record_id ?? 'Unknown';
    }

    /**
     * Extract timeline date from an entity based on configured date fields.
     *
     * @param  string  $entityType  Entity type key
     * @param  BaseEntity  $entity  The entity instance
     * @return array|null Array with 'date' and 'field' keys, or null if no date found
     */
    public function extractTimelineDate(string $entityType, BaseEntity $entity): ?array
    {
        $dateFields = $this->getTimelineDateFields();

        if (! isset($dateFields[$entityType])) {
            return null;
        }

        foreach ($dateFields[$entityType] as $field) {
            if (! empty($entity->$field)) {
                return [
                    'date' => $entity->$field,
                    'field' => $field,
                ];
            }
        }

        return null;
    }

    /**
     * Check if a linked entity is accessible to the user.
     * Used for privacy-aware canvas rendering.
     *
     * @param  string  $entityType  Entity type key
     * @param  string  $entityId  Entity record ID
     * @param  AuthenticatedUserInterface  $user  The user to check access for
     * @return bool True if user can access, false otherwise
     */
    public function canUserAccessLinkedEntity(string $entityType, string $entityId, AuthenticatedUserInterface $user): bool
    {
        $entity = $this->resolveEntity($entityType, $entityId);
        if (! $entity) {
            return false;
        }

        // Get the mini-app for this entity type
        try {
            $pluginManager = app('plugin.manager');
            $registry = EntityTypeRegistry::where('type_key', $entityType)->first();

            // If registry has a plugin_id, use the plugin's access check
            if ($registry && $registry->plugin_id) {
                $plugin = $pluginManager->get($registry->plugin_id);

                if ($plugin instanceof MiniAppBase) {
                    return $plugin->checkDataAccess($entity, $user, 'read');
                }
            }

            // Fallback: direct entity access check when no plugin is registered
            // This handles entity types that haven't been migrated to have plugin_id yet

            // System users can access everything
            if ($user->is_system_user) {
                return true;
            }

            // Must be in same partition
            if (property_exists($entity, 'partition_id') && $entity->partition_id !== $user->partition_id) {
                return false;
            }

            // Partition admins can access everything in their partition
            if (property_exists($entity, 'partition_id') && $user->isPartitionAdmin($entity->partition_id)) {
                return true;
            }

            // Owner can access
            if (property_exists($entity, 'created_by') && $entity->created_by === $user->record_id) {
                return true;
            }

            // Public entities can be accessed by anyone in the same partition
            if (property_exists($entity, 'is_public') && $entity->is_public) {
                return true;
            }

            // Check share-based access
            if (method_exists($entity, 'userHasShareAccess')) {
                if ($entity->userHasShareAccess($user, 'view')) {
                    return true;
                }
            }

            // Default: deny access
            return false;
        } catch (\Exception $e) {
            Log::error('Error checking linked entity access', [
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Get visible nodes for a user on a container.
     * Filters out nodes for entities the user cannot access.
     * Returns node data as arrays with display_label resolved.
     *
     * @param  Collection  $nodes  Collection of node records
     * @param  AuthenticatedUserInterface  $user  The user to check access for
     * @return array Array of node data with access info and display labels
     */
    public function getVisibleNodesForUser(Collection $nodes, AuthenticatedUserInterface $user): array
    {
        return $nodes->map(function ($node) use ($user) {
            $canAccess = $this->canUserAccessLinkedEntity(
                $node->entity_type,
                $node->entity_id,
                $user
            );

            // Convert node to array - handle both Eloquent models and stdClass
            if (method_exists($node, 'toArray')) {
                $nodeData = $node->toArray();
                // Add display_label from accessor (resolves entity label)
                $nodeData['display_label'] = $node->display_label ?? $node->entity_id;
            } else {
                // Handle stdClass (used in tests)
                $nodeData = (array) $node;
                $nodeData['display_label'] = $node->display_label ?? $node->entity_id ?? 'Unknown';
            }

            // Add access control info
            $nodeData['can_access'] = $canAccess;
            $nodeData['display_mode'] = $canAccess ? 'full' : 'restricted';

            // If user can't access, mask sensitive data but keep position for graph layout
            if (! $canAccess) {
                $nodeData['display_label'] = 'Restricted Entity';
                $nodeData['notes'] = null;
                $nodeData['tags'] = [];
            }

            return $nodeData;
        })->values()->toArray();
    }
}
