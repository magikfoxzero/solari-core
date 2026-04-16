<?php

namespace NewSolari\Core\Console;

use NewSolari\Core\Services\ArchiveService;
use Illuminate\Console\Command;

/**
 * Archive Deleted Records Command
 *
 * Archives soft-deleted records that have exceeded the retention period.
 * Records are moved from the main table to the corresponding archive table.
 *
 * Usage:
 *   php artisan archive:records
 *   php artisan archive:records --dry-run
 *   php artisan archive:records --table=tasks
 *   php artisan archive:records --partition=abc123
 *   php artisan archive:records --ignore-retention
 */
class ArchiveDeletedRecordsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'archive:records
                            {--dry-run : Show what would be archived without actually archiving}
                            {--table= : Archive only a specific table}
                            {--partition= : Archive only records from a specific partition}
                            {--ignore-retention : Archive all deleted records regardless of retention period}
                            {--force : Skip confirmation prompt}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Archive soft-deleted records that have exceeded the retention period';

    /**
     * Execute the console command.
     */
    public function handle(ArchiveService $archiveService): int
    {
        if (! config('archive.enabled', true)) {
            $this->warn('Archive system is disabled. Set ARCHIVE_ENABLED=true to enable.');

            return Command::SUCCESS;
        }

        $isDryRun = $this->option('dry-run');
        $specificTable = $this->option('table');
        $partitionId = $this->option('partition');
        $ignoreRetention = $this->option('ignore-retention');
        $retentionDays = config('archive.retention_days', 30);

        $this->info('Archive Deleted Records');
        $this->info('=======================');
        if ($ignoreRetention) {
            $this->warn('IGNORE RETENTION: Archiving ALL deleted records regardless of age');
        } else {
            $this->line("Retention period: {$retentionDays} days");
        }
        $this->line('');

        // Get eligible counts
        if ($specificTable) {
            $counts = [$specificTable => $archiveService->getEligibleCount($specificTable, $partitionId, $ignoreRetention)];
        } else {
            $counts = $archiveService->getAllEligibleCounts($partitionId, $ignoreRetention);
        }

        $totalEligible = array_sum($counts);

        if ($totalEligible === 0) {
            $this->info('No records eligible for archiving.');

            return Command::SUCCESS;
        }

        // Display eligible records
        $this->info('Records eligible for archiving:');
        $tableData = [];
        foreach ($counts as $table => $count) {
            if ($count > 0) {
                $tableData[] = [$table, $count];
            }
        }
        $this->table(['Table', 'Records'], $tableData);
        $this->line("Total: {$totalEligible} records");
        $this->line('');

        if ($isDryRun) {
            $this->warn('DRY RUN: No records will be archived.');

            return Command::SUCCESS;
        }

        // Confirmation prompt (unless --force is used)
        if (! $this->option('force') && ! $this->confirm("Archive {$totalEligible} records?")) {
            $this->info('Operation cancelled.');

            return Command::SUCCESS;
        }

        // Perform archiving
        $this->info('Archiving records...');

        $progressBar = $this->output->createProgressBar($totalEligible);
        $progressBar->start();

        $results = [];
        $totalArchived = 0;
        $totalFailed = 0;

        if ($specificTable) {
            $result = $archiveService->archiveTable($specificTable, $partitionId, $ignoreRetention);
            $results[$specificTable] = $result;
            $totalArchived += $result->archived;
            $totalFailed += $result->failed;
            $progressBar->advance($result->archived + $result->failed);
        } else {
            // Archive tables in priority order
            foreach ($counts as $table => $count) {
                if ($count > 0) {
                    $result = $archiveService->archiveTable($table, $partitionId, $ignoreRetention);
                    $results[$table] = $result;
                    $totalArchived += $result->archived;
                    $totalFailed += $result->failed;
                    $progressBar->advance($result->archived + $result->failed);
                }
            }
        }

        $progressBar->finish();
        $this->newLine(2);

        // Display results
        $this->info('Archive Results:');
        $resultData = [];
        foreach ($results as $table => $result) {
            $status = $result->isSuccessful() ? '<fg=green>OK</>' : '<fg=red>FAILED</>';
            $resultData[] = [$table, $result->archived, $result->failed, $status];
        }
        $this->table(['Table', 'Archived', 'Failed', 'Status'], $resultData);

        $this->newLine();
        $this->info("Total archived: {$totalArchived}");

        if ($totalFailed > 0) {
            $this->error("Total failed: {$totalFailed}");

            return Command::FAILURE;
        }

        $this->info('Archive operation completed successfully.');

        return Command::SUCCESS;
    }
}
