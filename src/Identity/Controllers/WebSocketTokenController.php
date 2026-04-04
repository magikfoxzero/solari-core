<?php

namespace NewSolari\Core\Identity\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * WebSocket Token Controller
 *
 * Generates short-lived, single-use tokens for WebSocket authentication.
 * This prevents exposure of long-lived JWT tokens in WebSocket headers.
 *
 * Security features:
 * - 30-second TTL (minimal exposure window)
 * - Single use (token is deleted after validation)
 * - User and partition bound (cannot be used by other users)
 *
 * @see FE-CRIT-002 - WebSocket Authentication Token Exposure
 */
class WebSocketTokenController extends Controller
{
    /**
     * WebSocket token TTL in seconds.
     * Must be longer than the frontend refresh interval (3 minutes).
     * 5 minutes provides buffer for refresh timing.
     */
    private const TOKEN_TTL_SECONDS = 300;

    /**
     * Generate a short-lived WebSocket authentication token.
     *
     * The token is stored in cache with user context and can only be used once.
     * This endpoint requires authentication via the standard auth middleware
     * (which now reads JWT from httpOnly cookie).
     */
    public function generateToken(Request $request): JsonResponse
    {
        // SECURITY: Get user from attributes only (set by middleware)
        // Never use $request->get() which could read from POST body
        $user = $request->attributes->get('authenticated_user');

        if (! $user) {
            return response()->json([
                'value' => false,
                'result' => 'Unauthorized',
                'code' => 401,
            ], 401);
        }

        // Get partition ID from attributes (set by middleware) or header
        $partitionId = $request->attributes->get('partition_id')
            ?? $request->header('X-Partition-ID');

        // Generate a cryptographically secure random token
        $wsToken = Str::random(64);

        // Store token with user context
        // The token is single-use: Cache::pull() in BroadcastAuthController removes it
        Cache::put(
            "ws_token:{$wsToken}",
            [
                'user_id' => $user->record_id,
                'partition_id' => $partitionId,
                'created_at' => now()->timestamp,
            ],
            now()->addSeconds(self::TOKEN_TTL_SECONDS)
        );

        return response()->json([
            'value' => true,
            'result' => [
                'ws_token' => $wsToken,
                'expires_in' => self::TOKEN_TTL_SECONDS,
            ],
        ]);
    }
}
