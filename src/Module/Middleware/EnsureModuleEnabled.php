<?php

namespace NewSolari\Core\Module\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use NewSolari\Core\Module\ModuleRegistry;
use Symfony\Component\HttpFoundation\Response;

class EnsureModuleEnabled
{
    public function handle(Request $request, Closure $next, string $module): Response
    {
        // Validate module slug format to prevent injection attacks
        // Module slugs should be alphanumeric with dashes/underscores, max 50 chars
        if (! preg_match('/^[a-zA-Z0-9_-]{1,50}$/', $module)) {
            Log::warning('Invalid module slug format', [
                'module' => substr($module, 0, 100), // Truncate for logging
                'path' => $request->path(),
            ]);

            return response()->json([
                'value' => false,
                'result' => 'Invalid module identifier',
                'code' => 400,
            ], 400);
        }

        if (! app(ModuleRegistry::class)->isEnabled($module)) {
            return response()->json([
                'value'  => false,
                'result' => "The '{$module}' module is disabled on this instance.",
                'code'   => 503,
            ], 503);
        }

        return $next($request);
    }
}
