<?php

namespace NewSolari\Core\Identity\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Idempotency Key Model
 *
 * Stores idempotency keys for bulk operations to ensure retry-safety.
 * API-MED-NEW-007: Prevents duplicate creation on network retries.
 *
 * @property string $record_id
 * @property string $idempotency_key
 * @property string $user_id
 * @property string $request_path
 * @property string $request_method
 * @property string $request_hash
 * @property int $response_status
 * @property string $response_body
 * @property \Carbon\Carbon $expires_at
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
class IdempotencyKey extends Model
{
    /**
     * The table associated with the model.
     */
    protected $table = 'idempotency_keys';

    /**
     * The primary key for the model.
     */
    protected $primaryKey = 'record_id';

    /**
     * The "type" of the primary key ID.
     */
    protected $keyType = 'string';

    /**
     * Indicates if the IDs are auto-incrementing.
     */
    public $incrementing = false;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<string>
     */
    protected $fillable = [
        'record_id',
        'idempotency_key',
        'user_id',
        'request_path',
        'request_method',
        'request_hash',
        'response_status',
        'response_body',
        'expires_at',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'expires_at' => 'datetime',
        'response_status' => 'integer',
    ];

    /**
     * Boot the model.
     */
    protected static function boot(): void
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->record_id)) {
                $model->record_id = Str::uuid()->toString();
            }
        });
    }

    /**
     * Get the user that owns this idempotency key.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(IdentityUser::class, 'user_id', 'record_id');
    }

    /**
     * Scope a query to only include non-expired keys.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeNotExpired($query)
    {
        return $query->where('expires_at', '>', now());
    }

    /**
     * Scope a query to only include expired keys.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeExpired($query)
    {
        return $query->where('expires_at', '<=', now());
    }

    /**
     * Find an existing idempotency key for a request.
     *
     * @param  string  $idempotencyKey  The client-provided idempotency key
     * @param  string  $userId  The authenticated user's ID
     * @param  string  $requestPath  The request path
     * @param  string  $requestMethod  The HTTP method
     * @return self|null
     */
    public static function findExisting(
        string $idempotencyKey,
        string $userId,
        string $requestPath,
        string $requestMethod
    ): ?self {
        return static::where('idempotency_key', $idempotencyKey)
            ->where('user_id', $userId)
            ->where('request_path', $requestPath)
            ->where('request_method', $requestMethod)
            ->notExpired()
            ->first();
    }

    /**
     * Create a hash of the request body for comparison.
     *
     * @param  mixed  $requestBody  The request body (array or string)
     * @return string SHA-256 hash
     */
    public static function hashRequestBody($requestBody): string
    {
        if (is_array($requestBody)) {
            // Sort keys for consistent hashing regardless of order
            $requestBody = json_encode($requestBody, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        return hash('sha256', (string) $requestBody);
    }

    /**
     * Check if the stored request hash matches a new request.
     *
     * @param  string  $newHash  Hash of the new request body
     * @return bool
     */
    public function matchesRequestHash(string $newHash): bool
    {
        return $this->request_hash === $newHash;
    }

    /**
     * Check if the key has expired.
     *
     * @return bool
     */
    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    /**
     * Get the default expiration time from config.
     *
     * @return int Expiration time in seconds
     */
    public static function getExpirationSeconds(): int
    {
        return (int) config('idempotency.expiration_seconds', 86400);
    }

    /**
     * Delete expired keys (for cleanup command).
     *
     * @return int Number of deleted keys
     */
    public static function deleteExpired(): int
    {
        return static::expired()->delete();
    }
}
