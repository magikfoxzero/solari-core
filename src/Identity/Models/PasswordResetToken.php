<?php

namespace NewSolari\Core\Identity\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * PasswordResetToken model for secure password reset flow.
 *
 * Uses composite primary key (email, partition_id) since the same email
 * can exist in multiple partitions with separate reset tokens.
 */
class PasswordResetToken extends Model
{
    protected $table = 'password_reset_tokens';

    /**
     * Composite primary key — Eloquent doesn't natively support composite PKs,
     * so we disable auto-incrementing and use manual queries where needed.
     */
    protected $primaryKey = 'email';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = [
        'email',
        'partition_id',
        'token',
        'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    /**
     * Token expiration time in seconds (1 hour).
     */
    public const TOKEN_EXPIRATION_SECONDS = 3600;

    /**
     * Check if this token has expired.
     */
    public function isExpired(): bool
    {
        if (! $this->created_at) {
            return true;
        }

        return $this->created_at->addSeconds(self::TOKEN_EXPIRATION_SECONDS)->isPast();
    }

    /**
     * Get the user associated with this reset token.
     * Scoped to partition for multi-tenant isolation.
     */
    public function user()
    {
        $query = IdentityUser::withoutGlobalScope('partition')
            ->where('email', $this->email);

        if ($this->partition_id) {
            $query->where('partition_id', $this->partition_id);
        }

        return $query->first();
    }

    /**
     * Find a token by its hashed value.
     */
    public static function findByToken(string $hashedToken): ?self
    {
        return static::where('token', $hashedToken)->first();
    }
}
