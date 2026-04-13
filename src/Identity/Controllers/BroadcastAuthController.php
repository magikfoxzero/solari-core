<?php

namespace NewSolari\Core\Identity\Controllers;

use NewSolari\Core\Identity\IdentityApiClient;
use NewSolari\Core\Identity\Models\IdentityUser;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Cache;

/**
 * Broadcast Authentication Controller
 *
 * Handles WebSocket channel authorization for Laravel Echo/Reverb.
 * Supports both short-lived WS tokens (preferred) and JWT (fallback).
 *
 * @see FE-CRIT-002 - WebSocket Authentication Token Exposure
 */
class BroadcastAuthController extends Controller
{
    /**
     * Authenticate the request for channel access.
     *
     * Authentication priority:
     * 1. Short-lived WS token (X-WS-Token header) - preferred, single-use
     * 2. JWT token (from httpOnly cookie or Bearer header) - fallback
     */
    public function authenticate(Request $request)
    {
        $user = null;
        $partitionId = null;

        // FE-CRIT-002: Try short-lived WebSocket token first (preferred)
        // Tokens expire in 30 seconds - using get() instead of pull() allows
        // multiple channel subscriptions with the same token before expiry
        $wsToken = $request->header('X-WS-Token');
        if ($wsToken) {
            $tokenData = Cache::get("ws_token:{$wsToken}");
            if ($tokenData) {
                $user = app(IdentityApiClient::class)->getUser($tokenData['user_id']);
                // TODO: Remove this Eloquent fallback in Phase 4 when identity tables are dropped from module database
                if (! $user) {
                    $user = IdentityUser::where('record_id', $tokenData['user_id'])->first();
                }
                $partitionId = $tokenData['partition_id'] ?? null;
            }
        }

        // Fallback to JWT authentication (from middleware)
        // This maintains backward compatibility and supports API clients
        if (! $user) {
            // SECURITY: Get user from attributes only (set by middleware) or Auth facade
            // Never use $request->get() which could read from POST body
            $user = $request->attributes->get('authenticated_user') ?? Auth::user();

            // SECURITY: Get partition_id from attributes only (set by middleware)
            // or fallback to header - never use $request->get() which could read from POST body
            $partitionId = $request->attributes->get('partition_id') ?? $request->header('X-Partition-ID');
        }

        if (! $user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        // Set partition_id for channel authorization
        // UserContext has readonly properties, so store partition_id as a request attribute
        // Channel auth checks: $user->current_partition_id ?? $user->partition_id ?? request()->attributes->get('partition_id')
        if ($partitionId) {
            $request->attributes->set('partition_id', $partitionId);
            // IdentityUser (Eloquent) supports dynamic properties for backward compatibility
            if ($user instanceof IdentityUser) {
                $user->current_partition_id = $partitionId;
            }
        }

        // Set user in Auth for Broadcast::auth to use
        // UserContext doesn't implement Authenticatable, so use request resolver as fallback
        if ($user instanceof Authenticatable) {
            Auth::setUser($user);
        } else {
            $request->setUserResolver(fn () => $user);
        }

        // Let Laravel handle the channel authorization
        return Broadcast::auth($request);
    }
}
