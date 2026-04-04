<?php

namespace NewSolari\Core\Security;

use NewSolari\Core\Identity\Models\IdentityUser;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class TestAuthenticationMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @return mixed
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Security: Only process in testing environment
        // Do NOT use runningInConsole() as it's true for artisan commands in production
        // APP_ENV must be explicitly set to 'testing' for test authentication
        if (! app()->environment('testing')) {
            return $next($request);
        }

        // Additional safety check: require explicit opt-in via config
        if (! config('app.allow_test_authentication', false)) {
            return $next($request);
        }

        // Check for user ID in headers (safe alternative to serialization)
        $testUserId = $request->header('X-Test-User-Id');
        $partitionId = $request->header('X-Partition-ID') ?? $request->header('X-Partition') ?? $request->header('x-partition');

        if ($testUserId) {
            // Look up the user by ID without partition scope
            // This is necessary because the X-Partition-ID header might specify a different
            // partition than the user belongs to (e.g., system user accessing other partitions)
            $user = IdentityUser::withoutGlobalScope('partition')->find($testUserId);

            if ($user) {
                // Store the user object directly in the request attributes
                $request->attributes->set('authenticated_user', $user);

                // Set the user in Laravel's Auth system so Auth::id() works
                \Illuminate\Support\Facades\Auth::setUser($user);

                // Set partition ID if provided - in both attributes (for global scopes) and input
                if ($partitionId) {
                    // Set in attributes for PartitionScope to read
                    $request->attributes->set('partition_id', $partitionId);

                    // Also merge into input if not already present (for backwards compatibility)
                    if (! $request->input('partition_id')) {
                        $request->merge(['partition_id' => $partitionId]);
                    }
                }
            }
        }

        return $next($request);
    }
}
