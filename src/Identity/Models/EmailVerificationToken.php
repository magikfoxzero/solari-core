<?php

namespace NewSolari\Core\Identity\Models;

use NewSolari\Core\Entity\BaseEntity;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * EmailVerificationToken model for account email verification.
 */
class EmailVerificationToken extends BaseEntity
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'email_verification_tokens';

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
        'record_id',
        'user_id',
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
     * Get the user that owns this verification token.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(IdentityUser::class, 'user_id', 'record_id');
    }

    /**
     * Find a token by its hashed value.
     */
    public static function findByToken(string $hashedToken): ?self
    {
        return static::where('token', $hashedToken)->first();
    }

    /**
     * Delete all tokens for a specific user.
     */
    public static function deleteForUser(string $userId): int
    {
        return static::where('user_id', $userId)->delete();
    }
}
