<?php

namespace NewSolari\Core\Identity\Models;

use NewSolari\Core\Constants\ApiConstants;
use NewSolari\Core\Entity\BaseEntity;
use NewSolari\Core\Entity\Traits\SoftDeleteCascade;
use Firebase\JWT\JWT;
use Illuminate\Auth\Authenticatable as AuthenticatableTrait;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use NewSolari\Core\Identity\Models\UserSoftBan;

class IdentityUser extends BaseEntity implements Authenticatable
{
    use AuthenticatableTrait;
    use HasFactory;
    use SoftDeleteCascade;

    /**
     * Relationships to cascade soft delete.
     * Note: emailVerificationTokens doesn't support soft delete (no deleted column)
     * and uses DB-level CASCADE DELETE instead.
     *
     * @var array
     */
    protected static array $cascadeOnDelete = [];

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
    protected $table = 'identity_users';

    /**
     * Default attribute values.
     * Ensures is_system_user defaults to false in-memory (matching DB default)
     * since it is not mass-assignable and may not be explicitly set.
     *
     * @var array
     */
    protected $attributes = [
        'is_system_user' => false,
    ];

    /**
     * Maximum failed login attempts before account lockout.
     */
    public const MAX_FAILED_ATTEMPTS = 10;

    /**
     * Base lockout duration in seconds (doubles with each lockout).
     */
    public const BASE_LOCKOUT_SECONDS = 60;

    /**
     * Maximum lockout duration in seconds (1 hour).
     */
    public const MAX_LOCKOUT_SECONDS = 3600;

    /**
     * Base delay for exponential backoff in seconds.
     */
    public const BASE_DELAY_SECONDS = 1;

    /**
     * The attributes that are mass assignable.
     *
     * SECURITY NOTE: password_hash is safe to include because the setPasswordHashAttribute
     * mutator ALWAYS hashes any value passed to it. Even if an attacker tries to inject
     * a raw bcrypt hash via mass assignment, it will be re-hashed, making it useless.
     *
     * @var array
     */
    protected $fillable = [
        'record_id',
        'partition_id',
        'username',
        'email',
        'password_hash',
        'password_required',
        'remember_token',

        'is_active',
        'first_name',
        'last_name',
        'failed_login_attempts',
        'locked_until',
        'last_failed_login_at',
        'email_verified_at',
        'requires_email_verification',
        'created_at',
        'updated_at',
    ];

    /**
     * The attributes that should be hidden from JSON serialization.
     *
     * @var array
     */
    protected $hidden = [
        'password',
        'password_hash',
        'remember_token',
    ];

    /**
     * The attributes that should be encrypted.
     *
     * @var array
     */
    protected $encrypted = [
        // password_hash is handled separately with hashing, not encryption
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'is_active' => 'boolean',
        'is_system_user' => 'boolean',
        'password_required' => 'boolean',
        'failed_login_attempts' => 'integer',
        'locked_until' => 'datetime',
        'last_failed_login_at' => 'datetime',
        'email_verified_at' => 'datetime',
        'requires_email_verification' => 'boolean',
    ];

    /**
     * The validation rules for the entity.
     *
     * @var array
     */
    protected $validations = [
        'username' => 'required|string|max:255',
        'email' => 'nullable|email|max:255',
        // NOTE: password_hash is nullable to support passkeys_only mode where users
        // register without a password. The raw password is validated in the controller
        // before passing to createWithValidation. The setPasswordHashAttribute mutator hashes
        // the value, so any min:8 validation here would be meaningless (hashes are 60+ chars)
        'password_hash' => 'nullable|string',
        'partition_id' => 'required|string|exists:identity_partitions,record_id',
        'is_active' => 'boolean',
    ];

