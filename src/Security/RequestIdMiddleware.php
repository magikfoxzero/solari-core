<?php

namespace NewSolari\Core\Security;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Request ID Middleware
 *
 * Generates a unique request ID for each incoming request to enable:
 * - Request tracing across log entries
 * - Correlation with external services
 * - Debugging and audit trail
 *
 * The request ID is:
 * - Generated as UUID v4 if not provided
 * - Accepted from X-Request-ID header (for distributed tracing)
 * - Added to all log entries via context
 * - Returned in response as X-Request-ID header
 */
class RequestIdMiddleware
{
    /**
     * The header name for the request ID
     */
    protected const REQUEST_ID_HEADER = 'X-Request-ID';

    /**
     * Handle an incoming request.
     *
     * @return mixed
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Get or generate request ID
        $requestId = $request->header(self::REQUEST_ID_HEADER) ?? (string) Str::uuid();

        // Validate incoming request ID (prevent injection)
        if (! $this->isValidRequestId($requestId)) {
            $requestId = (string) Str::uuid();
        }

        // Store in request attributes for access throughout the request lifecycle
        $request->attributes->set('request_id', $requestId);

        // Add to default log context so all logs include request_id
        Log::shareContext([
            'request_id' => $requestId,
        ]);

        // Process request
        $response = $next($request);

        // Add request ID to response headers
        $response->headers->set(self::REQUEST_ID_HEADER, $requestId);

        return $response;
    }

    /**
     * Validate that the request ID is a valid UUID or short alphanumeric string.
     * This prevents log injection attacks via malicious request IDs.
     */
    protected function isValidRequestId(string $requestId): bool
    {
        // Allow UUID format (with or without dashes)
        if (preg_match('/^[0-9a-f]{8}-?[0-9a-f]{4}-?[0-9a-f]{4}-?[0-9a-f]{4}-?[0-9a-f]{12}$/i', $requestId)) {
            return true;
        }

        // Allow short alphanumeric IDs (common in other systems)
        if (preg_match('/^[a-zA-Z0-9_-]{8,64}$/', $requestId)) {
            return true;
        }

        return false;
    }

    /**
     * Get the current request ID from the request.
     */
    public static function getRequestId(Request $request): ?string
    {
        return $request->attributes->get('request_id');
    }
}
