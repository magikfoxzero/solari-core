<?php

namespace NewSolari\Core\Services;

use NewSolari\Core\Identity\Models\EmailVerificationToken;
use NewSolari\Core\Identity\Models\IdentityUser;
use NewSolari\Core\Identity\Models\PasswordResetToken;
use App\Mail\EmailVerificationMail;
use App\Mail\PasswordResetMail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Service for handling email-based security features:
 * - Password reset tokens
 * - Email verification tokens
 *
 * Security considerations:
 * - Tokens are cryptographically secure (32 bytes of randomness)
 * - Tokens are hashed before storage using SHA-256
 * - Tokens expire after 1 hour
 * - Tokens are single-use (deleted after successful use)
 * - Email enumeration is prevented by returning consistent responses
 */
class EmailSecurityService
{
    /**
     * Token expiration time in seconds (1 hour).
     */
    public const TOKEN_EXPIRATION_SECONDS = 3600;

    /**
     * Token length in bytes (32 bytes = 256 bits of entropy).
     */
    public const TOKEN_BYTES = 32;

    /**
     * Generate a cryptographically secure random token.
     *
     * @return string The raw token (to be sent to user)
     */
    public function generateSecureToken(): string
    {
        return bin2hex(random_bytes(self::TOKEN_BYTES));
    }

    /**
     * Hash a token for secure storage using SHA-256.
     *
     * @param  string  $token  The raw token
     * @return string The hashed token
     */
    public function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * Get the frontend URL from configuration.
     *
     * @return string
     */
    public function getFrontendUrl(): string
    {
        return rtrim(config('app.frontend_url', config('app.url', 'http://localhost:3000')), '/');
    }

    // =========================================================================
    // PASSWORD RESET
    // =========================================================================

    /**
     * Create a password reset token for a user.
     * Replaces any existing token for the same email.
     *
     * @param  string  $email  The user's email address
     * @return string|null The raw token (to send to user), or null if email not found
     */
    public function createPasswordResetToken(string $email, ?string $partitionId = null): ?string
    {
        // Check if user exists with this email - bypass partition scope for auth flows
        $query = IdentityUser::withoutGlobalScope('partition')
            ->where('email', $email);
        if ($partitionId) {
            $query->where('partition_id', $partitionId);
        }
        $user = $query->first();
        if (! $user) {
            Log::info('Password reset requested for non-existent email', [
                'email_hash' => hash('sha256', $email), // Log hash, not actual email
            ]);

            return null;
        }

        // Block password reset for unverified accounts - they must verify email first
        if ($user->needsEmailVerification()) {
            Log::info('Password reset blocked - email verification required', [
                'user_id' => $user->record_id,
                'email_hash' => hash('sha256', $email),
            ]);

            return null;
        }

        $rawToken = $this->generateSecureToken();
        $hashedToken = $this->hashToken($rawToken);

        // Delete any existing token for this email+partition and create new one (atomic operation)
        DB::transaction(function () use ($email, $partitionId, $hashedToken) {
            $query = PasswordResetToken::where('email', $email);
            if ($partitionId) {
                $query->where('partition_id', $partitionId);
            }
            $query->delete();

            PasswordResetToken::create([
                'email' => $email,
                'partition_id' => $partitionId,
                'token' => $hashedToken,
                'created_at' => now(),
            ]);
        });

        Log::info('Password reset token created', [
            'user_id' => $user->record_id,
            'email_hash' => hash('sha256', $email),
        ]);

        return $rawToken;
    }

    /**
     * Validate a password reset token.
     *
     * @param  string  $email  The user's email address
     * @param  string  $token  The raw token from the user
     * @return IdentityUser|null The user if valid, null otherwise
     */
    public function validatePasswordResetToken(string $email, string $token): ?IdentityUser
    {
        $hashedToken = $this->hashToken($token);

        $resetToken = PasswordResetToken::where('email', $email)
            ->where('token', $hashedToken)
            ->first();

        if (! $resetToken) {
            Log::warning('Invalid password reset token attempt', [
                'email_hash' => hash('sha256', $email),
            ]);

            return null;
        }

        // Check expiration
        if ($resetToken->isExpired()) {
            Log::warning('Expired password reset token used', [
                'email_hash' => hash('sha256', $email),
                'created_at' => $resetToken->created_at,
            ]);
            $resetToken->delete();

            return null;
        }

        return $resetToken->user();
    }

    /**
     * Consume (delete) a password reset token after successful use.
     *
     * @param  string  $email  The user's email address
     */
    public function consumePasswordResetToken(string $email, ?string $partitionId = null): void
    {
        $query = PasswordResetToken::where('email', $email);
        if ($partitionId) {
            $query->where(function ($q) use ($partitionId) {
                $q->where('partition_id', $partitionId)
                  ->orWhereNull('partition_id');
            });
        }
        $query->delete();

        Log::info('Password reset token consumed', [
            'email_hash' => hash('sha256', $email),
        ]);
    }

