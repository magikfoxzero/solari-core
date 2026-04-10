<?php

namespace NewSolari\Core\Module\Console;

use Illuminate\Console\Command;
use NewSolari\Core\Module\ModuleRegistry;

class ModuleListCommand extends Command
{
    protected $signature = 'module:list';
    protected $description = 'List all discovered and registered modules';

    public function handle(): int
    {
        $registry = app(ModuleRegistry::class);
        $discovered = $registry->getDiscoveredModules();
        $rows = [];
        $enabledCount = 0;

        foreach ($discovered as $id => $data) {
            $enabled = $registry->isEnabled($id);
            if ($enabled) $enabledCount++;
            $inProcess = $registry->getModule($id) !== null;

            $rows[] = [
                $id,
                $data['name'] ?? $id,
                $data['type'] ?? 'mini-app',
                $enabled ? 'Yes' : 'No',
                $inProcess ? 'In-process' : 'Remote',
            ];
        }

        usort($rows, fn ($a, $b) => strcmp($a[0], $b[0]));

        $this->table(['ID', 'Name', 'Type', 'Enabled', 'Mode'], $rows);
        $this->info(count($rows) . " modules discovered, {$enabledCount} enabled");

        return 0;
    }
}
