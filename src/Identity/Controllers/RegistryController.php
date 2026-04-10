<?php

namespace NewSolari\Core\Identity\Controllers;

use NewSolari\Core\Http\BaseController;

use NewSolari\Core\Identity\Models\RegistrySetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class RegistryController extends BaseController
{
    public function index(Request $request)
    {
        try {
            $user = $this->getAuthenticatedUser($request);
            $partitionId = $this->getPartitionId($request);

            // Load groups.permissions for partition admin check
            if (! $user->is_system_user) {
                $user->load('groups.permissions');
            }

            $this->logRequest($request, 'list', 'registry_settings');

            // Get all settings the user has access to
            $settings = RegistrySetting::query()
                ->where(function ($query) use ($user, $partitionId) {
                    // User-level settings (only their own)
                    $query->where(function ($q) use ($user) {
                        $q->where('scope', 'user')
                            ->where('scope_id', $user->record_id);
                    });

                    // Partition-level settings (only for partition admins or system admins)
                    if ($user->is_system_user || ($user->isPartitionAdmin($partitionId))) {
                        $query->orWhere(function ($q) use ($partitionId) {
                            $q->where('scope', 'partition')
                                ->where('partition_id', $partitionId);
                        });
                    }

                    // System-level settings (only for system admins)
                    if ($user->is_system_user) {
                        $query->orWhere('scope', 'system');
                    }
                })
                ->orderBy('scope')
                ->orderBy('key')
                ->get();

            return $this->successResponse($settings);

        } catch (\Exception $e) {
            Log::error('Registry index error', [
                'error' => $e->getMessage(),
                'user_id' => Auth::id(),
                'request_path' => $request->path(),
            ]);

            return $this->errorResponse('Failed to list registry settings', 500);
        }
    }

    public function show(Request $request, string $id)
    {
        try {
            $user = $this->getAuthenticatedUser($request);
            $partitionId = $this->getPartitionId($request) ?? $user->partition_id;

            $this->logRequest($request, 'show', 'registry_settings');

            // Find setting by ID first
            $setting = RegistrySetting::where('record_id', $id)->first();

            if (! $setting) {
                return $this->errorResponse('Setting not found', 404);
            }

            // Load user's groups and permissions for partition admin check
            if (! $user->is_system_user && ! $user->relationLoaded('groups')) {
                $user->load('groups.permissions');
            }

            // Check permission using RegistrySetting's own permission logic
            Log::debug('RegistryController permission check before checkRegistryPermission', [
                'user_id' => $user->record_id,
                'username' => $user->username,
                'is_system_user' => $user->is_system_user,
                'setting_id' => $setting->record_id,
                'setting_scope' => $setting->scope,
                'setting_scope_id' => $setting->scope_id,
                'user_record_id' => $user->record_id,
                'match_expected' => $setting->scope_id === $user->record_id,
            ]);

            if (! $setting->checkRegistryPermission($user, 'read')) {
                Log::debug('RegistryController permission denied', [
                    'user_id' => $user->record_id,
                    'setting_id' => $setting->record_id,
                ]);

                return $this->errorResponse('Permission denied', 403);
            }

            Log::debug('RegistryController permission granted', [
                'user_id' => $user->record_id,
                'setting_id' => $setting->record_id,
            ]);

            return $this->successResponse($setting);

        } catch (\Exception $e) {
            Log::error('Registry show error', [
                'error' => $e->getMessage(),
                'user_id' => Auth::id(),
                'request_path' => $request->path(),
            ]);

            return $this->errorResponse('Failed to get registry setting', 500);
        }
    }

    public function store(Request $request)
    {
        try {
            $user = $this->getAuthenticatedUser($request);
            $partitionId = $this->getPartitionId($request);

            $this->logRequest($request, 'create', 'registry_settings');

            // Validate request data
            $validated = $request->validate([
                'key' => 'required|string|max:255',
                'value' => 'required|string|max:65535',
                'scope' => 'required|in:user,partition,system',
                'scope_id' => 'nullable|string',
                'partition_id' => 'nullable|string',
            ]);

            // Set default partition_id
            if (empty($validated['partition_id'])) {
                $validated['partition_id'] = $partitionId;
            }

            // Set default scope_id based on scope
            if (empty($validated['scope_id'])) {
                if ($validated['scope'] === 'user') {
                    $validated['scope_id'] = $user->record_id;
                } elseif ($validated['scope'] === 'partition') {
                    // Use the resolved partition_id so body-provided partition_id
                    // takes precedence over the header (important for system admins
                    // managing a partition different from their own)
                    $validated['scope_id'] = $validated['partition_id'];
                }
                // System scope doesn't need scope_id
            }

            // Check if trying to create partition registration as enabled when system registration is disabled
            if ($validated['key'] === 'partition.registration.enabled' &&
                ($validated['value'] === 'true' || $validated['value'] === true)) {
                $systemSetting = RegistrySetting::where('scope', 'system')
                    ->where('key', 'system.registration.enabled')
                    ->first();

                if ($systemSetting && ($systemSetting->value === 'false' || $systemSetting->value === false)) {
                    return $this->errorResponse(
                        'Cannot enable partition registration while system registration is disabled',
                        400
                    );
                }
            }

            $setting = RegistrySetting::createWithPermission($validated, $user);

            return $this->successResponse($setting, 201);

        } catch (ValidationException $e) {
            return $this->errorResponse('Validation failed', 422);
        } catch (\Exception $e) {
            Log::error('Registry store error', [
                'error' => $e->getMessage(),
                'user_id' => Auth::id(),
                'request_path' => $request->path(),
            ]);

            return $this->errorResponse('Failed to create registry setting', 500);
        }
    }

    public function update(Request $request, string $id)
    {
        try {
            $user = $this->getAuthenticatedUser($request);
            $partitionId = $this->getPartitionId($request) ?? $user->partition_id;

            $this->logRequest($request, 'update', 'registry_settings');

            // Find setting by ID first
            $setting = RegistrySetting::where('record_id', $id)->first();

            if (! $setting) {
                return $this->errorResponse('Setting not found', 404);
            }

            // Load user's groups and permissions for permission check
            if (! $user->is_system_user && ! $user->relationLoaded('groups')) {
                $user->load('groups.permissions');
            }

            // Check permission using RegistrySetting's own permission logic
            if (! $setting->checkRegistryPermission($user, 'update')) {
                return $this->errorResponse('Permission denied', 403);
            }

            // Validate request data
            $validated = $request->validate([
                'value' => 'required|string|min:1|max:65535',
                'key' => 'sometimes|string|max:255',
                'scope' => 'sometimes|string|in:user,partition,system',
            ]);

            // Check if trying to enable partition registration when system registration is disabled
            if ($setting->key === 'partition.registration.enabled' &&
                ($validated['value'] === 'true' || $validated['value'] === true)) {
                $systemSetting = RegistrySetting::where('scope', 'system')
                    ->where('key', 'system.registration.enabled')
                    ->first();

                if ($systemSetting && ($systemSetting->value === 'false' || $systemSetting->value === false)) {
                    return $this->errorResponse(
                        'Cannot enable partition registration while system registration is disabled',
                        400
                    );
                }
            }

            try {
                $setting->updateWithPermission($validated, $user);

                return $this->successResponse($setting);
            } catch (\Exception $e) {
                if (str_contains($e->getMessage(), 'Cannot change the scope of an existing setting')) {
                    return $this->errorResponse('Cannot change the scope of an existing setting', 400);
                }
                throw $e;
            }

        } catch (ValidationException $e) {
            return $this->errorResponse('Validation failed', 422);
        } catch (\Exception $e) {
            Log::error('Registry update error', [
                'error' => $e->getMessage(),
                'user_id' => Auth::id(),
                'request_path' => $request->path(),
            ]);

            return $this->errorResponse('Failed to update registry setting', 500);
        }
    }

    public function destroy(Request $request, string $id)
    {
        try {
            $user = $this->getAuthenticatedUser($request);
            $partitionId = $this->getPartitionId($request) ?? $user->partition_id;

            $this->logRequest($request, 'delete', 'registry_settings');

            // Find setting by ID first
            $setting = RegistrySetting::where('record_id', $id)->first();

            if (! $setting) {
                return $this->errorResponse('Setting not found', 404);
            }

            // Load user's groups and permissions for permission check
            if (! $user->is_system_user && ! $user->relationLoaded('groups')) {
                $user->load('groups.permissions');
            }

            // Check permission using RegistrySetting's own permission logic
            if (! $setting->checkRegistryPermission($user, 'delete')) {
                return $this->errorResponse('Permission denied', 403);
            }

            $setting->deleteWithPermission($user);

            return $this->successResponse(['message' => 'Setting deleted successfully']);

        } catch (\Exception $e) {
            Log::error('Registry destroy error', [
                'error' => $e->getMessage(),
                'user_id' => Auth::id(),
                'request_path' => $request->path(),
            ]);

            return $this->errorResponse('Failed to delete registry setting', 500);
        }
    }

    public function getUserSettings(Request $request)
    {
        try {
            $user = $this->getAuthenticatedUser($request);

            $this->logRequest($request, 'list_user', 'registry_settings');

            $settings = RegistrySetting::getByScope('user', $user->record_id)
                ->orderBy('key')
                ->get();

            return $this->successResponse($settings);

        } catch (\Exception $e) {
            Log::error('Registry user settings error', [
                'error' => $e->getMessage(),
                'user_id' => Auth::id(),
                'request_path' => $request->path(),
            ]);

            return $this->errorResponse('Failed to get user settings', 500);
        }
    }

    public function getPartitionSettings(Request $request)
    {
        try {
            $user = $this->getAuthenticatedUser($request);
            $partitionId = $this->getPartitionId($request);

            // Load groups.permissions for partition admin check
            if (! $user->is_system_user) {
                $user->load('groups.permissions');
            }

            $this->logRequest($request, 'list_partition', 'registry_settings');

            // Check if user can access partition settings
            if (! $user->is_system_user && ! $user->isPartitionAdmin($partitionId)) {
                return $this->errorResponse('Permission denied', 403);
            }

            $settings = RegistrySetting::getByScope('partition', null, $partitionId)
                ->orderBy('key')
                ->get();

            return $this->successResponse($settings);

        } catch (\Exception $e) {
            Log::error('Registry partition settings error', [
                'error' => $e->getMessage(),
                'user_id' => Auth::id(),
                'request_path' => $request->path(),
            ]);

            return $this->errorResponse('Failed to get partition settings', 500);
        }
    }

    public function getSystemSettings(Request $request)
    {
        try {
            $user = $this->getAuthenticatedUser($request);

            $this->logRequest($request, 'list_system', 'registry_settings');

            // Only system admins can access system settings
            if (! $user->is_system_user) {
                return $this->errorResponse('Permission denied', 403);
            }

            $settings = RegistrySetting::getByScope('system')
                ->orderBy('key')
                ->get();

            return $this->successResponse($settings);

        } catch (\Exception $e) {
            Log::error('Registry system settings error', [
                'error' => $e->getMessage(),
                'user_id' => Auth::id(),
                'request_path' => $request->path(),
            ]);

            return $this->errorResponse('Failed to get system settings', 500);
        }
    }

    /**
     * Find a registry setting with partition-aware scoping to prevent IDOR.
     * Only returns settings the user could potentially have access to based on scope.
     * System admins can access all settings.
     */
    protected function findAccessibleSetting(string $id, $user, ?string $partitionId): ?RegistrySetting
    {
        // System admins can access all settings
        if ($user->is_system_user) {
            return RegistrySetting::where('record_id', $id)->first();
        }

        // Load user's groups and permissions for partition admin check
        if (! $user->relationLoaded('groups')) {
            $user->load('groups.permissions');
        }

        // Fall back to user's partition if no partition specified
        $effectivePartitionId = $partitionId ?? $user->partition_id;

        return RegistrySetting::where('record_id', $id)
            ->where(function ($query) use ($user, $effectivePartitionId) {
                // User-level settings (only their own)
                $query->where(function ($q) use ($user) {
                    $q->where('scope', 'user')
                        ->where('scope_id', $user->record_id);
                });

                // Partition-level settings (only current partition context)
                $query->orWhere(function ($q) use ($effectivePartitionId) {
                    $q->where('scope', 'partition');
                    if ($effectivePartitionId) {
                        $q->where('partition_id', $effectivePartitionId);
                    }
                });
            })
            ->first();
    }
}
