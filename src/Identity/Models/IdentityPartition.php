<?php

namespace NewSolari\Core\Identity\Models;

use NewSolari\Core\Entity\BaseEntity;
use NewSolari\Core\Entity\Traits\SoftDeleteCascade;

class IdentityPartition extends BaseEntity
{
    use SoftDeleteCascade;

    /**
     * Relationships to cascade soft delete.
     * Note: primaryUsers are users whose partition_id matches this partition.
     * The 'users' relationship is belongsToMany and uses detach() in boot().
     *
     * @var array
     */
    protected static array $cascadeOnDelete = ['primaryUsers', 'entities', 'apps'];

    /**
     * Enable cascade restore.
     *
     * @var bool
     */
    protected static bool $cascadeOnRestore = true;
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'identity_partitions';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'record_id',
        'name',
        'description',
        'is_active',
        'created_at',
        'updated_at',
    ];

    /**
     * The validation rules for the entity.
     *
     * @var array
     */
    protected $validations = [
        'name' => 'required|string|max:255|unique:identity_partitions',
        'description' => 'nullable|string',
        'is_active' => 'boolean',
    ];

    /**
     * Get users whose primary partition is this partition (hasMany).
     * Used for cascade soft delete.
     */
    public function primaryUsers()
    {
        return $this->hasMany(IdentityUser::class, 'partition_id', 'record_id');
    }

    /**
     * Get the users associated with this partition via pivot table (belongsToMany).
     */
    public function users()
    {
        return $this->belongsToMany(
            IdentityUser::class,
            'identity_user_partitions',
            'partition_id',
            'user_id',
            'record_id',  // Local primary key (IdentityPartition)
            'record_id'   // Related primary key (IdentityUser)
        );
    }

    /**
     * Get the entities in this partition.
     */
    public function entities()
    {
        return $this->hasMany(Entity::class, 'partition_id');
    }

    /**
     * Get the app configurations for this partition.
     */
    public function apps()
    {
        return $this->hasMany(PartitionApp::class, 'partition_id', 'record_id');
    }

    /**
     * Update the entity with validation.
     * Override to make required fields optional for updates.
     *
     * @return bool
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function updateWithValidation(array $data)
    {
        // For updates, make required fields optional
        $updateValidations = $this->validations;
        foreach ($updateValidations as $field => $rules) {
            $rules = str_replace('required|', '', $rules);
            $rules = str_replace('required', '', $rules);
            $rules = trim($rules, '|');
            // Handle unique constraint - exclude current record
            if (str_contains($rules, 'unique:')) {
                $rules = preg_replace(
                    '/unique:(\w+)/',
                    'unique:$1,name,'.$this->record_id.',record_id',
                    $rules
                );
            }
            $updateValidations[$field] = $rules;
        }

        // Validate the data with update rules
        if (! empty($updateValidations)) {
            $validator = \Illuminate\Support\Facades\Validator::make($data, $updateValidations);

            if ($validator->fails()) {
                throw new \Illuminate\Validation\ValidationException($validator);
            }
        }

        return $this->update($data);
    }

    /**
     * Check if the user has permission to perform an action within this partition.
     *
     * @param  string  $action  The action to check permission for
     * @param  string|null  $userId  The user ID to check, defaults to authenticated user
     * @return bool
     */
    public function hasPermission(string $action, ?string $userId = null): bool
    {
        $userId = $userId ?? auth()->id();

        if (! $userId) {
            return false;
        }

        $user = IdentityUser::find($userId);

        if (! $user) {
            return false;
        }

        // System users have all permissions
        if ($user->is_system_user) {
            return true;
        }

        // Partition admins have all permissions within their partition
        if ($user->isPartitionAdmin($this->record_id)) {
            return true;
        }

        // For non-admin users, check if they have the specific permission via their groups
        return $user->hasPermission($action);
    }

    /**
     * Boot the model.
     */
    protected static function boot(): void
    {
        parent::boot();

        // Handle cleanup when soft deleting a partition
        // Note: primaryUsers, entities, apps cascade is handled by SoftDeleteCascade trait
        static::deleting(function ($partition) {
            // Only run this logic on soft delete, not force delete
            if (method_exists($partition, 'isForceDeleting') && $partition->isForceDeleting()) {
                return;
            }

            $partitionId = $partition->record_id;

            // Detach pivot table relationships (belongsToMany use detach, not cascade)
            $partition->users()->detach();

            // Nullify/delete all remaining foreign key references to this partition
            $partition->nullifyPartitionReferences($partitionId);
        });
    }

    /**
     * Nullify all foreign key references to this partition in other tables.
     * Dynamically discovers all foreign keys referencing identity_partitions.
     */
    private function nullifyPartitionReferences(string $partitionId): void
    {
        $db = \Illuminate\Support\Facades\DB::connection();
        $driver = $db->getDriverName();

        if ($driver === 'sqlite') {
            $this->nullifyPartitionReferencesSqlite($db, $partitionId);
        } else {
            $this->nullifyPartitionReferencesMysql($db, $partitionId);
        }
    }

    /**
     * Nullify foreign key references for SQLite databases.
     */
    private function nullifyPartitionReferencesSqlite($db, string $partitionId): void
    {
        // Get all tables
        $tables = $db->select("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'");

        foreach ($tables as $tableRow) {
            $tableName = $tableRow->name;

            // Skip the partition history table (we're writing to it)
            if ($tableName === 'identity_partitions_history') {
                continue;
            }

            // Get foreign keys for this table that reference identity_partitions
            $foreignKeys = $db->select(
                "SELECT * FROM pragma_foreign_key_list(?) WHERE \"table\" = 'identity_partitions'",
                [$tableName]
            );

            foreach ($foreignKeys as $fk) {
                $column = $fk->from;
                $onDelete = strtoupper($fk->on_delete ?? 'NO ACTION');

                // CASCADE - will be handled automatically, but delete explicitly to be safe
                if ($onDelete === 'CASCADE') {
                    try {
                        $db->table($tableName)->where($column, $partitionId)->delete();
                    } catch (\Exception $e) {
                        \Log::warning("Could not cascade delete from {$tableName}.{$column}: " . $e->getMessage());
                    }
                    continue;
                }

                // Check if the column is nullable using pragma_table_info
                $columnInfo = $db->selectOne(
                    "SELECT * FROM pragma_table_info(?) WHERE name = ?",
                    [$tableName, $column]
                );

                $isNullable = $columnInfo && $columnInfo->notnull == 0;

                if ($isNullable) {
                    // Column is nullable, set to null
                    try {
                        $db->table($tableName)->where($column, $partitionId)->update([$column => null]);
                    } catch (\Exception $e) {
                        \Log::warning("Could not nullify {$tableName}.{$column}: " . $e->getMessage());
                    }
                } else {
                    // Column is NOT NULL, must delete the rows
                    try {
                        $db->table($tableName)->where($column, $partitionId)->delete();
                        \Log::info("Deleted rows from {$tableName} where {$column} = {$partitionId} (NOT NULL constraint)");
                    } catch (\Exception $e) {
                        \Log::warning("Could not delete from {$tableName}.{$column}: " . $e->getMessage());
                    }
                }
            }
        }
    }

    /**
     * Nullify foreign key references for MySQL/PostgreSQL databases.
     */
    private function nullifyPartitionReferencesMysql($db, string $partitionId): void
    {
        $dbName = $db->getDatabaseName();

        // Query information_schema for all foreign keys referencing identity_partitions
        $foreignKeys = $db->select("
            SELECT
                kcu.TABLE_NAME as table_name,
                kcu.COLUMN_NAME as column_name,
                rc.DELETE_RULE as on_delete,
                c.IS_NULLABLE as is_nullable
            FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE kcu
            JOIN INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS rc
                ON kcu.CONSTRAINT_NAME = rc.CONSTRAINT_NAME
                AND kcu.TABLE_SCHEMA = rc.CONSTRAINT_SCHEMA
            JOIN INFORMATION_SCHEMA.COLUMNS c
                ON kcu.TABLE_NAME = c.TABLE_NAME
                AND kcu.COLUMN_NAME = c.COLUMN_NAME
                AND kcu.TABLE_SCHEMA = c.TABLE_SCHEMA
            WHERE kcu.REFERENCED_TABLE_NAME = 'identity_partitions'
                AND kcu.TABLE_SCHEMA = ?
        ", [$dbName]);

        foreach ($foreignKeys as $fk) {
            $tableName = $fk->table_name;
            $column = $fk->column_name;
            $onDelete = strtoupper($fk->on_delete ?? 'NO ACTION');
            $isNullable = strtoupper($fk->is_nullable ?? 'NO') === 'YES';

            // Skip the partition history table
            if ($tableName === 'identity_partitions_history') {
                continue;
            }

            // CASCADE - handled automatically, but delete explicitly
            if ($onDelete === 'CASCADE') {
                try {
                    $db->table($tableName)->where($column, $partitionId)->delete();
                } catch (\Exception $e) {
                    \Log::warning("Could not cascade delete from {$tableName}.{$column}: " . $e->getMessage());
                }
                continue;
            }

            if ($isNullable) {
                // Column is nullable, set to null
                try {
                    $db->table($tableName)->where($column, $partitionId)->update([$column => null]);
                } catch (\Exception $e) {
                    \Log::warning("Could not nullify {$tableName}.{$column}: " . $e->getMessage());
                }
            } else {
                // Column is NOT NULL, must delete the rows
                try {
                    $db->table($tableName)->where($column, $partitionId)->delete();
                    \Log::info("Deleted rows from {$tableName} where {$column} = {$partitionId} (NOT NULL constraint)");
                } catch (\Exception $e) {
                    \Log::warning("Could not delete from {$tableName}.{$column}: " . $e->getMessage());
                }
            }
        }
    }
}
