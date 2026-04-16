<?php

namespace NewSolari\Core\Console;

use NewSolari\Core\Entity\Models\IdempotencyKey;
use Illuminate\Console\Command;

/**
 * Cleanup Idempotency Keys Command
 *
 * API-MED-NEW-007: Removes expired idempotency keys to prevent
 * table bloat. Should be scheduled to run periodically.
 *
 * Usage:
 *   php artisan idempotency:cleanup
 *   php artisan idempotency:cleanup --dry-run
 */
class CleanupIdempotencyKeysCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'idempotency:cleanup
                            {--dry-run : Show what would be deleted without actually deleting}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clean up expired idempotency keys from the database';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $isDryRun = $this->option('dry-run');
        $batchSize = config('idempotency.cleanup_batch_size', 1000);

        $expiredCount = IdempotencyKey::expired()->count();

        if ($expiredCount === 0) {
            $this->info('No expired idempotency keys to clean up.');

            return Command::SUCCESS;
        }

        if ($isDryRun) {
            $this->info("Would delete {$expiredCount} expired idempotency keys.");

            // Show some sample keys that would be deleted
            $samples = IdempotencyKey::expired()
                ->limit(5)
                ->get(['idempotency_key', 'request_path', 'expires_at']);

            if ($samples->isNotEmpty()) {
                $this->table(
                    ['Idempotency Key', 'Request Path', 'Expired At'],
                    $samples->map(fn ($k) => [
                        substr($k->idempotency_key, 0, 36) . '...',
                        substr($k->request_path, 0, 40),
                        $k->expires_at->toDateTimeString(),
                    ])
                );
            }

            return Command::SUCCESS;
        }

        $this->info("Cleaning up {$expiredCount} expired idempotency keys...");

        $progressBar = $this->output->createProgressBar($expiredCount);
        $progressBar->start();

        $totalDeleted = 0;

        // Delete in batches to avoid memory issues
        while (true) {
            $deleted = IdempotencyKey::expired()
                ->limit($batchSize)
                ->delete();

            if ($deleted === 0) {
                break;
            }

            $totalDeleted += $deleted;
            $progressBar->advance($deleted);
        }

        $progressBar->finish();
        $this->newLine();

        $this->info("Successfully deleted {$totalDeleted} expired idempotency keys.");

        return Command::SUCCESS;
    }
}
