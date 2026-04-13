<?php

namespace NewSolari\Core;

use Illuminate\Support\ServiceProvider;
use NewSolari\Core\Module\Contracts\ServiceBusInterface;
use NewSolari\Core\Module\ModuleRegistry;
use NewSolari\Core\Module\ServiceBus\InProcessServiceBus;
use NewSolari\Core\Services\AuthorizationService;
use NewSolari\Core\Services\EntityTypeRegistryService;
use NewSolari\Core\Services\PartitionAppService;
use NewSolari\Core\Services\PluginRegistry;
use NewSolari\Core\Services\RelationshipMigrationService;
use NewSolari\Core\Services\RelationshipService;
use NewSolari\Core\Services\RelationshipTypeRegistryService;

class CoreServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $configs = ['security', 'jwt', 'passkeys', 'modules', 'ratelimits', 'relationships', 'idempotency'];
        foreach ($configs as $config) {
            $this->mergeConfigFrom(__DIR__ . '/../config/' . $config . '.php', $config);
        }

        $this->app->singleton(ServiceBusInterface::class, InProcessServiceBus::class);
        $this->app->singleton(ModuleRegistry::class);

        // --- Services moved from AppServiceProvider ---

        // Register PluginRegistry as a singleton
        $this->app->singleton(PluginRegistry::class);

        // Alias for plugin.manager (used by MetaAppBase)
        $this->app->alias(PluginRegistry::class, 'plugin.manager');

        // Register PartitionAppService as a singleton
        $this->app->singleton(PartitionAppService::class, function ($app) {
            return new PartitionAppService(
                $app->make(PluginRegistry::class)
            );
        });

        // Register AuthorizationService as a singleton
        $this->app->singleton(AuthorizationService::class);

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

        // Identity API client for service-to-service communication
        $this->app->singleton(\NewSolari\Core\Identity\IdentityApiClient::class);
    }

    public function boot(): void
    {
        // Register service token middleware alias for service-to-service auth
        // Available to all modules that need service-to-service endpoints
        $this->app['router']->aliasMiddleware(
            'service.token',
            \NewSolari\Core\Security\VerifyServiceToken::class
        );

        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
        $this->loadRoutesFrom(__DIR__ . '/../routes/api.php');

        if ($this->app->runningInConsole()) {
            $this->commands([
                \NewSolari\Core\Module\Console\ModuleClearCacheCommand::class,
                \NewSolari\Core\Module\Console\ModuleListCommand::class,
                \NewSolari\Core\Module\Console\SoftBanExpireCommand::class,
                \NewSolari\Core\Identity\IdentityCacheSubscriber::class,
            ]);
        }
    }
}
