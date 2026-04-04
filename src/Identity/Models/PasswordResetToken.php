<?php

namespace NewSolari\Core\Identity\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * PasswordResetToken model for secure password reset flow.
 *
 * Note: This model uses email as the primary key (not UUID) since
 * only one reset token per email address is allowed at a time.
 */
class PasswordResetToken extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'password_reset_tokens';

    /**
     * The primary key for the model.
     *
     * @var string
     */
    protected $primaryKey = 'email';

    /**
     * The "type" of the primary key ID.
     *
     * @var string
     */
    protected $keyType = 'string';

    /**
     * Indicates if the IDs are auto-incrementing.
     *
     * @var bool
     */
    public $incrementing = false;

    /**
     * Indicates if the model should be timestamped.
     * We only use created_at, not updated_at.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'email',
        'token',
        'created_at',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
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
     */
    public function user()
    {
        return IdentityUser::where('email', $this->email)->first();
    }

    /**
     * Find a token by its hashed value.
     */
    public static function findByToken(string $hashedToken): ?self
    {
        return static::where('token', $hashedToken)->first();
    }
}
