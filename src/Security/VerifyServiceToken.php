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
 * Each module can configure its expected token via config.
 */
class VerifyServiceToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();
        $expectedToken = config('services.service_token', config('jwt.secret', ''));

        if (!$token || !$expectedToken || !hash_equals($expectedToken, $token)) {
            return response()->json([
                'value' => false,
                'result' => 'Invalid service token',
                'code' => 401,
            ], 401);
        }

        return $next($request);
    }
}
