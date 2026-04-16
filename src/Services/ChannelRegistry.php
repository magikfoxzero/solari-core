<?php

namespace NewSolari\Core\Services;

/**
 * Registry for WebSocket channel authorization strategies.
 *
 * Modules register their channels at boot time, decoupling the websocket
 * module from hardcoded knowledge of other modules' channels and ports.
 *
 * Usage in a module's ServiceProvider::boot():
 *
 *   app(ChannelRegistry::class)->register('bottles.user', [
 *       'type' => 'private',
 *       'auth' => 'local:user_match',
 *   ]);
 *
 *   app(ChannelRegistry::class)->register('bottles.penpal', [
 *       'type' => 'private',
 *       'auth' => config('services.websocket.self_url', 'http://127.0.0.1:8154') . '/api/bottles/channel-auth',
 *   ]);
 */
class ChannelRegistry
{
    /** @var array<string, array{type: string, auth: string}> */
    protected array $channels = [];

    /**
     * Register a channel prefix with its authorization strategy.
     *
     * @param  string  $prefix  Channel prefix (e.g., 'bottles.penpal', 'chat.room')
     * @param  array{type: string, auth: string}  $config  Channel config:
     *   - type: 'private' or 'presence'
     *   - auth: 'local:user_match' for simple user ID validation,
     *           or an HTTP URL for callback-based authorization
     */
    public function register(string $prefix, array $config): void
    {
        $this->channels[$prefix] = $config;
    }

    /**
     * Get the config for a channel prefix, or null if not registered.
     */
    public function get(string $prefix): ?array
    {
        return $this->channels[$prefix] ?? null;
    }

    /**
     * Get all registered channels.
     *
     * @return array<string, array{type: string, auth: string}>
     */
    public function all(): array
    {
        return $this->channels;
    }

    /**
     * Check if a channel prefix is registered.
     */
    public function has(string $prefix): bool
    {
        return isset($this->channels[$prefix]);
    }
}