    /**
     * Set the password attribute.
     *
     * Password validation (required vs optional based on auth mode) is handled
     * by the controller. If null is passed, generates a random unusable hash
     * to satisfy the NOT NULL database constraint while being cryptographically
     * unusable (for passkeys_only mode).
     *
     * @param  string|null  $value
     */
    public function setPasswordHashAttribute($value)
    {
        if ($value === null) {
            // Generate random unusable hash to satisfy NOT NULL constraint
            $value = bin2hex(random_bytes(32));
        }

        $this->attributes['password_hash'] = Hash::make($value);
    }

    /**
     * Explicitly set the system user flag.
     * This is intentionally NOT mass-assignable for defense-in-depth.
     * Only call this from trusted internal code (never from user input).
     */
    public function setSystemUser(bool $value): void
    {
        $this->forceFill(['is_system_user' => $value])->save();
    }

    /**
     * Authenticate the user.
     *
     * @param  string  $password
     * @return bool
     */
    public function authenticate($password)
    {
        // Get the raw hashed password from the database
        $hashedPassword = $this->getRawOriginal('password_hash');

        if (! $hashedPassword) {
            return false;
        }

        // Verify password against bcrypt hash (one-way hashing only, no encryption)
        return Hash::check($password, $hashedPassword);
    }

