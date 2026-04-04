<?php

namespace NewSolari\Core\Module\Middleware;

use NewSolari\Core\Services\PartitionAppService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class CheckPartitionAppEnabled
{
    /**
     * The partition app service instance.
     */
    protected PartitionAppService $service;

    /**
     * Create a new middleware instance.
     */
    public function __construct(PartitionAppService $service)
    {
        $this->service = $service;
    }

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next, ?string $pluginId = null): Response
    {
        // Skip check if no plugin_id provided
        // This allows system routes and partition management routes to pass through
        if (! $pluginId) {
            return $next($request);
        }

        // Validate plugin ID format to prevent injection attacks
        // Plugin IDs should be alphanumeric with dashes/underscores, max 50 chars
        if (! preg_match('/^[a-zA-Z0-9_-]{1,50}$/', $pluginId)) {
            Log::warning('Invalid plugin ID format', [
                'plugin_id' => substr($pluginId, 0, 100), // Truncate for logging
                'path' => $request->path(),
            ]);

            return response()->json([
                'value' => false,
                'result' => 'Invalid plugin identifier',
                'code' => 400,
            ], 400);
        }

        // Get partition ID from various sources
        $partitionId = $this->getPartitionId($request);

        // If no partition context, return error
        if (! $partitionId) {
            return response()->json([
                'value' => false,
                'result' => 'Partition context required',
                'code' => 400,
            ], 400);
        }

        // Check if app is enabled for this partition
        $isEnabled = $this->service->isEnabled($partitionId, $pluginId);

        if (! $isEnabled) {
            Log::warning('App access denied - disabled for partition', [
                'plugin_id' => $pluginId,
                'partition_id' => $partitionId,
                'user_id' => $request->user()?->record_id ?? $request->attributes->get('authenticated_user')?->record_id,
                'path' => $request->path(),
                'method' => $request->method(),
            ]);

            return response()->json([
                'value' => false,
                'result' => 'App is not enabled for this partition',
                'code' => 403,
                'plugin_id' => $pluginId,
            ], 403);
        }

        // Check if app is admin-only
        $isAdminOnly = $this->service->isAdminOnly($partitionId, $pluginId);

        if ($isAdminOnly) {
            $user = $request->user() ?? $request->attributes->get('authenticated_user');
            $isAdmin = $user?->is_system_user || ($user && method_exists($user, 'isPartitionAdmin') && $user->isPartitionAdmin($partitionId));

            if (! $isAdmin) {
                Log::warning('App access denied - admin only', [
                    'plugin_id' => $pluginId,
                    'partition_id' => $partitionId,
                    'user_id' => $user?->record_id,
                    'path' => $request->path(),
                    'method' => $request->method(),
                ]);

                return response()->json([
                    'value' => false,
                    'result' => 'App is restricted to administrators',
                    'code' => 403,
                    'plugin_id' => $pluginId,
                ], 403);
            }
        }

        // App is enabled and user has access, continue with request
        return $next($request);
    }

    /**
     * Get partition ID from request.
     */
    protected function getPartitionId(Request $request): ?string
    {
        // Try to get from request input/query first
        $partitionId = $request->input('partition_id') ?? $request->query('partition_id');

        // Try X-Partition-ID header
        if (! $partitionId) {
            $partitionId = $request->header('X-Partition-ID') ?? $request->header('X-Partition');
        }

        // Try authenticated user's partition
        if (! $partitionId) {
            $user = $request->user() ?? $request->attributes->get('authenticated_user');
            if ($user) {
                $partitionId = $user->partition_id;
            }
        }

        return $partitionId;
    }
}