    /**
     * Send a password reset email.
     *
     * @param  string  $email  The user's email address
     * @return bool True if email was sent (or would have been sent), false otherwise
     */
    public function sendPasswordResetEmail(string $email, ?string $partitionId = null): bool
    {
        $token = $this->createPasswordResetToken($email, $partitionId);

        // Even if user doesn't exist, we return true to prevent enumeration
        if (! $token) {
            return true;
        }

        // Bypass partition scope for auth flows
        $query = IdentityUser::withoutGlobalScope('partition')
            ->where('email', $email);
        if ($partitionId) {
            $query->where('partition_id', $partitionId);
        }
        $user = $query->first();
        if (! $user) {
            return true;
        }

        $resetUrl = $this->getFrontendUrl().'/reset-password?token='.$token.'&email='.urlencode($email);

        try {
            Mail::to($email)->send(new PasswordResetMail($user, $resetUrl));

            Log::info('Password reset email sent', [
                'user_id' => $user->record_id,
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error('Failed to send password reset email', [
                'user_id' => $user->record_id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    // =========================================================================
    // EMAIL VERIFICATION
    // =========================================================================

    /**
     * Create an email verification token for a user.
     * Deletes any existing tokens for the same user.
     *
     * @param  IdentityUser  $user  The user to verify
     * @return string The raw token (to send to user)
     */
    public function createEmailVerificationToken(IdentityUser $user): string
    {
        $rawToken = $this->generateSecureToken();
        $hashedToken = $this->hashToken($rawToken);

        // Delete any existing tokens and create new one
        DB::transaction(function () use ($user, $hashedToken) {
            EmailVerificationToken::deleteForUser($user->record_id);

            EmailVerificationToken::create([
                'user_id' => $user->record_id,
                'token' => $hashedToken,
                'created_at' => now(),
            ]);
        });

        Log::info('Email verification token created', [
            'user_id' => $user->record_id,
        ]);

        return $rawToken;
    }

    /**
     * Validate an email verification token.
     *
     * @param  string  $token  The raw token from the user
     * @return IdentityUser|null The user if valid, null otherwise
     */
    public function validateEmailVerificationToken(string $token): ?IdentityUser
    {
        $hashedToken = $this->hashToken($token);

        $verificationToken = EmailVerificationToken::findByToken($hashedToken);

        if (! $verificationToken) {
            Log::warning('Invalid email verification token attempt');

            return null;
        }

        // Check expiration
        if ($verificationToken->isExpired()) {
            Log::warning('Expired email verification token used', [
                'user_id' => $verificationToken->user_id,
                'created_at' => $verificationToken->created_at,
            ]);
            $verificationToken->delete();

            return null;
        }

        return $verificationToken->user;
    }

    /**
     * Consume (delete) an email verification token and mark user as verified.
     *
     * @param  string  $token  The raw token
     * @return IdentityUser|null The verified user, or null if invalid
     */
    public function consumeEmailVerificationToken(string $token): ?IdentityUser
    {
        $user = $this->validateEmailVerificationToken($token);

        if (! $user) {
            return null;
        }

        // Mark user as verified and delete all tokens
        DB::transaction(function () use ($user) {
            $user->markEmailAsVerified();
            EmailVerificationToken::deleteForUser($user->record_id);
        });

        Log::info('Email verified successfully', [
            'user_id' => $user->record_id,
            'email' => $user->email,
        ]);

        return $user;
    }

    /**
     * Send an email verification email.
     *
     * @param  IdentityUser  $user  The user to verify
     * @return bool True if email was sent, false otherwise
     */
    public function sendVerificationEmail(IdentityUser $user): bool
    {
        if (! $user->email) {
            Log::warning('Cannot send verification email - user has no email', [
                'user_id' => $user->record_id,
            ]);

            return false;
        }

        if ($user->hasVerifiedEmail()) {
            Log::info('User already verified, skipping verification email', [
                'user_id' => $user->record_id,
            ]);

            return true;
        }

        $token = $this->createEmailVerificationToken($user);
        $verifyUrl = $this->getFrontendUrl().'/verify-email?token='.$token;

        try {
            Mail::to($user->email)->send(new EmailVerificationMail($user, $verifyUrl));

            Log::info('Verification email sent', [
                'user_id' => $user->record_id,
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error('Failed to send verification email', [
                'user_id' => $user->record_id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Resend verification email for a user by email address.
     *
     * @param  string  $email  The user's email address
     * @return bool True if sent (or would have been sent), false otherwise
     */
    public function resendVerificationEmail(string $email, ?string $partitionId = null): bool
    {
        $query = IdentityUser::withoutGlobalScope('partition')
            ->where('email', $email);

        if ($partitionId) {
            $query->where('partition_id', $partitionId);
        }

        $user = $query->first();

        // Return true even if user doesn't exist to prevent enumeration
        if (! $user) {
            Log::info('Verification resend requested for non-existent email', [
                'email_hash' => hash('sha256', $email),
            ]);

            return true;
        }

        if (! $user->requires_email_verification || $user->hasVerifiedEmail()) {
            return true;
        }

        return $this->sendVerificationEmail($user);
    }

    // =========================================================================
    // ACCOUNT RECOVERY (FOR PASSKEY USERS)
    // =========================================================================

    /**
     * Send account recovery email.
     *
     * Uses the same token pattern as password reset but stores in user table.
     * Always returns true to prevent email enumeration.
     *
     * @param  string  $email  The user's email address
     * @return bool Always true (prevents enumeration)
     */
    public function sendAccountRecoveryEmail(string $email): bool
    {
        // Check if user exists with this email
        $user = IdentityUser::withoutGlobalScope('partition')
            ->where('email', $email)
            ->first();

        if (! $user) {
            Log::info('Account recovery requested for non-existent email', [
                'email_hash' => hash('sha256', $email),
            ]);

            return true; // Prevent enumeration
        }

        // Block account recovery for unverified accounts - they must verify email first
        // Exception: Allow orphan accounts (passkeys_only mode with incomplete registration)
        // since recovery is their only way to complete account setup
        if ($user->needsEmailVerification() && !$user->isOrphanAccount()) {
            Log::info('Account recovery blocked - email verification required', [
                'user_id' => $user->record_id,
                'email_hash' => hash('sha256', $email),
            ]);

            return true; // Prevent enumeration - same response as non-existent email
        }

        // Log if this is an orphan account recovery (allowed despite unverified email)
        if ($user->needsEmailVerification() && $user->isOrphanAccount()) {
            Log::info('Account recovery allowed for orphan account', [
                'user_id' => $user->record_id,
                'email_hash' => hash('sha256', $email),
            ]);
        }

        $rawToken = $this->generateSecureToken();
        $hashedToken = $this->hashToken($rawToken);

        // Store recovery token in user record
        $user->account_recovery_token = $hashedToken;
        $user->account_recovery_expires = now()->addSeconds(
            (int) config('passkeys.recovery_token_ttl', 3600)
        );
        $user->save();

        $recoveryUrl = $this->getFrontendUrl().'/recover-account?token='.$rawToken.'&email='.urlencode($email);

        try {
            Mail::to($email)->send(new \App\Mail\AccountRecoveryMail($user, $recoveryUrl));

            Log::info('Account recovery email sent', [
                'user_id' => $user->record_id,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to send account recovery email', [
                'user_id' => $user->record_id,
                'error' => $e->getMessage(),
            ]);
        }

        return true;
    }

    /**
     * Validate an account recovery token.
     *
     * @param  string  $email  The user's email address
     * @param  string  $token  The raw token from the user
     * @return IdentityUser|null The user if valid, null otherwise
     */
    public function validateAccountRecoveryToken(string $email, string $token): ?IdentityUser
    {
        $hashedToken = $this->hashToken($token);

        $user = IdentityUser::withoutGlobalScope('partition')
            ->where('email', $email)
            ->where('account_recovery_token', $hashedToken)
            ->where('account_recovery_expires', '>', now())
            ->first();

        if (! $user) {
            Log::warning('Invalid account recovery token attempt', [
                'email_hash' => hash('sha256', $email),
            ]);

            return null;
        }

        return $user;
    }

    /**
     * Consume (clear) an account recovery token after successful use.
     *
     * @param  string  $email  The user's email address
     */
    public function consumeAccountRecoveryToken(string $email): void
    {
        $user = IdentityUser::withoutGlobalScope('partition')
            ->where('email', $email)
            ->first();

        if ($user) {
            $user->account_recovery_token = null;
            $user->account_recovery_expires = null;
            $user->save();

            Log::info('Account recovery token consumed', [
                'user_id' => $user->record_id,
            ]);
        }
    }

    // =========================================================================
    // CLEANUP
    // =========================================================================

    /**
     * Clean up expired tokens (for scheduled task).
     *
     * @return array Number of tokens deleted by type
     */
    public function cleanupExpiredTokens(): array
    {
        $expirationTime = now()->subSeconds(self::TOKEN_EXPIRATION_SECONDS);

        $passwordResetDeleted = PasswordResetToken::where('created_at', '<', $expirationTime)->delete();
        $emailVerificationDeleted = EmailVerificationToken::where('created_at', '<', $expirationTime)->delete();

        Log::info('Expired tokens cleaned up', [
            'password_reset_deleted' => $passwordResetDeleted,
            'email_verification_deleted' => $emailVerificationDeleted,
        ]);

        return [
            'password_reset' => $passwordResetDeleted,
            'email_verification' => $emailVerificationDeleted,
        ];
    }
}
