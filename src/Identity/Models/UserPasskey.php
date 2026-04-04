<?php

namespace NewSolari\Core\Identity\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserPasskey extends Model
{
    use HasUuids;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'user_passkeys';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<string>
     */
    protected $fillable = [
        'user_id',
        'credential_id',
        'credential_id_hash',
        'public_key',
        'sign_count',
        'transports',
        'device_name',
        'aaguid',
        'last_used_at',
    ];

    /**
     * Boot the model.
     */
    protected static function boot(): void
    {
        parent::boot();

        // Automatically compute credential_id_hash when saving
        static::saving(function (UserPasskey $passkey) {
            if ($passkey->credential_id !== null && $passkey->isDirty('credential_id')) {
                $passkey->credential_id_hash = hash('sha256', $passkey->credential_id);
            }
        });
    }

    /**
     * Find a passkey by credential ID.
     * Uses the hash index for efficient lookups.
     */
    public static function findByCredentialId(string $credentialId): ?self
    {
        $hash = hash('sha256', $credentialId);

        return static::where('credential_id_hash', $hash)
            ->where('credential_id', $credentialId) // Double-check for hash collisions
            ->first();
    }

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'transports' => 'array',
        'sign_count' => 'integer',
        'last_used_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * The attributes that should be hidden from arrays/JSON.
     *
     * @var array<string>
     */
    protected $hidden = [
        'credential_id',
        'public_key',
    ];

    /**
     * Get the user that owns the passkey.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(IdentityUser::class, 'user_id', 'record_id');
    }

    /**
     * Get the credential ID as base64url encoded string.
     */
    public function getCredentialIdBase64Attribute(): string
    {
        return rtrim(strtr(base64_encode($this->credential_id), '+/', '-_'), '=');
    }

    /**
     * Update the sign count and last used timestamp.
     *
     * @throws \RuntimeException if the new sign count is not greater than current (potential cloned authenticator)
     */
    public function updateSignCount(int $newSignCount): void
    {
        // Validate sign count to detect cloned authenticators
        // Sign count should always increase (or stay 0 for devices that don't support it)
        if ($this->sign_count > 0 && $newSignCount > 0 && $newSignCount <= $this->sign_count) {
            \Illuminate\Support\Facades\Log::warning('Potential cloned authenticator detected', [
                'passkey_id' => $this->id,
                'user_id' => $this->user_id,
                'stored_sign_count' => $this->sign_count,
                'received_sign_count' => $newSignCount,
            ]);
            throw new \RuntimeException('Authenticator verification failed');
        }

        $this->sign_count = $newSignCount;
        $this->last_used_at = now();
        $this->save();
    }

    /**
     * Convert the model to an array for API responses.
     */
    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'device_name' => $this->device_name,
            'transports' => $this->transports,
            'created_at' => $this->created_at?->toIso8601String(),
            'last_used_at' => $this->last_used_at?->toIso8601String(),
        ];
    }
}
