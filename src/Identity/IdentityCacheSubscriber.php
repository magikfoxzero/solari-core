<?php

namespace NewSolari\Core\Identity;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

/**
 * Artisan command that subscribes to Redis pub/sub identity.events channel
 * and invalidates local caches when identity data changes.
 *
 * CRITICAL: Token revocation is NOT handled here. The identity service writes
 * shared Redis cache keys (jwt_blacklist_{jti}) that AuthenticationMiddleware
 * checks directly. This subscriber only handles non-security cache invalidation.
 *
 * Run as a long-lived process: php artisan identity:cache-subscriber
 */
class IdentityCacheSubscriber extends Command
{
    protected $signature = 'identity:cache-subscriber';

    protected $description = 'Subscribe to identity service events and invalidate local caches';

    public function __construct(
        private readonly IdentityApiClient $client,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->info('Subscribing to identity.events channel...');

        try {
            Redis::subscribe(['identity.events'], function (string $message) {
                $this->processEvent($message);
            });
        } catch (\Exception $e) {
            Log::error('Identity cache subscriber failed', [
                'error' => $e->getMessage(),
            ]);
            $this->error("Subscriber failed: {$e->getMessage()}");

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    private function processEvent(string $message): void
    {
        try {
            $event = json_decode($message, true);

            if (! is_array($event) || ! isset($event['event'])) {
                Log::warning('Identity cache subscriber received malformed event', [
                    'message' => mb_substr($message, 0, 200),
                ]);

                return;
            }

            $type = $event['event'];
            $data = $event['data'] ?? [];

            match ($type) {
                'user.updated', 'user.deleted', 'user.logout' => $this->handleUserInvalidation($data),
                'user.banned' => $this->handleUserBanned($data),
                'user.blocked' => $this->handleUserBlocked($data),
                'permissions.changed' => $this->handlePermissionsChanged($data),
                'partition.updated' => $this->handlePartitionInvalidation($data),
                'partition_app.changed' => $this->handlePartitionAppChanged($data),
                default => Log::debug('Identity cache subscriber ignoring unknown event', [
                    'type' => $type,
                ]),
            };
        } catch (\Exception $e) {
            Log::error('Identity cache subscriber event processing error', [
                'error' => $e->getMessage(),
                'message' => mb_substr($message, 0, 200),
            ]);
        }
    }

    private function handleUserInvalidation(array $data): void
    {
        if (! isset($data['user_id'])) {
            return;
        }

        $this->client->invalidateUser($data['user_id']);
        Log::info('Identity cache: invalidated user', ['user_id' => $data['user_id']]);
    }

    private function handleUserBanned(array $data): void
    {
        if (! isset($data['user_id'])) {
            return;
        }

        Cache::forget('soft_ban:' . $data['user_id']);
        Log::info('Identity cache: cleared soft ban cache', ['user_id' => $data['user_id']]);
    }

    private function handleUserBlocked(array $data): void
    {
        if (! isset($data['user_id'])) {
            return;
        }

        // Invalidate both users' caches so block status is fresh
        $this->client->invalidateUser($data['user_id']);
        if (isset($data['blocked_user_id'])) {
            $this->client->invalidateUser($data['blocked_user_id']);
        }
        Log::info('Identity cache: cleared user block cache', ['user_id' => $data['user_id']]);
    }

    private function handlePermissionsChanged(array $data): void
    {
        if (! isset($data['user_id'])) {
            return;
        }

        $this->client->invalidateUser($data['user_id']);
        Log::info('Identity cache: invalidated user permissions', ['user_id' => $data['user_id']]);
    }

    private function handlePartitionInvalidation(array $data): void
    {
        if (! isset($data['partition_id'])) {
            return;
        }

        $this->client->invalidatePartition($data['partition_id']);
        Log::info('Identity cache: invalidated partition', ['partition_id' => $data['partition_id']]);
    }

    private function handlePartitionAppChanged(array $data): void
    {
        if (! isset($data['partition_id']) || ! isset($data['plugin_id'])) {
            return;
        }

        Cache::forget('partition_app:' . $data['partition_id'] . ':' . $data['plugin_id']);
        Cache::forget('identity_partition_apps:' . $data['partition_id']);
        Log::info('Identity cache: cleared partition app cache', [
            'partition_id' => $data['partition_id'],
            'plugin_id' => $data['plugin_id'],
        ]);
    }
}
