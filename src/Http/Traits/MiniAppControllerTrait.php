<?php

namespace NewSolari\Core\Http\Traits;

use NewSolari\Core\Constants\ApiConstants;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use NewSolari\Core\Contracts\IdentityUserContract;

/**
 * Trait MiniAppControllerTrait
 *
 * Provides common functionality for MiniApp plugin controllers
 * following DRY principles to reduce code duplication.
 *
 * @requires \NewSolari\Core\Http\BaseController This trait expects to be used
 *           in controllers that extend BaseController or implement getAuthenticatedUser()
 */
trait MiniAppControllerTrait
{
    /**
     * Get the authenticated user from the request.
     * This method must be implemented by the class using this trait.
     */
    abstract protected function getAuthenticatedUser(Request $request): ?IdentityUserContract;

    /**
     * Get relations to be loaded (override in controller if needed)
     */
    protected function getRelations(): array
    {
        return [];
    }

    /**
     * Convert plural plugin name to singular form for response structure
     */
    protected function getSingularForm(string $pluralName): string
    {
        // Handle irregular plurals and special cases
        $singularMap = [
            'hypotheses' => 'hypothesis',
            'motives' => 'motive',
            'entities' => 'entity',
            'people' => 'person',
            'places' => 'place',
            'files' => 'file',
            'folders' => 'folder',
            'notes' => 'note',
            'news' => 'news', // News is same in singular and plural
            'tags' => 'tag',
            'tasks' => 'task',
            'events' => 'event',
            'investigations' => 'investigation',
            'inventory_objects' => 'inventory_object',
            'blocknotes' => 'blocknote',
            'broadcastmessages' => 'broadcast_message',
            'broadcast_messages' => 'broadcast_message',
            'loginbanners' => 'login_banner',
            'login_banners' => 'login_banner',
            'privatemessages' => 'private_message',
            'private_messages' => 'private_message',
            'reference_materials' => 'reference_material',
        ];

        $lowercaseName = strtolower($pluralName);

        // Return mapped singular form if available
        if (isset($singularMap[$lowercaseName])) {
            return $singularMap[$lowercaseName];
        }

        // Default fallback: remove 's' from end
        return rtrim($lowercaseName, 's');
    }

    /**
     * Convert plural plugin name to plural form with underscores for list responses
     */
    protected function getPluralForm(string $pluralName): string
    {
        // Handle special cases that need underscores
        $pluralMap = [
            'broadcastmessages' => 'broadcast_messages',
            'loginbanners' => 'login_banners',
            'privatemessages' => 'private_messages',
        ];

        $lowercaseName = strtolower($pluralName);

        // Return mapped plural form if available
        if (isset($pluralMap[$lowercaseName])) {
            return $pluralMap[$lowercaseName];
        }

        // Default: return as-is
        return $lowercaseName;
    }

    /**
     * Get the permission base name from plugin ID
     *
     * Converts plugin IDs like 'blocknotes-mini-app' to 'blocknotes'
     * for permission names like 'blocknotes.read'
     */
    protected function getPermissionBaseName(object $plugin): string
    {
        // Plugin ID map for special cases
        $permissionMap = [
            'blocknotes-mini-app' => 'blocknotes',
            'inventory-objects-mini-app' => 'inventory_objects',
            'entities-mini-app' => 'entities',
            'people-mini-app' => 'people',
            'events-mini-app' => 'events',
            'files-mini-app' => 'files',
            'folders-mini-app' => 'folders',
            'notes-mini-app' => 'notes',
            'tags-mini-app' => 'tags',
            'tasks-mini-app' => 'tasks',
            'hypotheses-mini-app' => 'hypotheses',
            'motives-mini-app' => 'motives',
            'places-mini-app' => 'places',
            'investigations-meta-app' => 'investigations',
            'broadcast-messages-mini-app' => 'broadcast_messages',
            'login-banners-mini-app' => 'login_banners',
            'private-messages-mini-app' => 'private_messages',
            'reference-materials-mini-app' => 'reference_materials',
        ];

        $pluginId = $plugin->getId();

        // Return mapped permission base name if available
        if (isset($permissionMap[$pluginId])) {
            return $permissionMap[$pluginId];
        }

        // Default: try to extract from plugin ID by removing '-mini-app' or '-meta-app' suffix
        $baseName = preg_replace('/-(mini|meta|standalone)-app$/', '', $pluginId);

        // Convert hyphens to underscores for consistency
        return str_replace('-', '_', $baseName);
    }

