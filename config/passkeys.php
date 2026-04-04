<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Passkey Authentication Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for WebAuthn/Passkey authentication.
    |
    | AUTH_MODE options:
    | - 'passkeys_only': Users must use passkeys. No password option.
    | - 'hybrid' (default): Both passkeys and passwords available.
    | - 'passwords_only': Traditional password auth. Passkeys disabled.
    |
    */

    'enabled' => env('AUTH_MODE', 'hybrid') !== 'passwords_only',

    'mode' => env('AUTH_MODE', 'hybrid'),

    /*
    |--------------------------------------------------------------------------
    | Relying Party Configuration
    |--------------------------------------------------------------------------
    |
    | The relying party (RP) settings identify your application to the
    | authenticator device. The RP ID should be the domain of your application.
    |
    */

    'rp_name' => env('PASSKEY_RP_NAME', 'Solari'),

    // null = use current request host
    'rp_id' => env('PASSKEY_RP_ID'),

    /*
    |--------------------------------------------------------------------------
    | Allowed Origins
    |--------------------------------------------------------------------------
    |
    | Additional origins that are allowed for WebAuthn verification.
    | This is required for Android apps that use the Android Credential Manager.
    |
    | Format: comma-separated list of origins
    | - Web origins: https://app.example.com
    | - Android origins: android:apk-key-hash:<SHA256_HASH>
    |
    | The SHA256 hash should match the signing certificate fingerprint.
    |
    */
    'allowed_origins' => array_filter(explode(',', env('PASSKEY_ALLOWED_ORIGINS', ''))),

    /*
    |--------------------------------------------------------------------------
    | Timeout Configuration
    |--------------------------------------------------------------------------
    |
    | How long (in milliseconds) to wait for user interaction with the
    | authenticator before timing out.
    |
    */

    'timeout' => (int) env('PASSKEY_TIMEOUT', 60000),

    /*
    |--------------------------------------------------------------------------
    | User Verification
    |--------------------------------------------------------------------------
    |
    | Controls whether the authenticator should verify the user.
    | Options: 'required', 'preferred', 'discouraged'
    |
    | 'preferred' is recommended - it uses biometrics/PIN when available
    | but doesn't fail if the device doesn't support it.
    |
    */

    'user_verification' => env('PASSKEY_USER_VERIFICATION', 'preferred'),

    /*
    |--------------------------------------------------------------------------
    | Attestation
    |--------------------------------------------------------------------------
    |
    | Controls whether to request attestation from the authenticator.
    | Options: 'none', 'indirect', 'direct', 'enterprise'
    |
    | 'none' is recommended for most applications - it maximizes privacy
    | and compatibility while still providing secure authentication.
    |
    */

    'attestation' => env('PASSKEY_ATTESTATION', 'none'),

    /*
    |--------------------------------------------------------------------------
    | Challenge TTL
    |--------------------------------------------------------------------------
    |
    | How long (in seconds) to store registration/authentication challenges.
    | Challenges are stored server-side and must be validated within this time.
    |
    */

    'challenge_ttl' => (int) env('PASSKEY_CHALLENGE_TTL', 300), // 5 minutes

    /*
    |--------------------------------------------------------------------------
    | Account Recovery
    |--------------------------------------------------------------------------
    |
    | Configuration for email-based account recovery when passkeys are lost.
    |
    */

    'recovery_token_ttl' => (int) env('PASSKEY_RECOVERY_TOKEN_TTL', 3600), // 1 hour
];
