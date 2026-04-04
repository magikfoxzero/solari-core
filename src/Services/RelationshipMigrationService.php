<?php

namespace NewSolari\Core\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class RelationshipMigrationService
{
    protected RelationshipService $relationshipService;

    public function __construct(RelationshipService $relationshipService)
    {
        $this->relationshipService = $relationshipService;
    }

    /**
     * Capture baseline statistics for a legacy table before migration.
     *
     * @return array Baseline statistics
     */
    public function captureBaseline(string $tableName): array
    {
        if (! Schema::hasTable($tableName)) {
            throw new \Exception("Table does not exist: {$tableName}");
        }

        $baseline = [
            'table_name' => $tableName,
            'row_count_total' => DB::table($tableName)->count(),
            'row_count_active' => 0,
            'row_count_deleted' => 0,
            'unique_sources' => 0,
            'unique_targets' => 0,
            'orphan_count' => 0,
            'content_checksum' => null,
        ];

        // Count active and deleted rows if soft deletes exist
        if (Schema::hasColumn($tableName, 'deleted_at')) {
            $baseline['row_count_active'] = DB::table($tableName)
                ->whereNull('deleted_at')
                ->count();
            $baseline['row_count_deleted'] = DB::table($tableName)
                ->whereNotNull('deleted_at')
                ->count();
        } else {
            $baseline['row_count_active'] = $baseline['row_count_total'];
        }

        // Get mapping configuration
        $mapping = $this->getMappingConfig($tableName);

        if ($mapping) {
            // Count unique sources and targets
            $baseline['unique_sources'] = DB::table($tableName)
                ->distinct()
                ->count($mapping['source_column']);

            $baseline['unique_targets'] = DB::table($tableName)
                ->distinct()
                ->count($mapping['target_column']);

            // Count orphaned records (if possible)
            $baseline['orphan_count'] = $this->countOrphans($tableName, $mapping);
        }

        // Create checksum of content
        $baseline['content_checksum'] = $this->generateChecksum($tableName);

        // Store baseline in database
        DB::table('migration_baseline')->insert([
            'table_name' => $tableName,
            'row_count_total' => $baseline['row_count_total'],
            'row_count_active' => $baseline['row_count_active'],
            'row_count_deleted' => $baseline['row_count_deleted'],
            'unique_sources' => $baseline['unique_sources'],
            'unique_targets' => $baseline['unique_targets'],
            'orphan_count' => $baseline['orphan_count'],
            'content_checksum' => $baseline['content_checksum'],
            'migration_status' => 'baseline_captured',
            'captured_at' => now(),
        ]);

        Log::info("Baseline captured for table: {$tableName}", $baseline);

        return $baseline;
    }

