<?php

namespace NewSolari\Core\Security;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class DualWriteRelationships
{
    /**
     * Handle an incoming request.
     *
     * This middleware intercepts relationship operations and writes to both
     * the legacy pivot tables AND the new entity_relationships table during
     * the migration period.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Only enable dual-write if configured
        if (! config('relationships.features.dual_write_enabled', false)) {
            return $next($request);
        }

        // Store the request for potential dual-write operations
        // The actual dual-write logic would be handled by model observers
        // or in the service layer to ensure both tables stay in sync

        $response = $next($request);

        // Log dual-write operations if debug logging is enabled
        if (config('relationships.features.debug_logging', false)) {
            $this->logDualWriteOperation($request, $response);
        }

        return $response;
    }

    /**
     * Log dual-write operation for debugging.
     */
    protected function logDualWriteOperation(Request $request, Response $response): void
    {
        // Only log relationship-related operations
        if (! str_contains($request->path(), 'relationships')) {
            return;
        }

        Log::debug('Dual-write operation', [
            'method' => $request->method(),
            'path' => $request->path(),
            'status' => $response->getStatusCode(),
            'user_id' => $request->user()?->record_id,
        ]);
    }
}
