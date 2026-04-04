<?php

namespace NewSolari\Core\Identity\Controllers;

use NewSolari\Core\Http\BaseController;

use NewSolari\Core\Security\MaintenanceMode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class SystemController extends BaseController
{
    /**
     * Get basic system status (public endpoint).
     *
     * Returns minimal, non-sensitive information.
     * For detailed system info, use the authenticated /system/info endpoint.
     *
     * @unauthenticated
     */
    public function status(): JsonResponse
    {
        $this->logRequest(request(), 'status', 'system');

        // Only expose minimal, non-sensitive information publicly
        // Sensitive info (environment, debug mode, versions) requires authentication
        $data = [
            'system' => 'WebOS',
            'version' => config('app.version', '1.0.0'),
            // Minimum native shell version required for OTA updates
            // Native apps with older shells will be prompted to update via app store
            'min_native_version' => config('app.min_native_version', '1.0.0'),
            'timestamp' => now()->toDateTimeString(),
            'maintenance_mode' => MaintenanceMode::isEnabled(),
        ];

        return $this->successResponse($data);
    }

    /**
     * Get detailed system information (authenticated endpoint).
     *
     * Returns sensitive system information including environment,
     * debug mode, and version details. Requires authentication.
     */
    public function info(Request $request): JsonResponse
    {
        $this->logRequest($request, 'info', 'system');

        $user = $this->getAuthenticatedUser($request);

        // Check if user is authenticated
        if (! $user) {
            return $this->errorResponse('Authentication required', 401);
        }

        // Only system admins can view detailed system info
        if (! $user->is_system_user) {
            return $this->errorResponse('Forbidden', 403);
        }

        // Base system information (safe for all environments)
        $data = [
            'system' => 'WebOS',
            'version' => config('app.version', '1.0.0'),
            'environment' => config('app.env'),
            'timestamp' => now()->toDateTimeString(),
            'maintenance_mode' => MaintenanceMode::isEnabled(),
        ];

        // Additional debug information only available in non-production environments
        // This prevents information disclosure about system internals
        if (! app()->environment('production')) {
            $data['debug_mode'] = config('app.debug');
            $data['php_version'] = PHP_VERSION;
            $data['laravel_version'] = app()->version();
            $data['memory_usage'] = [
                'current' => round(memory_get_usage(true) / 1024 / 1024, 2).' MB',
                'peak' => round(memory_get_peak_usage(true) / 1024 / 1024, 2).' MB',
            ];
        }

        return $this->successResponse($data);
    }

    public function health(): JsonResponse
    {
        $this->logRequest(request(), 'health', 'system');

        // Check database connection
        try {
            \DB::connection()->getPdo();
            $databaseStatus = 'healthy';
        } catch (\Exception $e) {
            Log::error('Health check: Database connection failed', [
                'error' => $e->getMessage(),
            ]);
            $databaseStatus = 'unhealthy';
        }

        // Check cache connection
        try {
            Cache::put('health_check', 'test', 1);
            $cacheStatus = 'healthy';
        } catch (\Exception $e) {
            Log::error('Health check: Cache connection failed', [
                'error' => $e->getMessage(),
            ]);
            $cacheStatus = 'unhealthy';
        }

        // Overall status is unhealthy if any component is unhealthy
        $overallStatus = ($databaseStatus === 'healthy' && $cacheStatus === 'healthy')
            ? 'healthy'
            : 'unhealthy';

        $data = [
            'status' => $overallStatus,
            'database' => $databaseStatus,
            'cache' => $cacheStatus,
            'timestamp' => now()->toDateTimeString(),
        ];

        // Return 503 if unhealthy, 200 if healthy
        $httpCode = $overallStatus === 'healthy' ? 200 : 503;

        return $this->successResponse($data, $httpCode);
    }

    public function enableMaintenance(Request $request): JsonResponse
    {
        $this->logRequest($request, 'enable_maintenance', 'system');

        $user = $this->getAuthenticatedUser($request);

        // Check if user is authenticated
        if (! $user) {
            return $this->errorResponse('Authentication required', 401);
        }

        // Only system admins can enable maintenance mode
        if (! $user->is_system_user) {
            return $this->errorResponse('Forbidden', 403);
        }

        $validated = $request->validate([
            'message' => 'required|string|max:255',
            'retry_after' => 'nullable|integer|min:60',
        ]);

        MaintenanceMode::enable(
            $validated['message'],
            $validated['retry_after'] ?? 300
        );

        return $this->successResponse([
            'maintenance_mode' => true,
            'message' => $validated['message'],
            'retry_after' => $validated['retry_after'] ?? 300,
        ]);
    }

    public function disableMaintenance(Request $request): JsonResponse
    {
        $this->logRequest($request, 'disable_maintenance', 'system');

        $user = $this->getAuthenticatedUser($request);

        // Check if user is authenticated
        if (! $user) {
            return $this->errorResponse('Authentication required', 401);
        }

        // Only system admins can disable maintenance mode
        if (! $user->is_system_user) {
            return $this->errorResponse('Forbidden', 403);
        }

        MaintenanceMode::disable();

        return $this->successResponse([
            'maintenance_mode' => false,
        ]);
    }

    /**
     * Run idempotency key cleanup.
     *
     * Wraps the idempotency:cleanup artisan command.
     */
    public function cleanupIdempotency(Request $request): JsonResponse
    {
        $this->logRequest($request, 'cleanup_idempotency', 'system');

        $user = $this->getAuthenticatedUser($request);
        if (! $user->is_system_user) {
            return $this->errorResponse('Forbidden', 403);
        }

        try {
            $dryRun = $request->boolean('dry_run', false);

            $args = [];
            if ($dryRun) {
                $args['--dry-run'] = true;
            }

            $exitCode = Artisan::call('idempotency:cleanup', $args);
            $output = Artisan::output();

            Log::info('System operation: cleanup_idempotency', [
                'user_id' => $user->record_id,
                'dry_run' => $dryRun,
                'exit_code' => $exitCode,
            ]);

            return $this->successResponse([
                'output' => trim($output),
                'exit_code' => $exitCode,
                'dry_run' => $dryRun,
            ]);
        } catch (\Exception $e) {
            Log::error('System operation cleanup_idempotency failed', [
                'error' => $e->getMessage(),
            ]);

            return $this->errorResponse('Failed to run cleanup', 500);
        }
    }

    /**
     * Run record archival.
     *
     * Wraps the archive:records artisan command.
     */
    public function archiveRecords(Request $request): JsonResponse
    {
        $this->logRequest($request, 'archive_records', 'system');

        $user = $this->getAuthenticatedUser($request);
        if (! $user->is_system_user) {
            return $this->errorResponse('Forbidden', 403);
        }

        try {
            $dryRun = $request->boolean('dry_run', false);

            $args = ['--force' => true];
            if ($dryRun) {
                $args['--dry-run'] = true;
            }
            if ($request->filled('table')) {
                $args['--table'] = $request->input('table');
            }

            $exitCode = Artisan::call('archive:records', $args);
            $output = Artisan::output();

            Log::info('System operation: archive_records', [
                'user_id' => $user->record_id,
                'dry_run' => $dryRun,
                'table' => $request->input('table'),
                'exit_code' => $exitCode,
            ]);

            return $this->successResponse([
                'output' => trim($output),
                'exit_code' => $exitCode,
                'dry_run' => $dryRun,
            ]);
        } catch (\Exception $e) {
            Log::error('System operation archive_records failed', [
                'error' => $e->getMessage(),
            ]);

            return $this->errorResponse('Failed to archive records', 500);
        }
    }

    /**
     * Purge archived records.
     *
     * Wraps the archive:purge artisan command. Requires confirmation.
     */
    public function purgeArchived(Request $request): JsonResponse
    {
        $this->logRequest($request, 'purge_archived', 'system');

        $user = $this->getAuthenticatedUser($request);
        if (! $user->is_system_user) {
            return $this->errorResponse('Forbidden', 403);
        }

        $request->validate([
            'confirm' => 'required|accepted',
        ]);

        try {
            $dryRun = $request->boolean('dry_run', false);

            $args = ['--force' => true];
            if ($dryRun) {
                $args['--dry-run'] = true;
            }
            if ($request->filled('table')) {
                $args['--table'] = $request->input('table');
            }

            $exitCode = Artisan::call('archive:purge', $args);
            $output = Artisan::output();

            Log::info('System operation: purge_archived', [
                'user_id' => $user->record_id,
                'dry_run' => $dryRun,
                'table' => $request->input('table'),
                'exit_code' => $exitCode,
            ]);

            return $this->successResponse([
                'output' => trim($output),
                'exit_code' => $exitCode,
                'dry_run' => $dryRun,
            ]);
        } catch (\Exception $e) {
            Log::error('System operation purge_archived failed', [
                'error' => $e->getMessage(),
            ]);

            return $this->errorResponse('Failed to purge archived records', 500);
        }
    }

    /**
     * Run orphan record cleanup.
     *
     * Wraps the orphan:cleanup artisan command.
     */
    public function cleanupOrphans(Request $request): JsonResponse
    {
        $this->logRequest($request, 'cleanup_orphans', 'system');

        $user = $this->getAuthenticatedUser($request);
        if (! $user->is_system_user) {
            return $this->errorResponse('Forbidden', 403);
        }

        $request->validate([
            'retention_days' => 'nullable|integer|min:1',
        ]);

        try {
            $dryRun = $request->boolean('dry_run', false);

            $args = ['--force' => true];
            if ($dryRun) {
                $args['--dry-run'] = true;
            }
            if ($request->filled('retention_days')) {
                $args['--retention-days'] = (int) $request->input('retention_days');
            }

            $exitCode = Artisan::call('orphan:cleanup', $args);
            $output = Artisan::output();

            Log::info('System operation: cleanup_orphans', [
                'user_id' => $user->record_id,
                'dry_run' => $dryRun,
                'retention_days' => $request->input('retention_days'),
                'exit_code' => $exitCode,
            ]);

            return $this->successResponse([
                'output' => trim($output),
                'exit_code' => $exitCode,
                'dry_run' => $dryRun,
            ]);
        } catch (\Exception $e) {
            Log::error('System operation cleanup_orphans failed', [
                'error' => $e->getMessage(),
            ]);

            return $this->errorResponse('Failed to cleanup orphan accounts', 500);
        }
    }

    /**
     * Run push token cleanup.
     *
     * Wraps the push:cleanup artisan command.
     */
    public function cleanupPushTokens(Request $request): JsonResponse
    {
        $this->logRequest($request, 'cleanup_push_tokens', 'system');

        $user = $this->getAuthenticatedUser($request);
        if (! $user->is_system_user) {
            return $this->errorResponse('Forbidden', 403);
        }

        $request->validate([
            'retention_days' => 'nullable|integer|min:1',
        ]);

        try {
            $dryRun = $request->boolean('dry_run', false);

            $args = ['--force' => true];
            if ($dryRun) {
                $args['--dry-run'] = true;
            }
            if ($request->filled('retention_days')) {
                $args['--retention-days'] = (int) $request->input('retention_days');
            }

            $exitCode = Artisan::call('push:cleanup', $args);
            $output = Artisan::output();

            Log::info('System operation: cleanup_push_tokens', [
                'user_id' => $user->record_id,
                'dry_run' => $dryRun,
                'retention_days' => $request->input('retention_days'),
                'exit_code' => $exitCode,
            ]);

            return $this->successResponse([
                'output' => trim($output),
                'exit_code' => $exitCode,
                'dry_run' => $dryRun,
            ]);
        } catch (\Exception $e) {
            Log::error('System operation cleanup_push_tokens failed', [
                'error' => $e->getMessage(),
            ]);

            return $this->errorResponse('Failed to cleanup push tokens', 500);
        }
    }

    /**
     * Run GDPR data purge.
     *
     * Wraps the gdpr:purge artisan command. Requires confirmation.
     * Requires either record_id (with table) or username.
     */
    public function gdprPurge(Request $request): JsonResponse
    {
        $this->logRequest($request, 'gdpr_purge', 'system');

        $user = $this->getAuthenticatedUser($request);
        if (! $user->is_system_user) {
            return $this->errorResponse('Forbidden', 403);
        }

        $request->validate([
            'confirm' => 'required|accepted',
            'record_id' => 'required_without:username|string',
            'username' => 'required_without:record_id|string',
            'table' => 'required_with:record_id|string',
            'delete_user' => 'nullable|boolean',
        ]);

        try {
            $args = ['--force' => true];
            if ($request->filled('record_id')) {
                $args['--record-id'] = $request->input('record_id');
            }
            if ($request->filled('username')) {
                $args['--username'] = $request->input('username');
            }
            if ($request->filled('table')) {
                $args['--table'] = $request->input('table');
            }
            if ($request->boolean('delete_user', false)) {
                $args['--delete-user'] = true;
            }

            $exitCode = Artisan::call('gdpr:purge', $args);
            $output = Artisan::output();

            Log::warning('System operation: gdpr_purge', [
                'user_id' => $user->record_id,
                'target_record_id' => $request->input('record_id'),
                'target_username' => $request->input('username'),
                'table' => $request->input('table'),
                'delete_user' => $request->boolean('delete_user', false),
                'exit_code' => $exitCode,
            ]);

            return $this->successResponse([
                'output' => trim($output),
                'exit_code' => $exitCode,
            ]);
        } catch (\Exception $e) {
            Log::error('System operation gdpr_purge failed', [
                'error' => $e->getMessage(),
            ]);

            return $this->errorResponse('Failed to run GDPR purge', 500);
        }
    }

    /**
     * Send a test push notification.
     *
     * Wraps the push:test artisan command.
     */
    public function testPush(Request $request): JsonResponse
    {
        $this->logRequest($request, 'test_push', 'system');

        $user = $this->getAuthenticatedUser($request);
        // Test push is safe for partition admins (not just system admins)
        if (! $user->is_system_user && ! $user->isPartitionAdmin($user->partition_id)) {
            return $this->errorResponse('Forbidden', 403);
        }

        $request->validate([
            'user_id' => 'required|string',
            'title' => 'nullable|string|max:255',
            'body' => 'nullable|string|max:1000',
            'url' => 'nullable|string|max:2048',
        ]);

        try {
            $args = ['user' => $request->input('user_id')];
            if ($request->filled('title')) {
                $args['--title'] = $request->input('title');
            }
            if ($request->filled('body')) {
                $args['--body'] = $request->input('body');
            }
            if ($request->filled('url')) {
                $args['--url'] = $request->input('url');
            }

            $exitCode = Artisan::call('push:test', $args);
            $output = Artisan::output();

            Log::info('System operation: test_push', [
                'user_id' => $user->record_id,
                'target_user_id' => $request->input('user_id'),
                'exit_code' => $exitCode,
            ]);

            return $this->successResponse([
                'output' => trim($output),
                'exit_code' => $exitCode,
            ]);
        } catch (\Exception $e) {
            Log::error('System operation test_push failed', [
                'error' => $e->getMessage(),
            ]);

            return $this->errorResponse('Failed to send test push', 500);
        }
    }
}