    /**
     * Migrate a legacy pivot table to entity_relationships.
     *
     * @param  array|null  $customMapping  Custom mapping configuration
     * @return array Migration results
     */
    public function migrateTable(string $tableName, ?array $customMapping = null): array
    {
        if (! Schema::hasTable($tableName)) {
            throw new \Exception("Table does not exist: {$tableName}");
        }

        // Get or use custom mapping
        $mapping = $customMapping ?? $this->getMappingConfig($tableName);

        if (! $mapping) {
            throw new \Exception("No mapping configuration found for table: {$tableName}");
        }

        // Update migration status
        $this->updateMigrationStatus($tableName, 'migration_in_progress');

        $results = [
            'table_name' => $tableName,
            'rows_migrated' => 0,
            'rows_failed' => 0,
            'errors' => [],
        ];

        $chunkSize = config('relationships.migration.chunk_size', 500);
        $chunkDelay = config('relationships.migration.chunk_delay', 100);

        // Track migrated record IDs for potential rollback
        $migratedRecordIds = [];

        try {
            DB::table($tableName)->orderBy('record_id')->chunk($chunkSize, function ($records) use (
                $mapping,
                &$results,
                &$migratedRecordIds,
                $chunkDelay,
                $tableName
            ) {
                // Wrap each chunk in a transaction for atomicity
                DB::transaction(function () use ($records, $mapping, &$results, &$migratedRecordIds, $tableName) {
                    foreach ($records as $record) {
                        try {
                            $this->migrateRecord($record, $mapping);
                            $results['rows_migrated']++;
                            $migratedRecordIds[] = $record->record_id ?? null;
                        } catch (\Exception $e) {
                            $results['rows_failed']++;
                            $this->logMigrationError($tableName, $record->record_id ?? null, $e);

                            if (config('relationships.migration.stop_on_error', false)) {
                                throw $e;
                            }
                        }
                    }
                });

                // Delay between chunks to reduce database load
                if ($chunkDelay > 0) {
                    usleep($chunkDelay * 1000);
                }
            });

            // Update baseline with migration results
            DB::table('migration_baseline')
                ->where('table_name', $tableName)
                ->latest('captured_at')
                ->update([
                    'migration_status' => 'migration_completed',
                    'migration_completed_at' => now(),
                    'rows_migrated' => $results['rows_migrated'],
                    'rows_failed' => $results['rows_failed'],
                ]);

            Log::info("Migration completed for table: {$tableName}", $results);
        } catch (\Exception $e) {
            $this->updateMigrationStatus($tableName, 'migration_failed');

            // Store migrated record IDs for potential cleanup
            // This allows manual or automated rollback of partially migrated data
            $migrationErrorData = [
                'error' => $e->getMessage(),
                'results' => $results,
                'migrated_record_ids' => $migratedRecordIds,
                'rollback_info' => sprintf(
                    'To rollback, delete relationships created from source_type=%s where source_id in migrated_record_ids',
                    $mapping['source_type'] ?? 'unknown'
                ),
            ];

            Log::error("Migration failed for table: {$tableName}", $migrationErrorData);

            // Store rollback info in cache for potential cleanup
            Cache::put(
                "migration_rollback_{$tableName}",
                [
                    'table' => $tableName,
                    'mapping' => $mapping,
                    'migrated_ids' => $migratedRecordIds,
                    'failed_at' => now()->toDateTimeString(),
                ],
                3600 // 1 hour TTL
            );

            throw $e;
        }

        return $results;
    }

    /**
     * Migrate a single record.
     */
    protected function migrateRecord(object $record, array $mapping): void
    {
        // Extract metadata from configured fields
        $metadata = [];
        foreach ($mapping['metadata_fields'] as $field) {
            if (property_exists($record, $field) && $record->$field !== null) {
                $metadata[$field] = $record->$field;
            }
        }

        // Create relationship
        $this->relationshipService->create(
            $mapping['source_type'],
            $record->{$mapping['source_column']},
            $mapping['target_type'],
            $record->{$mapping['target_column']},
            $mapping['relationship_type'],
            $metadata,
            [
                'partition_id' => $record->partition_id ?? null,
                'created_by' => $record->created_by ?? null,
                'priority' => $record->priority ?? 0,
                'is_primary' => $record->is_primary ?? false,
            ]
        );
    }

    /**
     * Validate migration results.
     *
     * @return array Validation results
     */
    public function validateMigration(string $tableName): array
    {
        $baseline = DB::table('migration_baseline')
            ->where('table_name', $tableName)
            ->latest('captured_at')
            ->first();

        if (! $baseline) {
            throw new \Exception("No baseline found for table: {$tableName}");
        }

        $mapping = $this->getMappingConfig($tableName);
        $errors = [];

        // Count relationships in new table
        $migratedCount = DB::table('entity_relationships')
            ->where('source_type', $mapping['source_type'])
            ->where('relationship_type', $mapping['relationship_type'])
            ->count();

        // Compare counts
        if ($migratedCount !== $baseline->row_count_active) {
            $errors[] = sprintf(
                'Row count mismatch: Expected %d, found %d',
                $baseline->row_count_active,
                $migratedCount
            );
        }

        // Update validation status
        $status = empty($errors) ? 'validation_passed' : 'validation_failed';
        DB::table('migration_baseline')
            ->where('table_name', $tableName)
            ->latest('captured_at')
            ->update([
                'migration_status' => $status,
                'validation_errors' => empty($errors) ? null : implode("\n", $errors),
            ]);

        Log::info("Validation {$status} for table: {$tableName}", [
            'errors' => $errors,
            'expected' => $baseline->row_count_active,
            'actual' => $migratedCount,
        ]);

        return [
            'table_name' => $tableName,
            'status' => $status,
            'errors' => $errors,
            'expected_count' => $baseline->row_count_active,
            'actual_count' => $migratedCount,
        ];
    }

