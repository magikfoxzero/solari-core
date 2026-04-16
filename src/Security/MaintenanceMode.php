<?php

namespace NewSolari\Core\Security;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\HttpFoundation\Response;

/**
 * Maintenance Mode Middleware
 *
 * Checks if the application is in maintenance mode and returns a 503 response
 * with appropriate messaging. Supports IP-based bypass for administrators.
 *
 * Uses Laravel's built-in maintenance mode (artisan down/up) which stores
 * state in storage/framework/down file. This is more reliable than cache-based
 * approaches because it can be managed via CLI even when the API is down.
 */
class MaintenanceMode
{
    /**
     * Path to Laravel's maintenance mode file
     */
    protected const DOWN_FILE_PATH = 'framework/down';

    /**
     * Default maintenance message
     */
    protected const DEFAULT_MESSAGE = 'System is currently under maintenance';

    /**
     * Default retry-after time in seconds
     */
    protected const DEFAULT_RETRY_AFTER = 300;

    /**
     * Paths that should always bypass maintenance mode.
     * These are critical endpoints needed to manage the system.
     */
    protected const BYPASS_PATHS = [
        'api/system/maintenance/disable',
        'api/system/maintenance/enable',
        'api/system/status',
        'api/system/health',
    ];

    /**
     * Handle an incoming request.
     *
     * @return mixed
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (app()->isDownForMaintenance()) {
            // Allow certain paths to bypass maintenance mode (like disable endpoint)
            $path = $request->path();
            foreach (self::BYPASS_PATHS as $bypassPath) {
                if ($path === $bypassPath || str_starts_with($path, $bypassPath.'/')) {
                    return $next($request);
                }
            }

            // Allow bypass for test environment unless explicitly testing maintenance mode
            // Use X-Test-Maintenance header to indicate maintenance mode should be enforced during tests
            if (app()->environment('testing') && ! $request->header('X-Test-Maintenance')) {
                return $next($request);
            }

            // Allow certain IPs to bypass maintenance mode
            $allowedIps = array_filter(explode(',', config('app.maintenance_bypass_ips', '')));
            $clientIp = $request->ip();

            if (empty($allowedIps) || ! in_array($clientIp, $allowedIps)) {
                $data = self::getState();
                $retryAfter = $data['retry'] ?? self::DEFAULT_RETRY_AFTER;
                $message = $data['message'] ?? self::DEFAULT_MESSAGE;

                $response = response()->json([
                    'value' => false,
                    'result' => $message,
                    'code' => 503,
                    'maintenance' => true,
                    'retry_after' => $retryAfter,
                ], 503);

                // Add Retry-After header for proper HTTP compliance
                $response->headers->set('Retry-After', (string) $retryAfter);

                return $response;
            }
        }

        return $next($request);
    }

    /**
     * Enable maintenance mode using Laravel's artisan down command.
     *
     * @param  string  $message  Custom maintenance message
     * @param  int  $retryAfter  Expected downtime in seconds
     */
    public static function enable(string $message = self::DEFAULT_MESSAGE, int $retryAfter = self::DEFAULT_RETRY_AFTER): void
    {
        // Use Artisan to put app in maintenance mode
        // The --secret option creates a bypass cookie that admins can use
        Artisan::call('down', [
            '--retry' => $retryAfter,
            '--secret' => config('app.maintenance_secret') ?: \Illuminate\Support\Str::random(32),
        ]);

        // Store additional data (message) in the down file
        // Laravel stores JSON in storage/framework/down
        $downFilePath = storage_path(self::DOWN_FILE_PATH);
        if (file_exists($downFilePath)) {
            $data = json_decode(file_get_contents($downFilePath), true) ?? [];
            $data['message'] = $message;
            $data['started_at'] = now()->toIso8601String();
            file_put_contents($downFilePath, json_encode($data, JSON_PRETTY_PRINT));
        }
    }

    /**
     * Disable maintenance mode using Laravel's artisan up command.
     */
    public static function disable(): void
    {
        Artisan::call('up');
    }

    /**
     * Check if maintenance mode is enabled.
     */
    public static function isEnabled(): bool
    {
        return app()->isDownForMaintenance();
    }

    /**
     * Get current maintenance mode state.
     *
     * @return array|null Returns state data or null if not in maintenance
     */
    public static function getState(): ?array
    {
        if (! app()->isDownForMaintenance()) {
            return null;
        }

        $downFilePath = storage_path(self::DOWN_FILE_PATH);
        if (file_exists($downFilePath)) {
            $data = json_decode(file_get_contents($downFilePath), true);
            if ($data) {
                return [
                    'enabled' => true,
                    'message' => $data['message'] ?? self::DEFAULT_MESSAGE,
                    'retry_after' => $data['retry'] ?? self::DEFAULT_RETRY_AFTER,
                    'started_at' => $data['started_at'] ?? $data['time'] ?? null,
                    'secret' => $data['secret'] ?? null,
                ];
            }
        }

        return [
            'enabled' => true,
            'message' => self::DEFAULT_MESSAGE,
            'retry_after' => self::DEFAULT_RETRY_AFTER,
            'started_at' => null,
        ];
    }
}