    /**
     * Check if the user has a specific permission.
     *
     * @param  string  $permissionName  The full permission name in entity.action format (e.g., 'notes.read', 'users.create')
     * @return bool
     */
    public function hasPermission(string $permissionName): bool
    {
        // System users have all permissions
        if ($this->is_system_user) {
            return true;
        }

        // Load permissions if not already loaded
        if (! $this->relationLoaded('permissions')) {
            $this->load('permissions');
        }

        // Check direct user permissions first
        foreach ($this->permissions as $permission) {
            if ($permission->name === $permissionName) {
                return true;
            }
        }

        // Check group permissions (permissions inherited from user's groups)
        if (! $this->relationLoaded('groups')) {
            $this->load('groups.permissions');
        }

        foreach ($this->groups as $group) {
            foreach ($group->permissions as $permission) {
                if ($permission->name === $permissionName) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Check if the user is a partition admin.
     */
    public function isPartitionAdmin(?string $partitionId = null): bool
    {
        // System users are always partition admins
        if ($this->is_system_user) {
            return true;
        }

        // If no partitionId is specified, check if user is in their own partition
        if (! $partitionId) {
            $partitionId = $this->partition_id;
        }

        if (! $partitionId) {
            return false;
        }

        // Load groups with their permissions if not already loaded
        if (! $this->relationLoaded('groups')) {
            $this->load('groups.permissions');
        }

        // Check if user is in any group that:
        // 1. Belongs to the target partition (group.partition_id === $partitionId)
        // 2. Has the system-wide 'partitions.admin' permission
        foreach ($this->groups as $group) {
            // Group must belong to the target partition
            if ($group->partition_id !== $partitionId) {
                continue;
            }

            foreach ($group->permissions as $permission) {
                // Check for system-wide partitions.admin permission
                if ($permission->name === 'partitions.admin') {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Get all partition IDs for which this user is an admin.
     */
    public function getAdminPartitionIds(): array
    {
        // System users are admins of all partitions they belong to
        if ($this->is_system_user) {
            return $this->partitions()->pluck('partition_id')->toArray();
        }

        // Load groups with their permissions if not already loaded
        if (! $this->relationLoaded('groups')) {
            $this->load('groups.permissions');
        }

        $adminPartitionIds = [];

        // Find all groups that have the partitions.admin permission
        // The group's partition_id determines which partition the user is admin of
        foreach ($this->groups as $group) {
            if (! $group->partition_id) {
                continue;
            }

            foreach ($group->permissions as $permission) {
                if ($permission->name === 'partitions.admin') {
                    $adminPartitionIds[] = $group->partition_id;
                    break; // Found admin permission for this group, move to next group
                }
            }
        }

        return array_unique($adminPartitionIds);
    }

    /**
     * Update the entity with validation.
     *
     * @return bool
     *
     * @throws ValidationException
     */
    public function updateWithValidation(array $data)
    {
        // For updates, make required fields optional and handle unique constraints
        $updateValidations = $this->validations;
        foreach ($updateValidations as $field => $rules) {
            $rules = str_replace('required|', '', $rules);
            $rules = str_replace('required', '', $rules);
            $rules = trim($rules, '|');
            // Handle unique constraint - exclude current record
            // unique:table becomes unique:table,column,id,id_column
            if (str_contains($rules, 'unique:')) {
                $rules = preg_replace(
                    '/unique:(\w+)/',
                    'unique:$1,'.$field.','.$this->record_id.',record_id',
                    $rules
                );
            }
            $updateValidations[$field] = $rules;
        }

        // Validate the data with update rules
        if (! empty($updateValidations)) {
            $validator = \Illuminate\Support\Facades\Validator::make($data, $updateValidations);

            if ($validator->fails()) {
                throw new ValidationException($validator);
            }
        }

        // NOTE: Do NOT call encryptFields() here - setAttribute() handles encryption
        // to prevent double encryption when update() calls fill() -> setAttribute()

        return $this->update($data);
    }

    /**
     * Get the user's partitions.
     */
    public function partitions()
    {
        return $this->belongsToMany(
            IdentityPartition::class,
            'identity_user_partitions',
            'user_id',       // FK on pivot pointing to IdentityUser
            'partition_id',  // FK on pivot pointing to IdentityPartition
            'record_id',     // PK on IdentityUser (current model)
            'record_id'      // PK on IdentityPartition (related model)
        );
    }

    /**
     * Get the user's groups.
     */
    public function groups()
    {
        return $this->belongsToMany(
            Group::class,
            'identity_user_groups',
            'user_id',       // FK on pivot pointing to IdentityUser
            'group_id',      // FK on pivot pointing to Group
            'record_id',     // PK on IdentityUser (current model)
            'record_id'      // PK on Group (related model)
        );
    }

    /**
     * Get the user's permissions.
     */
    public function permissions()
    {
        return $this->belongsToMany(
            Permission::class,
            'identity_user_permissions',
            'user_id',         // FK on pivot pointing to IdentityUser
            'permission_id',   // FK on pivot pointing to Permission
            'record_id',       // PK on IdentityUser (current model)
            'record_id'        // PK on Permission (related model)
        );
    }

    /**
     * Assign a permission to the user.
     *
     * @param  string  $permissionId
     * @return bool
     */
    public function assignPermission($permissionId)
    {
        if (! $this->permissions()->where('permission_id', $permissionId)->exists()) {
            $this->permissions()->attach($permissionId);

            return true;
        }

        return false;
    }

    /**
     * Revoke a permission from the user.
     *
     * @param  string  $permissionId
     * @return bool
     */
    public function revokePermission($permissionId)
    {
        return $this->permissions()->detach($permissionId) > 0;
    }

    /**
     * Get the name of the unique identifier for the user.
     *
     * @return string
     */
    public function getAuthIdentifierName()
    {
        return 'record_id';
    }

    /**
     * Get the unique identifier for the user.
     *
     * @return mixed
     */
    public function getAuthIdentifier()
    {
        return $this->record_id;
    }

    /**
     * Get the password for the user.
     *
     * @return string
     */
    public function getAuthPassword()
    {
        return $this->password_hash;
    }

    /**
     * Get the token value for the "remember me" session.
     *
     * @return string
     */
    public function getRememberToken()
    {
        return $this->remember_token;
    }

    /**
     * Set the token value for the "remember me" session.
     *
     * @param  string  $value
     * @return void
     */
    public function setRememberToken($value)
    {
        $this->remember_token = $value;
    }

    /**
     * Get the column name for the "remember me" token.
     *
     * @return string
     */
    public function getRememberTokenName()
    {
        return 'remember_token';
    }

    /**
     * Get the JWT secret key from configuration.
     * SECURITY: JWT_SECRET must always be set - no fallback to APP_KEY.
     *
     * @throws \RuntimeException If JWT_SECRET is not configured
     */
    public static function getJwtSecret(): string
    {
        $secret = config('jwt.secret');

        if (! empty($secret)) {
            return $secret;
        }

        // SECURITY: Always require a separate JWT secret - never fall back to APP_KEY
        throw new \RuntimeException(
            'JWT_SECRET must be set. Generate one with: php -r "echo base64_encode(random_bytes(32));"'
        );
    }

    /**
     * Create a JWT token for API authentication (for testing purposes).
     * Uses firebase/php-jwt library for proper JWT generation.
     *
     * @param  string  $name
     * @param  array  $abilities
     * @return object
     */
    public function createToken($name = 'test-token', $abilities = ['*'])
    {
        $tokenService = app(\NewSolari\Core\Services\OidcTokenService::class);

        $claims = [
            'sub' => $this->record_id,
            'partition_id' => $this->partition_id,
            'username' => $this->username,
            'email' => $this->email,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'display_name' => trim(($this->first_name ?? '') . ' ' . ($this->last_name ?? '')) ?: $this->username,
            'is_system_user' => $this->is_system_user,
            'is_active' => $this->is_active ?? true,
            'is_partition_admin' => $this->isPartitionAdmin($this->partition_id),
        ];

        $token = $tokenService->issueAccessToken($claims);

        $tokenObject = new \stdClass;
        $tokenObject->plainTextToken = $token;
        $tokenObject->token = $token;
        $tokenObject->accessToken = $token;

        return $tokenObject;
    }

    /**
     * Create a new factory instance for the model.
     *
     * @return \NewSolari\Core\Database\Factories\IdentityUserFactory
     */
    protected static function newFactory()
    {
        return \NewSolari\Core\Database\Factories\IdentityUserFactory::new();
    }

    /**
     * Check if the account is currently locked out.
     */
    public function isLockedOut(): bool
    {
        if (! $this->locked_until) {
            return false;
        }

        return $this->locked_until->isFuture();
    }

    /**
     * Get the number of seconds remaining in the lockout period.
     */
    public function getRemainingLockoutSeconds(): int
    {
        if (! $this->isLockedOut()) {
            return 0;
        }

        return max(0, $this->locked_until->diffInSeconds(now()));
    }

    /**
     * Calculate the progressive delay based on failed attempts (exponential backoff).
     * Returns the number of seconds to wait before allowing another attempt.
     *
     * Delay pattern: 0, 1, 2, 4, 8, 16, 32, 60, 60, 60... (capped at 60 seconds)
     */
    public function getLoginDelaySeconds(): int
    {
        if ($this->failed_login_attempts <= 1) {
            return 0;
        }

        // Exponential backoff: 2^(attempts-2) seconds, capped at 60 seconds
        $delay = (int) pow(2, min($this->failed_login_attempts - 2, 6));

        return min($delay, 60);
    }

    /**
     * Check if enough time has passed since last failed attempt (exponential backoff).
     */
    public function canAttemptLogin(): bool
    {
        if ($this->isLockedOut()) {
            return false;
        }

        if (! $this->last_failed_login_at) {
            return true;
        }

        $requiredDelay = $this->getLoginDelaySeconds();
        if ($requiredDelay <= 0) {
            return true;
        }

        $secondsSinceLastFail = $this->last_failed_login_at->diffInSeconds(now());

        return $secondsSinceLastFail >= $requiredDelay;
    }

    /**
     * Record a failed login attempt and apply lockout if threshold reached.
     */
    public function recordFailedLogin(): void
    {
        $this->failed_login_attempts = ($this->failed_login_attempts ?? 0) + 1;
        $this->last_failed_login_at = now();

        // Apply lockout if max attempts exceeded
        if ($this->failed_login_attempts >= self::MAX_FAILED_ATTEMPTS) {
            // Calculate lockout duration with exponential increase based on lockout count
            // Each time the account gets locked, the duration doubles (up to max)
            $lockoutMultiplier = (int) floor($this->failed_login_attempts / self::MAX_FAILED_ATTEMPTS);
            $lockoutSeconds = min(
                self::BASE_LOCKOUT_SECONDS * pow(2, $lockoutMultiplier - 1),
                self::MAX_LOCKOUT_SECONDS
            );

            $this->locked_until = now()->addSeconds($lockoutSeconds);

            \Log::warning('Account locked due to failed login attempts', [
                'user_id' => $this->record_id,
                'username' => $this->username,
                'failed_attempts' => $this->failed_login_attempts,
                'locked_until' => $this->locked_until->toDateTimeString(),
                'lockout_duration_seconds' => $lockoutSeconds,
            ]);
        }

        $this->save();
    }

    /**
     * Record a successful login and reset failed attempt counters.
     */
    public function recordSuccessfulLogin(): void
    {
        // Only update if there were previous failed attempts
        if ($this->failed_login_attempts > 0 || $this->locked_until !== null) {
            $this->failed_login_attempts = 0;
            $this->locked_until = null;
            $this->last_failed_login_at = null;
            $this->save();
        }
    }

    /**
     * Manually unlock the account (for admin use).
     */
    public function unlockAccount(): void
    {
        $this->failed_login_attempts = 0;
        $this->locked_until = null;
        $this->last_failed_login_at = null;
        $this->save();

        \Log::info('Account manually unlocked', [
            'user_id' => $this->record_id,
            'username' => $this->username,
        ]);
    }

    /**
     * Check if the user's email has been verified.
     */
    public function hasVerifiedEmail(): bool
    {
        return $this->email_verified_at !== null;
    }

    /**
     * Mark the user's email as verified.
     */
    public function markEmailAsVerified(): bool
    {
        $this->email_verified_at = now();
        $this->requires_email_verification = false;

        return $this->save();
    }

    /**
     * Check if the user needs to verify their email before logging in.
     * Returns true only if the user was required to verify (created after feature enabled)
     * and has not yet verified.
     */
    public function needsEmailVerification(): bool
    {
        return $this->requires_email_verification && ! $this->hasVerifiedEmail();
    }

    /**
     * Get the email verification tokens for this user.
     */
    public function emailVerificationTokens()
    {
        return $this->hasMany(EmailVerificationToken::class, 'user_id', 'record_id');
    }

    /**
     * Get the user's passkeys.
     */
    public function passkeys()
    {
        return $this->hasMany(UserPasskey::class, 'user_id', 'record_id');
    }

    /**
     * Check if user has any passkeys registered.
     */
    public function hasPasskeys(): bool
    {
        return $this->passkeys()->exists();
    }

    /**
     * Check if this account is an orphan (passkeys_only mode with no usable credentials).
     *
     * An orphan account occurs when a user registers in passkeys_only mode but
     * cancels before completing passkey setup, leaving them with:
     * - No usable password (password_required = false)
     * - No passkeys registered
     */
    public function isOrphanAccount(): bool
    {
        if (config('passkeys.mode', 'hybrid') !== 'passkeys_only') {
            return false;
        }

        return !$this->password_required && !$this->hasPasskeys();
    }

    /**
     * Boot the model.
     */
    protected static function boot(): void
    {
        parent::boot();

        // Handle cleanup when soft deleting a user
        // Note: emailVerificationTokens cascade is handled by SoftDeleteCascade trait
        static::deleting(function ($user) {
            // Only run this logic on soft delete, not force delete
            if (method_exists($user, 'isForceDeleting') && $user->isForceDeleting()) {
                return;
            }

            $userId = $user->record_id;

            // Fire event for plugins to perform cleanup (e.g., cancel subscriptions)
            // Listeners handle their own errors - deletion always proceeds
            event(new \NewSolari\Core\Events\UserDeleting($user));

            // Detach all pivot table relationships (belongsToMany use detach, not cascade)
            $user->partitions()->detach();
            $user->groups()->detach();
            $user->permissions()->detach();

            // Nullify all foreign key references to this user in other tables
            $user->nullifyUserReferences($userId);
        });
    }

    /**
     * Nullify all foreign key references to this user in other tables.
     * Dynamically discovers all foreign keys referencing identity_users.
     */
    private function nullifyUserReferences(string $userId): void
    {
        $db = \Illuminate\Support\Facades\DB::connection();
        $driver = $db->getDriverName();

        if ($driver === 'sqlite') {
            $this->nullifyUserReferencesSqlite($db, $userId);
        } else {
            $this->nullifyUserReferencesMysql($db, $userId);
        }
    }

    /**
     * Nullify foreign key references for SQLite databases.
     * Uses pragma_foreign_key_list to dynamically find all FK references.
     */
    private function nullifyUserReferencesSqlite($db, string $userId): void
    {
        // Get all tables
        $tables = $db->select("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'");

        foreach ($tables as $tableRow) {
            $tableName = $tableRow->name;

            // Get foreign keys for this table that reference identity_users
            $foreignKeys = $db->select(
                "SELECT * FROM pragma_foreign_key_list(?) WHERE \"table\" = 'identity_users'",
                [$tableName]
            );

            foreach ($foreignKeys as $fk) {
                $column = $fk->from;
                $onDelete = strtoupper($fk->on_delete ?? 'NO ACTION');

                // Skip if CASCADE - these will be handled automatically
                if ($onDelete === 'CASCADE') {
                    // But we should explicitly delete to be safe
                    $db->table($tableName)->where($column, $userId)->delete();
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
                        $db->table($tableName)->where($column, $userId)->update([$column => null]);
                    } catch (\Exception $e) {
                        \Log::warning("Could not nullify {$tableName}.{$column}: " . $e->getMessage());
                    }
                } else {
                    // Column is NOT NULL, must delete the rows
                    try {
                        $db->table($tableName)->where($column, $userId)->delete();
                        \Log::info("Deleted rows from {$tableName} where {$column} = {$userId} (NOT NULL constraint)");
                    } catch (\Exception $e) {
                        \Log::warning("Could not delete from {$tableName}.{$column}: " . $e->getMessage());
                    }
                }
            }
        }
    }

    /**
     * Nullify foreign key references for MySQL/PostgreSQL databases.
     * Uses information_schema to dynamically find all FK references.
     */
    private function nullifyUserReferencesMysql($db, string $userId): void
    {
        $dbName = $db->getDatabaseName();

        // Query information_schema for all foreign keys referencing identity_users
        // Include column nullability info
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
            WHERE kcu.REFERENCED_TABLE_NAME = 'identity_users'
                AND kcu.TABLE_SCHEMA = ?
        ", [$dbName]);

        foreach ($foreignKeys as $fk) {
            $tableName = $fk->table_name;
            $column = $fk->column_name;
            $onDelete = strtoupper($fk->on_delete ?? 'NO ACTION');
            $isNullable = strtoupper($fk->is_nullable ?? 'NO') === 'YES';

            // Skip if CASCADE - these will be handled automatically
            if ($onDelete === 'CASCADE') {
                $db->table($tableName)->where($column, $userId)->delete();
                continue;
            }

            if ($isNullable) {
                // Column is nullable, set to null
                try {
                    $db->table($tableName)->where($column, $userId)->update([$column => null]);
                } catch (\Exception $e) {
                    \Log::warning("Could not nullify {$tableName}.{$column}: " . $e->getMessage());
                }
            } else {
                // Column is NOT NULL, must delete the rows
                try {
                    $db->table($tableName)->where($column, $userId)->delete();
                    \Log::info("Deleted rows from {$tableName} where {$column} = {$userId} (NOT NULL constraint)");
                } catch (\Exception $e) {
                    \Log::warning("Could not delete from {$tableName}.{$column}: " . $e->getMessage());
                }
            }
        }
    }

    /**
     * Check if this user is currently soft-banned.
     */
    public function isSoftBanned(): bool
    {
        return UserSoftBan::isUserSoftBanned($this->record_id);
    }
}
