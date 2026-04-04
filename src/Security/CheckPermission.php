<?php

namespace NewSolari\Core\Security;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Permission middleware implementing three-tier authorization:
 *
 * Tier 1: System Admins - bypass all permission checks (is_system_user = true)
 * Tier 2: Partition Admins - bypass in their partition (isPartitionAdmin = true)
 * Tier 3: Regular Users - require explicit permission
 *
 * Usage in routes:
 *   Route::get('/users', [UserController::class, 'index'])->middleware('permission:users.read');
 *   Route::post('/notes', [NotesController::class, 'store'])->middleware('permission:notes.create');
 */
class CheckPermission
{
    /**
     * Handle an incoming request.
     *
     * @param  string  $permission  The required permission (e.g., 'users.read', 'notes.create')
     */
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        // Get authenticated user from request (set by AuthenticationMiddleware)
        // Using $request->attributes->get() which reads from middleware-set attributes only,
        // not from user-controllable input like POST body or query params.
        $user = $request->attributes->get('authenticated_user');

        if (!$user) {
            return response()->json([
                'value' => false,
                'result' => 'Unauthorized',
                'code' => 401,
            ], 401);
        }

        // Tier 1: System admins bypass all permission checks
        if ($user->is_system_user) {
            return $next($request);
        }

        // Get partition ID from request attributes (set by AuthenticationMiddleware) or header
        // Note: Use $request->attributes->get() to get middleware-set values, not $request->get()
        // which would include POST body parameters that could be manipulated
        $partitionId = $request->attributes->get('partition_id') ?? $request->header('X-Partition-ID');

        // Tier 2: Partition admins bypass in their partition
        if ($partitionId && $user->isPartitionAdmin($partitionId)) {
            return $next($request);
        }

        // Tier 3: Regular users - check specific permission
        if ($user->hasPermission($permission)) {
            return $next($request);
        }

        // Permission denied
        return response()->json([
            'value' => false,
            'result' => 'Unauthorized',
            'code' => 403,
        ], 403);
    }
}
