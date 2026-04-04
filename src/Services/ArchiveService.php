<?php

namespace NewSolari\Core\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Service for archiving soft-deleted records and GDPR purging.
 *
 * Archives records that have been soft-deleted for longer than the configured
 * retention period. Also provides GDPR-compliant hard-delete functionality.
 */
class ArchiveService
{
    /**
     * Archive all eligible records from a specific table.
     *
     * @param  string  $tableName  The source table to archive from
     * @param  string|null  $partitionId  Optional partition to limit archival
     * @param  bool  $ignoreRetention  If true, archives all deleted records regardless of retention period
     * @return ArchiveResult Result containing counts and status
     */
    public function archiveTable(string $tableName, ?string $partitionId = null, bool $ignoreRetention = false): ArchiveResult
    {
        $config = $this->getTableConfig($tableName);

        if (! $config || ! ($config['enabled'] ?? true)) {
            return new ArchiveResult(
                table: $tableName,
                archived: 0,
                failed: 0,
                skipped: 0,
                message: 'Table not configured or disabled for archiving'
            );
        }

        $retentionDays = config('archive.retention_days', 30);
        $batchSize = config('archive.batch_size', 500);
        $chunkDelayMs = config('archive.chunk_delay_ms', 100);
        $archiveTable = $this->getArchiveTableName($tableName);

        // Verify archive table exists
        if (! Schema::hasTable($archiveTable)) {
            return new ArchiveResult(
                table: $tableName,
                archived: 0,
                failed: 0,
                skipped: 0,
                message: "Archive table '{$archiveTable}' does not exist"
            );
        }

        $cutoffDate = $ignoreRetention ? now() : now()->subDays($retentionDays);
        $totalArchived = 0;
        $totalFailed = 0;

        try {
            // Process in batches
            while (true) {
                $query = DB::table($tableName)
                    ->where('deleted', true);

                // Only apply retention filter if not ignoring it
                if (! $ignoreRetention) {
                    $query->where('updated_at', '<', $cutoffDate);
                }

                if ($partitionId) {
                    $query->where('partition_id', $partitionId);
                }

                $records = $query->limit($batchSize)->get();

                if ($records->isEmpty()) {
                    break;
                }

                $batchResult = $this->archiveBatch($tableName, $archiveTable, $records);
                $totalArchived += $batchResult['archived'];
                $totalFailed += $batchResult['failed'];

                // Delay between batches to reduce database load
                if ($chunkDelayMs > 0) {
                    usleep($chunkDelayMs * 1000);
                }
            }

            // Handle cascade children
            $cascadeTables = $config['cascade'] ?? [];
            foreach ($cascadeTables as $childTable) {
                $childResult = $this->archiveOrphanedChildren($childTable, $tableName, $partitionId);
                $totalArchived += $childResult['archived'];
                $totalFailed += $childResult['failed'];
            }

            $this->logArchiveOperation($tableName, $partitionId, $totalArchived, $totalFailed);

            return new ArchiveResult(
                table: $tableName,
                archived: $totalArchived,
                failed: $totalFailed,
                skipped: 0,
                message: 'Archive completed successfully'
            );
        } catch (\Exception $e) {
            Log::error('Archive operation failed', [
                'table' => $tableName,
                'partition_id' => $partitionId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return new ArchiveResult(
                table: $tableName,
                archived: $totalArchived,
                failed: $totalFailed,
                skipped: 0,
                message: 'Archive failed: '.$e->getMessage()
            );
        }
    }

    /**
     * Archive all configured tables.
     *
     * @param  string|null  $partitionId  Optional partition to limit archival
     * @param  bool  $ignoreRetention  If true, archives all deleted records regardless of retention period
     * @return array<ArchiveResult> Results for each table
     */
    public function archiveAll(?string $partitionId = null, bool $ignoreRetention = false): array
    {
        $results = [];
        $tables = $this->getEnabledTables();

        // Sort by priority
        uasort($tables, fn ($a, $b) => ($a['priority'] ?? 99) <=> ($b['priority'] ?? 99));

        foreach ($tables as $tableName => $config) {
            $results[$tableName] = $this->archiveTable($tableName, $partitionId, $ignoreRetention);
        }

        return $results;
    }

    /**
     * Get count of records eligible for archiving.
     *
     * @param  string  $tableName  The table to check
     * @param  string|null  $partitionId  Optional partition filter
     * @param  bool  $ignoreRetention  If true, counts all deleted records regardless of retention period
     * @return int Number of eligible records
     */
    public function getEligibleCount(string $tableName, ?string $partitionId = null, bool $ignoreRetention = false): int
    {
        $query = DB::table($tableName)
            ->where('deleted', true);

        // Only apply retention filter if not ignoring it
        if (! $ignoreRetention) {
            $retentionDays = config('archive.retention_days', 30);
            $cutoffDate = now()->subDays($retentionDays);
            $query->where('updated_at', '<', $cutoffDate);
        }

        if ($partitionId) {
            $query->where('partition_id', $partitionId);
        }

        return $query->count();
    }

    /**
     * Get counts for all configured tables.
     *
     * @param  string|null  $partitionId  Optional partition filter
     * @param  bool  $ignoreRetention  If true, counts all deleted records regardless of retention period
     * @return array<string, int> Table name => eligible count
     */
    public function getAllEligibleCounts(?string $partitionId = null, bool $ignoreRetention = false): array
    {
        $counts = [];
        $tables = $this->getEnabledTables();

        foreach ($tables as $tableName => $config) {
            if (Schema::hasTable($tableName)) {
                $counts[$tableName] = $this->getEligibleCount($tableName, $partitionId, $ignoreRetention);
            }
        }

        return $counts;
    }

    /**
     * GDPR: Permanently delete a specific record from both main and archive tables.
     *
     * @param  string  $tableName  The table containing the record
     * @param  string  $recordId  The record_id to purge
     * @return GdprPurgeResult Result with deletion counts
     */
    public function gdprPurge(string $tableName, string $recordId): GdprPurgeResult
    {
        if (! config('archive.gdpr.enabled', true)) {
            return new GdprPurgeResult(
                success: false,
                mainDeleted: 0,
                archiveDeleted: 0,
                cascadeDeleted: 0,
                message: 'GDPR purge is disabled'
            );
        }

        $archiveTable = $this->getArchiveTableName($tableName);
        $mainDeleted = 0;
        $archiveDeleted = 0;
        $cascadeDeleted = 0;

        try {
            return DB::transaction(function () use ($tableName, $archiveTable, $recordId, &$mainDeleted, &$archiveDeleted, &$cascadeDeleted) {
                // Delete from archive table first (if exists)
                if (Schema::hasTable($archiveTable)) {
                    $archiveDeleted = DB::table($archiveTable)
                        ->where('original_record_id', $recordId)
                        ->delete();
                }

                // Delete from main table
                $mainDeleted = DB::table($tableName)
                    ->where('record_id', $recordId)
                    ->delete();

                // Handle cascade deletions
                $config = $this->getTableConfig($tableName);
                $cascadeTables = $config['cascade'] ?? [];

                foreach ($cascadeTables as $childTable) {
                    $cascadeDeleted += $this->gdprPurgeCascade($childTable, $tableName, $recordId);
                }

                // Log for audit
                if (config('archive.gdpr.audit_log', true)) {
                    $this->logGdprPurge($tableName, $recordId, $mainDeleted, $archiveDeleted, $cascadeDeleted);
                }

                return new GdprPurgeResult(
                    success: true,
                    mainDeleted: $mainDeleted,
                    archiveDeleted: $archiveDeleted,
                    cascadeDeleted: $cascadeDeleted,
                    message: 'GDPR purge completed successfully'
                );
            });
        } catch (\Exception $e) {
            Log::error('GDPR purge failed', [
                'table' => $tableName,
                'record_id' => $recordId,
                'error' => $e->getMessage(),
            ]);

            return new GdprPurgeResult(
                success: false,
                mainDeleted: 0,
                archiveDeleted: 0,
                cascadeDeleted: 0,
                message: 'GDPR purge failed: '.$e->getMessage()
            );
        }
    }

    /**
     * GDPR: Purge all records created by a specific user.
     *
     * @param  string  $userId  The user's record_id
     * @return GdprPurgeResult Result with deletion counts
     */
    public function gdprPurgeByUser(string $userId): GdprPurgeResult
    {
        if (! config('archive.gdpr.enabled', true)) {
            return new GdprPurgeResult(
                success: false,
                mainDeleted: 0,
                archiveDeleted: 0,
                cascadeDeleted: 0,
                message: 'GDPR purge is disabled'
            );
        }

        $totalMainDeleted = 0;
        $totalArchiveDeleted = 0;
        $totalCascadeDeleted = 0;
        $tables = $this->getEnabledTables();

        try {
            return DB::transaction(function () use ($userId, $tables, &$totalMainDeleted, &$totalArchiveDeleted, &$totalCascadeDeleted) {
                foreach ($tables as $tableName => $config) {
                    if (! Schema::hasTable($tableName)) {
                        continue;
                    }

                    // Check if table has created_by column
                    if (! Schema::hasColumn($tableName, 'created_by')) {
                        continue;
                    }

                    $archiveTable = $this->getArchiveTableName($tableName);

                    // Delete from archive table first
                    if (Schema::hasTable($archiveTable)) {
                        $totalArchiveDeleted += DB::table($archiveTable)
                            ->where('created_by', $userId)
                            ->delete();
                    }

                    // Delete from main table
                    $totalMainDeleted += DB::table($tableName)
                        ->where('created_by', $userId)
                        ->delete();
                }

                // Log for audit
                if (config('archive.gdpr.audit_log', true)) {
                    $this->logGdprPurgeByUser($userId, $totalMainDeleted, $totalArchiveDeleted);
                }

                return new GdprPurgeResult(
                    success: true,
                    mainDeleted: $totalMainDeleted,
                    archiveDeleted: $totalArchiveDeleted,
                    cascadeDeleted: $totalCascadeDeleted,
                    message: 'GDPR purge by user completed successfully'
                );
            });
        } catch (\Exception $e) {
            Log::error('GDPR purge by user failed', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);

            return new GdprPurgeResult(
                success: false,
                mainDeleted: 0,
                archiveDeleted: 0,
                cascadeDeleted: 0,
                message: 'GDPR purge failed: '.$e->getMessage()
            );
        }
    }

    /**
     * Permanently delete old archived records that have exceeded the purge retention period.
     *
     * @param  string  $tableName  The source table name (archive table will be derived)
     * @param  bool  $ignoreRetention  If true, deletes all archived records regardless of age
     * @return PurgeResult Result containing counts and status
     */
    public function purgeArchiveTable(string $tableName, bool $ignoreRetention = false): PurgeResult
    {
        $purgeDays = config('archive.archive_purge_days');

        // If purge days is not set and not ignoring retention, purging is disabled
        if (! $ignoreRetention && (! $purgeDays || $purgeDays <= 0)) {
            return new PurgeResult(
                table: $tableName,
                purged: 0,
                failed: 0,
                message: 'Archive purging is disabled (archive_purge_days not set)'
            );
        }

        $archiveTable = $this->getArchiveTableName($tableName);

        if (! Schema::hasTable($archiveTable)) {
            return new PurgeResult(
                table: $tableName,
                purged: 0,
                failed: 0,
                message: "Archive table '{$archiveTable}' does not exist"
            );
        }

        $batchSize = config('archive.batch_size', 500);
        $chunkDelayMs = config('archive.chunk_delay_ms', 100);
        $cutoffDate = $ignoreRetention ? now() : now()->subDays($purgeDays);
        $totalPurged = 0;
        $totalFailed = 0;

        try {
            while (true) {
                $query = DB::table($archiveTable);

                if (! $ignoreRetention) {
                    $query->where('archived_at', '<', $cutoffDate);
                }

                $recordIds = $query->limit($batchSize)->pluck('archive_id')->toArray();

                if (empty($recordIds)) {
                    break;
                }

                try {
                    $deleted = DB::table($archiveTable)
                        ->whereIn('archive_id', $recordIds)
                        ->delete();
                    $totalPurged += $deleted;
                } catch (\Exception $e) {
                    $totalFailed += count($recordIds);
                    Log::warning('Failed to purge archive batch', [
                        'table' => $archiveTable,
                        'error' => $e->getMessage(),
                    ]);
                }

                if ($chunkDelayMs > 0) {
                    usleep($chunkDelayMs * 1000);
                }
            }

            $this->logPurgeOperation($tableName, $totalPurged, $totalFailed);

            return new PurgeResult(
                table: $tableName,
                purged: $totalPurged,
                failed: $totalFailed,
                message: 'Purge completed successfully'
            );
        } catch (\Exception $e) {
            Log::error('Archive purge operation failed', [
                'table' => $tableName,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return new PurgeResult(
                table: $tableName,
                purged: $totalPurged,
                failed: $totalFailed,
                message: 'Purge failed: '.$e->getMessage()
            );
        }
    }

    /**
     * Purge old records from all archive tables.
     *
     * @param  bool  $ignoreRetention  If true, deletes all archived records regardless of age
     * @return array<PurgeResult> Results for each table
     */
    public function purgeAllArchives(bool $ignoreRetention = false): array
    {
        $results = [];
        $tables = $this->getEnabledTables();

        // Sort by priority (reverse order - purge lower priority first)
        uasort($tables, fn ($a, $b) => ($b['priority'] ?? 99) <=> ($a['priority'] ?? 99));

        foreach ($tables as $tableName => $config) {
            $results[$tableName] = $this->purgeArchiveTable($tableName, $ignoreRetention);
        }

        return $results;
    }

    /**
     * Get count of archived records eligible for purging.
     *
     * @param  string  $tableName  The source table name
     * @param  bool  $ignoreRetention  If true, counts all archived records regardless of age
     * @return int Number of eligible records
     */
    public function getPurgeEligibleCount(string $tableName, bool $ignoreRetention = false): int
    {
        $archiveTable = $this->getArchiveTableName($tableName);

        if (! Schema::hasTable($archiveTable)) {
            return 0;
        }

        $purgeDays = config('archive.archive_purge_days');

        // If purge days is not set and not ignoring retention, return 0
        if (! $ignoreRetention && (! $purgeDays || $purgeDays <= 0)) {
            return 0;
        }

        $query = DB::table($archiveTable);

        if (! $ignoreRetention) {
            $cutoffDate = now()->subDays($purgeDays);
            $query->where('archived_at', '<', $cutoffDate);
        }

        return $query->count();
    }

    /**
     * Get purge-eligible counts for all configured tables.
     *
     * @param  bool  $ignoreRetention  If true, counts all archived records regardless of age
     * @return array<string, int> Table name => eligible count
     */
    public function getAllPurgeEligibleCounts(bool $ignoreRetention = false): array
    {
        $counts = [];
        $tables = $this->getEnabledTables();

        foreach ($tables as $tableName => $config) {
            $counts[$tableName] = $this->getPurgeEligibleCount($tableName, $ignoreRetention);
        }

        return $counts;
    }

    /**
     * Archive a batch of records.
     */
    protected function archiveBatch(string $sourceTable, string $archiveTable, Collection $records): array
    {
        $archived = 0;
        $failed = 0;

        foreach ($records as $record) {
            try {
                DB::transaction(function () use ($sourceTable, $archiveTable, $record, &$archived) {
                    // Convert to array and add archive metadata
                    $data = (array) $record;
                    $originalRecordId = $data['record_id'];

                    // Rename record_id to original_record_id for archive
                    unset($data['record_id']);
                    $data['original_record_id'] = $originalRecordId;
                    $data['archived_at'] = now();
                    $data['archived_by'] = 'system-archive-daemon';

                    // Insert into archive table
                    DB::table($archiveTable)->insert($data);

                    // Delete from source table
                    DB::table($sourceTable)
                        ->where('record_id', $originalRecordId)
                        ->delete();

                    $archived++;
                });
            } catch (\Exception $e) {
                $failed++;
                Log::warning('Failed to archive record', [
                    'table' => $sourceTable,
                    'record_id' => $record->record_id ?? 'unknown',
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return ['archived' => $archived, 'failed' => $failed];
    }

    /**
     * Archive orphaned child records (children whose parents have been archived).
     */
    protected function archiveOrphanedChildren(string $childTable, string $parentTable, ?string $partitionId): array
    {
        $archived = 0;
        $failed = 0;

        // Skip if child table doesn't exist or isn't configured
        if (! Schema::hasTable($childTable)) {
            return ['archived' => $archived, 'failed' => $failed];
        }

        $childArchiveTable = $this->getArchiveTableName($childTable);
        if (! Schema::hasTable($childArchiveTable)) {
            return ['archived' => $archived, 'failed' => $failed];
        }

        // Get the foreign key column name (convention: {singular_parent}_id)
        $parentFk = $this->getParentForeignKey($parentTable);

        // Check if child table has this foreign key
        if (! Schema::hasColumn($childTable, $parentFk)) {
            return ['archived' => $archived, 'failed' => $failed];
        }

        $batchSize = config('archive.batch_size', 500);
        $chunkDelayMs = config('archive.chunk_delay_ms', 100);

        // Find child records where parent no longer exists in main table
        while (true) {
            $query = DB::table($childTable)
                ->whereNotExists(function ($q) use ($parentTable, $childTable, $parentFk) {
                    $q->select(DB::raw(1))
                        ->from($parentTable)
                        ->whereColumn("{$parentTable}.record_id", '=', "{$childTable}.{$parentFk}");
                })
                ->where("{$childTable}.deleted", true);

            if ($partitionId) {
                $query->where("{$childTable}.partition_id", $partitionId);
            }

            $orphans = $query->limit($batchSize)->get();

            if ($orphans->isEmpty()) {
                break;
            }

            $batchResult = $this->archiveBatch($childTable, $childArchiveTable, $orphans);
            $archived += $batchResult['archived'];
            $failed += $batchResult['failed'];

            if ($chunkDelayMs > 0) {
                usleep($chunkDelayMs * 1000);
            }
        }

        return ['archived' => $archived, 'failed' => $failed];
    }

    /**
     * GDPR cascade deletion for child tables.
     */
    protected function gdprPurgeCascade(string $childTable, string $parentTable, string $parentRecordId): int
    {
        if (! Schema::hasTable($childTable)) {
            return 0;
        }

        $parentFk = $this->getParentForeignKey($parentTable);

        if (! Schema::hasColumn($childTable, $parentFk)) {
            return 0;
        }

        $deleted = 0;
        $childArchiveTable = $this->getArchiveTableName($childTable);

        // Delete from child archive table
        if (Schema::hasTable($childArchiveTable)) {
            // Need to find original record IDs first
            $childRecordIds = DB::table($childTable)
                ->where($parentFk, $parentRecordId)
                ->pluck('record_id')
                ->toArray();

            if (! empty($childRecordIds)) {
                $deleted += DB::table($childArchiveTable)
                    ->whereIn('original_record_id', $childRecordIds)
                    ->delete();
            }
        }

        // Delete from child main table
        $deleted += DB::table($childTable)
            ->where($parentFk, $parentRecordId)
            ->delete();

        return $deleted;
    }

    /**
     * Get the archive table name for a source table.
     */
    protected function getArchiveTableName(string $sourceTable): string
    {
        return $sourceTable.'_archive';
    }

    /**
     * Get the parent foreign key column name.
     */
    protected function getParentForeignKey(string $parentTable): string
    {
        // Convert plural table name to singular and add _id
        // e.g., tasks -> task_id, entities -> entity_id
        $singular = rtrim($parentTable, 's');
        if (str_ends_with($parentTable, 'ies')) {
            $singular = substr($parentTable, 0, -3).'y';
        }

        return $singular.'_id';
    }

    /**
     * Get configuration for a specific table.
     */
    protected function getTableConfig(string $tableName): ?array
    {
        return config("archive.tables.{$tableName}");
    }

    /**
     * Get all enabled tables.
     */
    protected function getEnabledTables(): array
    {
        $tables = config('archive.tables', []);

        return array_filter($tables, fn ($config) => $config['enabled'] ?? true);
    }

    /**
     * Log archive operation.
     */
    protected function logArchiveOperation(string $table, ?string $partitionId, int $archived, int $failed): void
    {
        if (! config('archive.logging.enabled', true)) {
            return;
        }

        Log::channel(config('archive.logging.channel', 'single'))->info('Archive operation completed', [
            'table' => $table,
            'partition_id' => $partitionId,
            'archived' => $archived,
            'failed' => $failed,
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    /**
     * Log GDPR purge operation.
     */
    protected function logGdprPurge(string $table, string $recordId, int $mainDeleted, int $archiveDeleted, int $cascadeDeleted): void
    {
        Log::channel(config('archive.logging.channel', 'single'))->info('GDPR purge completed', [
            'operation' => 'gdpr_purge',
            'table' => $table,
            'record_id' => $recordId,
            'main_deleted' => $mainDeleted,
            'archive_deleted' => $archiveDeleted,
            'cascade_deleted' => $cascadeDeleted,
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    /**
     * Log GDPR purge by user operation.
     */
    protected function logGdprPurgeByUser(string $userId, int $mainDeleted, int $archiveDeleted): void
    {
        Log::channel(config('archive.logging.channel', 'single'))->info('GDPR purge by user completed', [
            'operation' => 'gdpr_purge_by_user',
            'user_id' => $userId,
            'main_deleted' => $mainDeleted,
            'archive_deleted' => $archiveDeleted,
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    /**
     * Log archive purge operation.
     */
    protected function logPurgeOperation(string $table, int $purged, int $failed): void
    {
        if (! config('archive.logging.enabled', true)) {
            return;
        }

        Log::channel(config('archive.logging.channel', 'single'))->info('Archive purge operation completed', [
            'operation' => 'archive_purge',
            'table' => $table,
            'purged' => $purged,
            'failed' => $failed,
            'timestamp' => now()->toIso8601String(),
        ]);
    }
}

/**
 * Result object for archive operations.
 */
class ArchiveResult
{
    public function __construct(
        public readonly string $table,
        public readonly int $archived,
        public readonly int $failed,
        public readonly int $skipped,
        public readonly string $message,
    ) {}

    public function isSuccessful(): bool
    {
        return $this->failed === 0 && ! str_starts_with($this->message, 'Archive failed');
    }

    public function toArray(): array
    {
        return [
            'table' => $this->table,
            'archived' => $this->archived,
            'failed' => $this->failed,
            'skipped' => $this->skipped,
            'message' => $this->message,
            'success' => $this->isSuccessful(),
        ];
    }
}

/**
 * Result object for GDPR purge operations.
 */
class GdprPurgeResult
{
    public function __construct(
        public readonly bool $success,
        public readonly int $mainDeleted,
        public readonly int $archiveDeleted,
        public readonly int $cascadeDeleted,
        public readonly string $message,
    ) {}

    public function getTotalDeleted(): int
    {
        return $this->mainDeleted + $this->archiveDeleted + $this->cascadeDeleted;
    }

    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'main_deleted' => $this->mainDeleted,
            'archive_deleted' => $this->archiveDeleted,
            'cascade_deleted' => $this->cascadeDeleted,
            'total_deleted' => $this->getTotalDeleted(),
            'message' => $this->message,
        ];
    }
}

/**
 * Result object for archive purge operations.
 */
class PurgeResult
{
    public function __construct(
        public readonly string $table,
        public readonly int $purged,
        public readonly int $failed,
        public readonly string $message,
    ) {}

    public function isSuccessful(): bool
    {
        return $this->failed === 0 && ! str_starts_with($this->message, 'Purge failed');
    }

    public function toArray(): array
    {
        return [
            'table' => $this->table,
            'purged' => $this->purged,
            'failed' => $this->failed,
            'message' => $this->message,
            'success' => $this->isSuccessful(),
        ];
    }
}
