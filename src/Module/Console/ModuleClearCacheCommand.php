<?php

namespace NewSolari\Core\Module\Console;

use Illuminate\Console\Command;
use NewSolari\Core\Module\ModuleRegistry;

class ModuleClearCacheCommand extends Command
{
    protected $signature = 'module:clear-cache';
    protected $description = 'Clear the discovered modules cache';

    public function handle(): int
    {
        app(ModuleRegistry::class)->clearDiscoveryCache();
        $this->info('Module discovery cache cleared.');
        return 0;
    }
}
