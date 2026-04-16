<?php

namespace NewSolari\Core;

use Illuminate\Support\ServiceProvider;
use NewSolari\Core\Module\Contracts\ServiceBusInterface;
use NewSolari\Core\Module\ModuleRegistry;
use NewSolari\Core\Module\ServiceBus\InProcessServiceBus;
use NewSolari\Core\Services\EntityTypeRegistryService;
use NewSolari\Core\Services\PluginRegistry;
use NewSolari\Core\Services\RelationshipMigrationService;
use NewSolari\Core\Services\RelationshipService;
use NewSolari\Core\Services\RelationshipTypeRegistryService;
use NewSolari\Core\Services\ShareableTypeRegistry;

class CoreServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $configs = ['security', 'modules', 'ratelimits', 'relationships', 'idempotency'];
        foreach ($configs as $config) {
            $this->mergeConfigFrom(__DIR__ . '/../config/' . $config . '.php', $config);
        }

        $this->app->singleton(ServiceBusInterface::class, InProcessServiceBus::class);
        $this->app->singleton(ModuleRegistry::class);

        // --- Services moved from AppServiceProvider ---

        // Register PluginRegistry as a singleton
        $this->app->singleton(PluginRegistry::class);

        // Register ShareableTypeRegistry — modules register their shareable types at boot
        $this->app->singleton(ShareableTypeRegistry::class);

        // Register ChannelRegistry — modules register their WebSocket channels at boot
        $this->app->singleton(Services\ChannelRegistry::class);

        // Alias for plugin.manager (used by MetaAppBase)
        $this->app->alias(PluginRegistry::class, 'plugin.manager');

        // --- Services moved from RelationshipServiceProvider ---

        $this->app->singleton(EntityTypeRegistryService::class, function ($app) {
            return new EntityTypeRegistryService;
        });

        $this->app->singleton(RelationshipTypeRegistryService::class, function ($app) {
            return new RelationshipTypeRegistryService;
        });

        $this->app->singleton(RelationshipService::class, function ($app) {
            return new RelationshipService(
                $app->make(EntityTypeRegistryService::class),
                $app->make(RelationshipTypeRegistryService::class)
            );
        });

        $this->app->singleton(RelationshipMigrationService::class, function ($app) {
            return new RelationshipMigrationService(
                $app->make(RelationshipService::class)
            );
        });

        // App\Services\* aliases removed — stubs deleted, all code uses NewSolari\Core\Services\* directly
    }

    public function boot(): void
    {
        // Register service token middleware alias for service-to-service auth
        // Available to all modules that need service-to-service endpoints
        $this->app['router']->aliasMiddleware(
            'service.token',
            \NewSolari\Core\Security\VerifyServiceToken::class
        );

        $this->validateIdentityBindings();

        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([
                \NewSolari\Core\Module\Console\ModuleClearCacheCommand::class,
                \NewSolari\Core\Module\Console\ModuleListCommand::class,
                \NewSolari\Core\Console\CleanupIdempotencyKeysCommand::class,
                \NewSolari\Core\Console\ArchiveDeletedRecordsCommand::class,
                \NewSolari\Core\Console\PurgeArchivedRecordsCommand::class,
            ]);
        }
    }

    /**
     * Validate that required identity module bindings exist.
     * Fails fast with a clear message if the identity module isn't loaded.
     */
    protected function validateIdentityBindings(): void
    {
        $required = ['identity.user_model', 'identity.partition_model'];

        foreach ($required as $binding) {
            if (! $this->app->bound($binding)) {
                throw new \RuntimeException(
                    "Required binding '{$binding}' is not registered. "
                    . "Ensure the Identity module registers '{$binding}' in its register() method (not boot())."
                );
            }
        }
    }
}
