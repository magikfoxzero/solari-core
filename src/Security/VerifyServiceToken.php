<?php

namespace NewSolari\Core\Security;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware for service-to-service authentication.
 * Validates a shared secret token passed as a Bearer token.
 * This is simpler than JWT user auth and works for internal service calls.
 *
 * Used by multiple modules that expose service-to-service endpoints.
 * Supports optional service name parameter for per-service token lookup:
 *   - service.token          → checks services.service_token, falls back to jwt.secret
 *   - service.token:ai       → checks services.ai.service_token, falls back to default
 *   - service.token:notifications → checks services.notification_center.token, falls back to default
 */
class VerifyServiceToken
{
    /**
     * Map of service names to their config keys for per-service tokens.
     */
    protected static array $serviceConfigKeys = [
        'ai' => 'services.ai.service_token',
        'notifications' => 'services.notification_center.token',
        'websocket' => 'websocket.service_token',
    ];

    public function handle(Request $request, Closure $next, ?string $service = null): Response
    {
        $token = $request->bearerToken();
        $expectedToken = $this->resolveExpectedToken($service);

        if (!$token || !$expectedToken || !hash_equals($expectedToken, $token)) {
            return response()->json([
                'value' => false,
                'result' => 'Invalid service token',
                'code' => 401,
            ], 401);
        }

        return $next($request);
    }

    /**
     * Resolve the expected token for the given service.
     * Per-service config takes priority, then global service_token, then jwt.secret.
     */
    protected function resolveExpectedToken(?string $service): string
    {
        // Check per-service config first
        if ($service && isset(static::$serviceConfigKeys[$service])) {
            $serviceToken = config(static::$serviceConfigKeys[$service]);
            if ($serviceToken) {
                return $serviceToken;
            }
        }

        // Fall back to global service token, then jwt.secret
        return config('services.service_token') ?? config('jwt.secret') ?? '';
    }
}