    /**
     * Standard index implementation
     */
    protected function indexWithPlugin(Request $request, object $plugin, string $queryMethod, string $permissionAction = 'read'): JsonResponse
    {
        try {
            $user = $this->getAuthenticatedUser($request);
            if (! $user || ! $user instanceof IdentityUserContract) {
                return $this->errorResponse('Authentication required', 401);
            }

            $user->load('permissions', 'groups.permissions');

            $permissionName = $this->getPermissionBaseName($plugin).'.'.$permissionAction;
            if (! $plugin->checkUserPermission($user, $permissionName)) {
                return $this->errorResponse('Permission denied', 403);
            }

            // Exclude internal request attributes that should not be used as filters
            $filters = $request->except(['per_page', 'page', 'partition_id', 'authenticated_user']);

            // Add partition context from request header for proper scoping
            $partitionId = $this->getPartitionId($request);
            if ($partitionId) {
                $filters['_partition_context'] = $partitionId;
            }

            $query = $plugin->$queryMethod($user, $filters, ! empty($this->getRelations()));

            // Validate per_page with max limit to prevent resource exhaustion
            $perPage = min((int) $request->get('per_page', ApiConstants::PAGINATION_DEFAULT), ApiConstants::PAGINATION_MAX);
            $perPage = max($perPage, ApiConstants::PAGINATION_MIN); // Ensure at least 1
            $items = $query->paginate($perPage);

            $pluginName = strtolower($plugin->getName());
            $itemsKey = $this->getPluralForm($pluginName);

            return $this->successResponse([
                $itemsKey => $items->items(),
                'pagination' => [
                    'total' => $items->total(),
                    'per_page' => $items->perPage(),
                    'current_page' => $items->currentPage(),
                    'last_page' => $items->lastPage(),
                    'from' => $items->firstItem(),
                    'to' => $items->lastItem(),
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Index failed', ['plugin' => $plugin->getId(), 'error' => $e->getMessage()]);

            return $this->errorResponse('Failed to list items', 500);
        }
    }

    /**
     * Standard store implementation
     */
    protected function storeWithPlugin(Request $request, object $plugin, string $createMethod, string $permissionAction = 'create'): JsonResponse
    {
        try {
            $user = $this->getAuthenticatedUser($request);
            if (! $user || ! $user instanceof IdentityUserContract) {
                return $this->errorResponse('Authentication required', 401);
            }

            $user->load('permissions', 'groups.permissions');

            $permissionName = $this->getPermissionBaseName($plugin).'.'.$permissionAction;
            if (! $plugin->checkUserPermission($user, $permissionName)) {
                return $this->errorResponse('Permission denied', 403);
            }

            // Use validated() for FormRequest, all() for regular Request
            $data = $request instanceof FormRequest ? $request->validated() : $request->all();
            $item = $plugin->$createMethod($data, $user);

            $pluginName = strtolower($plugin->getName());
            // Handle irregular plurals and special cases
            $itemKey = $this->getSingularForm($pluginName);

            // Load relations if needed
            if (method_exists($this, 'getRelations') && ! empty($this->getRelations())) {
                $relations = $this->getRelations();
                // Only load relations that aren't already loaded
                $unloadedRelations = array_filter($relations, function ($relation) use ($item) {
                    return ! $item->relationLoaded($relation);
                });
                if (! empty($unloadedRelations)) {
                    $item->load($unloadedRelations);
                }
            }

            return $this->successResponse([
                $itemKey => $this->formatItemWithRelations($item),
                'message' => ucfirst(str_replace('_', ' ', $itemKey)).' created successfully',
            ], 201);

        } catch (\Illuminate\Validation\ValidationException $e) {
            $errorMessage = 'Validation failed: '.implode(', ', array_keys($e->errors()));

            return $this->errorResponse($errorMessage, 422, ['errors' => $e->errors()]);
        } catch (\Exception $e) {
            Log::error('Store failed', ['plugin' => $plugin->getId(), 'error' => $e->getMessage()]);

            return $this->errorResponse('Failed to create item', 500);
        }
    }

    /**
     * Standard show implementation
     */
    protected function showWithPlugin(Request $request, string $id, object $plugin, string $permissionAction = 'read'): JsonResponse
    {
        try {
            $user = $this->getAuthenticatedUser($request);
            if (! $user || ! $user instanceof IdentityUserContract) {
                return $this->errorResponse('Authentication required', 401);
            }

            $model = $plugin->getDataModel();
            $item = $model::find($id);

            if (! $item) {
                return $this->errorResponse('Item not found', 404);
            }

            if (! $plugin->checkDataAccess($item, $user, $permissionAction)) {
                return $this->errorResponse('Permission denied', 403);
            }

            $pluginName = strtolower($plugin->getName());
            $itemKey = $this->getSingularForm($pluginName);

            return $this->successResponse([$itemKey => $item->toArray()]);

        } catch (\Exception $e) {
            Log::error('Show failed', ['plugin' => $plugin->getId(), 'id' => $id, 'error' => $e->getMessage()]);

            return $this->errorResponse('Failed to get item', 500);
        }
    }

    /**
     * Standard update implementation
     */
    protected function updateWithPlugin(Request $request, string $id, object $plugin, string $updateMethod, string $permissionAction = 'update'): JsonResponse
    {
        try {
            $user = $this->getAuthenticatedUser($request);
            if (! $user || ! $user instanceof IdentityUserContract) {
                return $this->errorResponse('Authentication required', 401);
            }

            $model = $plugin->getDataModel();
            $item = $model::find($id);

            if (! $item) {
                return $this->errorResponse('Item not found', 404);
            }

            if (! $plugin->checkDataAccess($item, $user, $permissionAction)) {
                return $this->errorResponse('Permission denied', 403);
            }

            // Use validated() for FormRequest, all() for regular Request
            $data = $request instanceof FormRequest ? $request->validated() : $request->all();
            $result = $plugin->$updateMethod($item, $data, $user);

            if (! $result) {
                return $this->errorResponse('Failed to update item', 500);
            }

            $pluginName = strtolower($plugin->getName());
            $itemKey = $this->getSingularForm($pluginName);

            return $this->successResponse([
                $itemKey => $item->fresh()->toArray(),
                'message' => ucfirst(str_replace('_', ' ', $itemKey)).' updated successfully',
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            $errorMessage = 'Validation failed: '.implode(', ', array_keys($e->errors()));

            return $this->errorResponse($errorMessage, 422, ['errors' => $e->errors()]);
        } catch (\Exception $e) {
            Log::error('Update failed', ['plugin' => $plugin->getId(), 'id' => $id, 'error' => $e->getMessage()]);

            return $this->errorResponse('Failed to update item', 500);
        }
    }

    /**
     * Standard destroy implementation
     */
    protected function destroyWithPlugin(Request $request, string $id, object $plugin, string $permissionAction = 'delete'): JsonResponse
    {
        try {
            $user = $this->getAuthenticatedUser($request);
            if (! $user || ! $user instanceof IdentityUserContract) {
                return $this->errorResponse('Authentication required', 401);
            }

            $model = $plugin->getDataModel();
            $item = $model::find($id);

            if (! $item) {
                return $this->errorResponse('Item not found', 404);
            }

            if (! $plugin->checkDataAccess($item, $user, $permissionAction)) {
                return $this->errorResponse('Permission denied', 403);
            }

            $result = $plugin->deleteDataItem($item, $user);

            if (! $result) {
                return $this->errorResponse('Failed to delete item', 500);
            }

            return $this->successResponse(['message' => 'Item deleted successfully']);

        } catch (\Exception $e) {
            Log::error('Delete failed', ['plugin' => $plugin->getId(), 'id' => $id, 'error' => $e->getMessage()]);

            return $this->errorResponse('Failed to delete item', 500);
        }
    }

    /**
     * Escape special characters for SQL LIKE patterns.
     * Prevents LIKE pattern injection attacks.
     */
    protected function escapeLikePattern(string $value): string
    {
        return str_replace(
            ['\\', '%', '_'],
            ['\\\\', '\\%', '\\_'],
            $value
        );
    }

    /**
     * Standard search implementation
     */
    protected function searchWithPlugin(Request $request, object $plugin, string $queryMethod, string $permissionAction = 'read'): JsonResponse
    {
        try {
            $user = $this->getAuthenticatedUser($request);
            if (! $user || ! $user instanceof IdentityUserContract) {
                return $this->errorResponse('Authentication required', 401);
            }

            $user->load('permissions', 'groups.permissions');

            $searchTerm = $request->get('q', '');
            // Exclude internal request attributes that should not be used as filters
            $filters = $request->except(['q', 'per_page', 'page', 'partition_id', 'authenticated_user']);

            if (empty($searchTerm)) {
                return $this->errorResponse('Search term is required', 400);
            }

            // Validate search term max length to prevent resource exhaustion
            if (strlen($searchTerm) > ApiConstants::SEARCH_TERM_MAX_LENGTH) {
                return $this->errorResponse('Search term too long (max '.ApiConstants::SEARCH_TERM_MAX_LENGTH.' characters)', 400);
            }

            // Sanitize search term to prevent LIKE pattern injection
            $sanitizedSearchTerm = $this->escapeLikePattern($searchTerm);

            // Add partition context from request header for proper scoping
            $partitionId = $this->getPartitionId($request);
            if ($partitionId) {
                $filters['_partition_context'] = $partitionId;
            }

            $query = $plugin->$queryMethod($user, $filters, true);
            $plugin->applySearchFilter($query, $sanitizedSearchTerm);

            // Validate per_page with max limit to prevent resource exhaustion
            $perPage = min((int) $request->get('per_page', ApiConstants::PAGINATION_DEFAULT), ApiConstants::PAGINATION_MAX);
            $perPage = max($perPage, ApiConstants::PAGINATION_MIN); // Ensure at least 1
            $results = $query->paginate($perPage);

            // Use resource name instead of generic 'results' for consistency with index endpoints
            $pluginName = strtolower($plugin->getName());
            $resourceKey = $this->getPluralForm($pluginName);

            return $this->successResponse([
                $resourceKey => $results->items(),
                'pagination' => [
                    'total' => $results->total(),
                    'per_page' => $results->perPage(),
                    'current_page' => $results->currentPage(),
                    'last_page' => $results->lastPage(),
                    'from' => $results->firstItem(),
                    'to' => $results->lastItem(),
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Search failed', ['plugin' => $plugin->getId(), 'error' => $e->getMessage()]);

            return $this->errorResponse('Search failed', 500);
        }
    }

    /**
     * Standard export implementation
     * Enforces row limits to prevent DoS attacks via large exports.
     */
    protected function exportWithPlugin(Request $request, object $plugin, string $exportMethod, string $permissionAction = 'export'): JsonResponse
    {
        try {
            $user = $this->getAuthenticatedUser($request);
            if (! $user || ! $user instanceof IdentityUserContract) {
                return $this->errorResponse('Authentication required', 401);
            }

            $user->load('permissions', 'groups.permissions');

            $permissionName = $this->getPermissionBaseName($plugin).'.'.$permissionAction;
            if (! $plugin->checkUserPermission($user, $permissionName)) {
                return $this->errorResponse('Permission denied', 403);
            }

            $format = $request->get('format', 'json');

            // Validate export format
            $allowedFormats = ['json', 'csv', 'excel'];
            if (! in_array($format, $allowedFormats)) {
                return $this->errorResponse('Invalid export format. Allowed formats: '.implode(', ', $allowedFormats), 400);
            }

            // Exclude internal request attributes that should not be used as filters
            $filters = $request->except(['format', 'partition_id', 'authenticated_user']);

            // Add row limit to filters to prevent DoS via large exports
            $filters['_export_limit'] = ApiConstants::EXPORT_MAX_ROWS;

            $exportData = $plugin->$exportMethod($user, $filters, $format);

            // Check if export was truncated
            $rowCount = is_array($exportData) ? count($exportData) : 0;
            $wasTruncated = $rowCount >= ApiConstants::EXPORT_MAX_ROWS;

            $response = [
                'export' => $exportData,
                'message' => ucfirst($plugin->getName()).' data exported successfully',
                'row_count' => $rowCount,
            ];

            if ($wasTruncated) {
                $response['warning'] = 'Export was limited to '.ApiConstants::EXPORT_MAX_ROWS.' rows. Use filters to narrow your results.';
            }

            return $this->successResponse($response);
        } catch (\Exception $e) {
            Log::error('Export failed', ['plugin' => $plugin->getId(), 'error' => $e->getMessage()]);

            return $this->errorResponse('Failed to export', 500);
        }
    }

    /**
     * Format item with relations for response
     * This ensures relations are properly included in the response
     */
    protected function formatItemWithRelations($item): array
    {
        return $item->toArray();
    }

    /**
     * Standard statistics implementation
     */
    protected function statisticsWithPlugin(Request $request, object $plugin, string $permissionAction = 'read'): JsonResponse
    {
        try {
            $user = $this->getAuthenticatedUser($request);
            if (! $user || ! $user instanceof IdentityUserContract) {
                return $this->errorResponse('Authentication required', 401);
            }

            $user->load('permissions', 'groups.permissions');

            $permissionName = $this->getPermissionBaseName($plugin).'.'.$permissionAction;
            if (! $plugin->checkUserPermission($user, $permissionName)) {
                return $this->errorResponse('Permission denied', 403);
            }

            $stats = $plugin->getStatistics($user);

            return $this->successResponse(['statistics' => $stats]);
        } catch (\Exception $e) {
            Log::error('Statistics failed', ['plugin' => $plugin->getId(), 'error' => $e->getMessage()]);

            return $this->errorResponse('Failed to get statistics', 500);
        }
    }
}