    /**
     * Rollback migration for a table.
     *
     * @return int Number of relationships deleted
     */
    public function rollback(string $tableName): int
    {
        $mapping = $this->getMappingConfig($tableName);

        if (! $mapping) {
            throw new \Exception("No mapping configuration found for table: {$tableName}");
        }

        $deleted = DB::table('entity_relationships')
            ->where('source_type', $mapping['source_type'])
            ->where('relationship_type', $mapping['relationship_type'])
            ->delete();

        // Update migration status
        $this->updateMigrationStatus($tableName, 'rolled_back');

        Log::info("Rollback completed for table: {$tableName}", [
            'relationships_deleted' => $deleted,
        ]);

        return $deleted;
    }

    /**
     * Get mapping configuration for a table.
     */
    protected function getMappingConfig(string $tableName): ?array
    {
        return config("relationships.legacy_mappings.{$tableName}");
    }

    /**
     * Count orphaned records in a table.
     */
    protected function countOrphans(string $tableName, array $mapping): int
    {
        // This is a simplified implementation
        // In production, you'd check for missing foreign key references
        return 0;
    }

    /**
     * Generate a checksum for table content.
     */
    protected function generateChecksum(string $tableName): string
    {
        $data = DB::table($tableName)
            ->select('record_id')
            ->orderBy('record_id')
            ->pluck('record_id')
            ->implode(',');

        return md5($data);
    }

    /**
     * Update migration status in baseline table.
     */
    protected function updateMigrationStatus(string $tableName, string $status): void
    {
        DB::table('migration_baseline')
            ->where('table_name', $tableName)
            ->latest('captured_at')
            ->update([
                'migration_status' => $status,
                'migration_started_at' => $status === 'migration_in_progress' ? now() : DB::raw('migration_started_at'),
            ]);
    }

    /**
     * Log a migration error.
     */
    protected function logMigrationError(string $tableName, ?string $recordId, \Exception $exception): void
    {
        DB::table('migration_errors')->insert([
            'table_name' => $tableName,
            'record_id' => $recordId,
            'error_message' => $exception->getMessage(),
            'error_context' => json_encode([
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
            ]),
            'error_type' => $this->classifyError($exception),
            'occurred_at' => now(),
        ]);

        Log::error("Migration error in {$tableName}", [
            'record_id' => $recordId,
            'error' => $exception->getMessage(),
        ]);
    }

    /**
     * Classify error type.
     */
    protected function classifyError(\Exception $exception): string
    {
        $message = $exception->getMessage();

        if (str_contains($message, 'constraint')) {
            return 'constraint_violation';
        }

        if (str_contains($message, 'validation')) {
            return 'invalid_data';
        }

        return 'other';
    }

    /**
     * Get migration progress for a table.
     */
    public function getProgress(string $tableName): ?array
    {
        $baseline = DB::table('migration_baseline')
            ->where('table_name', $tableName)
            ->latest('captured_at')
            ->first();

        if (! $baseline) {
            return null;
        }

        return [
            'table_name' => $tableName,
            'status' => $baseline->migration_status,
            'total_rows' => $baseline->row_count_active,
            'rows_migrated' => $baseline->rows_migrated,
            'rows_failed' => $baseline->rows_failed,
            'progress_percentage' => $baseline->row_count_active > 0
                ? round(($baseline->rows_migrated / $baseline->row_count_active) * 100, 2)
                : 0,
            'started_at' => $baseline->migration_started_at,
            'completed_at' => $baseline->migration_completed_at,
        ];
    }
}
