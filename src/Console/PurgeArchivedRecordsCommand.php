<?php

namespace NewSolari\Core\Console;

use NewSolari\Core\Services\ArchiveService;
use Illuminate\Console\Command;

/**
 * Permanently deletes archived records that have exceeded the purge retention period.
 * Records are permanently removed from archive tables after the configured time.
 *
 * Usage:
 *   php artisan archive:purge
 *   php artisan archive:purge --dry-run
 *   php artisan archive:purge --table=tasks
 *   php artisan archive:purge --ignore-retention
 */
class PurgeArchivedRecordsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'archive:purge
                            {--dry-run : Show what would be purged without actually deleting}
                            {--table= : Purge only a specific table\'s archive}
                            {--ignore-retention : Purge all archived records regardless of age}
                            {--force : Skip confirmation prompt}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Permanently delete archived records that have exceeded the purge retention period';

    public function handle(ArchiveService $archiveService): int
    {
        if (! config('archive.enabled', true)) {
            $this->warn('Archive system is disabled. Set ARCHIVE_ENABLED=true to enable.');

            return Command::SUCCESS;
        }

        $isDryRun = $this->option('dry-run');
        $specificTable = $this->option('table');
        $ignoreRetention = $this->option('ignore-retention');
        $purgeDays = config('archive.archive_purge_days');

        $this->info('Purge Archived Records');
        $this->info('======================');

        if ($ignoreRetention) {
            $this->warn('IGNORE RETENTION: Purging ALL archived records regardless of age');
            $this->warn('THIS WILL PERMANENTLY DELETE ALL ARCHIVED DATA!');
        } elseif (! $purgeDays || $purgeDays <= 0) {
            $this->warn('Archive purging is disabled (ARCHIVE_PURGE_DAYS not set).');
            $this->line('Set ARCHIVE_PURGE_DAYS environment variable to enable automatic purging.');
            $this->line('Or use --ignore-retention to purge all archived records.');

            return Command::SUCCESS;
        } else {
            $this->line("Purge retention period: {$purgeDays} days");
        }
        $this->line('');

        // Get eligible counts
        if ($specificTable) {
            $counts = [$specificTable => $archiveService->getPurgeEligibleCount($specificTable, $ignoreRetention)];
        } else {
            $counts = $archiveService->getAllPurgeEligibleCounts($ignoreRetention);
        }

        $totalEligible = array_sum($counts);

        if ($totalEligible === 0) {
            $this->info('No archived records eligible for purging.');

            return Command::SUCCESS;
        }

        // Show preview
        $this->info("Records eligible for permanent deletion:");
        $tableData = [];
        foreach ($counts as $table => $count) {
            if ($count > 0) {
                $tableData[] = [$table.'_archive', $count];
            }
        }
        $this->table(['Archive Table', 'Records'], $tableData);
        $this->line("Total: {$totalEligible} records");
        $this->line('');

        if ($isDryRun) {
            $this->warn('DRY RUN: No records were deleted.');

            return Command::SUCCESS;
        }

        // Confirm before proceeding
        if (! $this->option('force')) {
            $this->warn('WARNING: This will PERMANENTLY DELETE archived records. This cannot be undone!');
            if (! $this->confirm('Do you want to proceed with purging?')) {
                $this->info('Purge operation cancelled.');

                return Command::SUCCESS;
            }
        }

        $this->info('Purging archived records...');
        $this->line('');

        $progressBar = $this->output->createProgressBar($totalEligible);
        $progressBar->start();

        $results = [];
        $totalPurged = 0;
        $totalFailed = 0;

        if ($specificTable) {
            $result = $archiveService->purgeArchiveTable($specificTable, $ignoreRetention);
            $results[$specificTable] = $result;
            $totalPurged += $result->purged;
            $totalFailed += $result->failed;
            $progressBar->advance($result->purged + $result->failed);
        } else {
            foreach ($counts as $table => $count) {
                if ($count > 0) {
                    $result = $archiveService->purgeArchiveTable($table, $ignoreRetention);
                    $results[$table] = $result;
                    $totalPurged += $result->purged;
                    $totalFailed += $result->failed;
                    $progressBar->advance($result->purged + $result->failed);
                }
            }
        }

        $progressBar->finish();
        $this->line('');
        $this->line('');

        // Show results
        $this->info('Purge Results:');
        $resultData = [];
        foreach ($results as $table => $result) {
            $resultData[] = [
                $table.'_archive',
                $result->purged,
                $result->failed,
                $result->isSuccessful() ? 'OK' : 'FAILED',
            ];
        }
        $this->table(['Archive Table', 'Purged', 'Failed', 'Status'], $resultData);

        $this->line('');
        $this->info("Total purged: {$totalPurged}");

        if ($totalFailed > 0) {
            $this->error("Total failed: {$totalFailed}");

            return Command::FAILURE;
        }

        $this->info('Purge operation completed successfully.');

        return Command::SUCCESS;
    }
}
