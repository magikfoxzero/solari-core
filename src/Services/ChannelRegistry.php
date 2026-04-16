<?php

namespace NewSolari\Core\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Database-backed registry for WebSocket channel authorization strategies.
 *
 * Modules register their channels at boot time. Registrations are persisted
 * to the `websocket_channels` table so they're available to any service
 * (including the standalone websocket service where other module providers
 * don't boot).
 *
 * Lookups are cached for 5 minutes to avoid DB queries on every broadcast auth.
 *
 * Usage in a module's ServiceProvider::boot():
 *
 *   app(ChannelRegistry::class)->register('bottles.user', [
 *       'type' => 'private',
 *       'auth' => 'local:user_match',
 *   ], 'bottles');
 */
class ChannelRegistry
{
    private const CACHE_KEY = 'websocket_channels';
    private const CACHE_TTL = 300; // 5 minutes

    /**
     * Register a channel prefix with its authorization strategy.
     * Idempotent — updates if already registered with different config.
     *
     * @param  string  $prefix  Channel prefix (e.g., 'bottles.penpal', 'chat.room')
     * @param  array{type: string, auth: string}  $config  Channel config
     * @param  string|null  $registeredBy  Module name for audit trail
     */
    public function register(string $prefix, array $config, ?string $registeredBy = null): void
    {
        if (! $this->tableExists()) {
            return;
        }

        try {
            DB::table('websocket_channels')->updateOrInsert(
                ['prefix' => $prefix],
                [
                    'type' => $config['type'] ?? 'private',
                    'auth_url' => $config['auth'] ?? '',
                    'registered_by' => $registeredBy,
                    'updated_at' => now(),
                ]
            );

            // Clear cache so the new channel is picked up immediately
            Cache::forget(self::CACHE_KEY);
        } catch (\Exception $e) {
            // Don't crash the app if the table doesn't exist yet (pre-migration)
            Log::debug('ChannelRegistry: failed to register channel', [
                'prefix' => $prefix,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Get the config for a channel prefix, or null if not registered.
     */
    public function get(string $prefix): ?array
    {
        $channels = $this->all();
        return $channels[$prefix] ?? null;
    }

    /**
     * Get all registered channels (cached).
     *
     * @return array<string, array{type: string, auth: string}>
     */
    public function all(): array
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL, function () {
            if (! $this->tableExists()) {
                return [];
            }

            try {
                $rows = DB::table('websocket_channels')->get();
                $channels = [];
                foreach ($rows as $row) {
                    $channels[$row->prefix] = [
                        'type' => $row->type,
                        'auth' => $row->auth_url,
                    ];
                }
                return $channels;
            } catch (\Exception $e) {
                return [];
            }
        });
    }

    /**
     * Check if a channel prefix is registered.
     */
    public function has(string $prefix): bool
    {
        return $this->get($prefix) !== null;
    }

    /**
     * Remove a channel registration.
     */
    public function unregister(string $prefix): void
    {
        if (! $this->tableExists()) {
            return;
        }

        try {
            DB::table('websocket_channels')->where('prefix', $prefix)->delete();
            Cache::forget(self::CACHE_KEY);
        } catch (\Exception $e) {
            // Ignore
        }
    }

    /**
     * Check if the websocket_channels table exists (pre-migration safety).
     */
    private function tableExists(): bool
    {
        try {
            return Schema::hasTable('websocket_channels');
        } catch (\Exception) {
            return false;
        }
    }
}
