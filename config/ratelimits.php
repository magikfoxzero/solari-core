<?php

/**
 * Rate Limiting Configuration
 *
 * All rate limiting values should be configured here and read from environment variables.
 * This provides a single source of truth for all rate limits across the application.
 */

return [
    /*
    |--------------------------------------------------------------------------
    | Global Rate Limiting (RateLimitingMiddleware)
    |--------------------------------------------------------------------------
    |
    | These settings apply to the global rate limiting middleware that runs
    | on all API requests.
    |
    */
    'global' => [
        'max_attempts' => (int) env('RATE_LIMIT_MAX_ATTEMPTS', 120),
        'decay_minutes' => (int) env('RATE_LIMIT_DECAY_MINUTES', 1),
        // Safety bounds - prevent misconfiguration from causing security issues
        'min_attempts' => (int) env('RATE_LIMIT_MIN_ATTEMPTS', 10),
        'max_attempts_limit' => (int) env('RATE_LIMIT_MAX_ATTEMPTS_LIMIT', 10000),
        'min_decay' => (int) env('RATE_LIMIT_MIN_DECAY', 1),
        'max_decay' => (int) env('RATE_LIMIT_MAX_DECAY', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | Email Rate Limiting (EmailRateLimitMiddleware)
    |--------------------------------------------------------------------------
    |
    | Stricter limits for email-related endpoints (password reset, verification)
    | to prevent email bombing attacks.
    |
    */
    'email' => [
        'max_attempts' => (int) env('EMAIL_RATE_LIMIT_MAX_ATTEMPTS', 3),
        'decay_minutes' => (int) env('EMAIL_RATE_LIMIT_DECAY_MINUTES', 1),
    ],

    /*
    |--------------------------------------------------------------------------
    | Authentication Route Limits
    |--------------------------------------------------------------------------
    |
    | Rate limits for authentication-related endpoints.
    |
    */
    'auth' => [
        // Login: stricter limit to prevent brute force
        'login' => [
            'max_attempts' => (int) env('RATE_LIMIT_AUTH_LOGIN', 10),
            'decay_minutes' => (int) env('RATE_LIMIT_AUTH_LOGIN_DECAY', 1),
        ],
        // Token refresh: reasonable for SPA usage patterns
        'refresh' => [
            'max_attempts' => (int) env('RATE_LIMIT_AUTH_REFRESH', 30),
            'decay_minutes' => (int) env('RATE_LIMIT_AUTH_REFRESH_DECAY', 1),
        ],
        // Registration: allows testing, still prevents mass abuse
        'register' => [
            'max_attempts' => (int) env('RATE_LIMIT_AUTH_REGISTER', 20),
            'decay_minutes' => (int) env('RATE_LIMIT_AUTH_REGISTER_DECAY', 1),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Passkey Route Limits
    |--------------------------------------------------------------------------
    |
    | Rate limits for WebAuthn/passkey endpoints.
    |
    */
    'passkey' => [
        // Authentication attempts
        'authenticate' => [
            'max_attempts' => (int) env('RATE_LIMIT_PASSKEY_AUTH', 30),
            'decay_minutes' => (int) env('RATE_LIMIT_PASSKEY_AUTH_DECAY', 1),
        ],
        // Registration (needs headroom for recovery flow)
        'register' => [
            'max_attempts' => (int) env('RATE_LIMIT_PASSKEY_REGISTER', 15),
            'decay_minutes' => (int) env('RATE_LIMIT_PASSKEY_REGISTER_DECAY', 1),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Public Endpoint Limits
    |--------------------------------------------------------------------------
    |
    | Rate limits for public read-only endpoints.
    |
    */
    'public' => [
        // Public partition list, config endpoints, etc.
        'read' => [
            'max_attempts' => (int) env('RATE_LIMIT_PUBLIC_READ', 60),
            'decay_minutes' => (int) env('RATE_LIMIT_PUBLIC_READ_DECAY', 1),
        ],
        // Record shares read
        'shares_read' => [
            'max_attempts' => (int) env('RATE_LIMIT_SHARES_READ', 30),
            'decay_minutes' => (int) env('RATE_LIMIT_SHARES_READ_DECAY', 1),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | File Upload Limits
    |--------------------------------------------------------------------------
    |
    | Stricter limits for file upload endpoints.
    |
    */
    'upload' => [
        'max_attempts' => (int) env('RATE_LIMIT_UPLOAD', 5),
        'decay_minutes' => (int) env('RATE_LIMIT_UPLOAD_DECAY', 1),
    ],

    /*
    |--------------------------------------------------------------------------
    | Standard Write Operation Limits
    |--------------------------------------------------------------------------
    |
    | Limits for standard POST/PUT/DELETE operations.
    |
    */
    'write' => [
        'standard' => [
            'max_attempts' => (int) env('RATE_LIMIT_WRITE_STANDARD', 30),
            'decay_minutes' => (int) env('RATE_LIMIT_WRITE_STANDARD_DECAY', 1),
        ],
        'strict' => [
            'max_attempts' => (int) env('RATE_LIMIT_WRITE_STRICT', 10),
            'decay_minutes' => (int) env('RATE_LIMIT_WRITE_STRICT_DECAY', 1),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Bottles/Message in a Bottle Limits
    |--------------------------------------------------------------------------
    |
    | Rate limits for the Message in a Bottle feature.
    |
    */
    'bottles' => [
        // Spam detection service limits
        'max_per_hour' => (int) env('RATE_LIMIT_BOTTLES_PER_HOUR', 10),
        'max_duplicates_per_day' => (int) env('RATE_LIMIT_BOTTLES_DUPLICATES_PER_DAY', 2),
        'min_interval_seconds' => (int) env('RATE_LIMIT_BOTTLES_MIN_INTERVAL', 30),
        'global_duplicate_threshold' => (int) env('RATE_LIMIT_BOTTLES_GLOBAL_DUPLICATES', 5),
        // User history check thresholds
        'suspicious_rejections_per_week' => (int) env('RATE_LIMIT_BOTTLES_SUSPICIOUS_REJECTIONS', 3),
        'min_bottles_for_ratio_check' => (int) env('RATE_LIMIT_BOTTLES_MIN_FOR_RATIO', 5),
    ],

    /*
    |--------------------------------------------------------------------------
    | Pen Pal Limits
    |--------------------------------------------------------------------------
    |
    | Rate limits for the Pen Pal feature.
    |
    */
    'penpal' => [
        'messages' => [
            'max_attempts' => (int) env('RATE_LIMIT_PENPAL_MESSAGES', 20),
            'decay_minutes' => (int) env('RATE_LIMIT_PENPAL_MESSAGES_DECAY', 1),
        ],
        'actions' => [
            'max_attempts' => (int) env('RATE_LIMIT_PENPAL_ACTIONS', 30),
            'decay_minutes' => (int) env('RATE_LIMIT_PENPAL_ACTIONS_DECAY', 1),
        ],
        'report' => [
            'max_attempts' => (int) env('RATE_LIMIT_PENPAL_REPORT', 5),
            'decay_minutes' => (int) env('RATE_LIMIT_PENPAL_REPORT_DECAY', 1),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Places/Nearby Limits
    |--------------------------------------------------------------------------
    */
    'places' => [
        'nearby' => [
            'max_attempts' => (int) env('RATE_LIMIT_PLACES_NEARBY', 20),
            'decay_minutes' => (int) env('RATE_LIMIT_PLACES_NEARBY_DECAY', 1),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Chat Command Limits
    |--------------------------------------------------------------------------
    |
    | Rate limits for chat commands like /claude.
    |
    */
    'chat' => [
        'ai_commands' => [
            'max_attempts' => (int) env('RATE_LIMIT_CHAT_AI', 5),
            'decay_seconds' => (int) env('RATE_LIMIT_CHAT_AI_DECAY', 60),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | External API Limits
    |--------------------------------------------------------------------------
    |
    | Rate limits for external API services.
    |
    */
    'external' => [
        'transcriptapi' => [
            'rate_limit_per_minute' => (int) env('TRANSCRIPTAPI_RATE_LIMIT', 200),
            'request_delay_ms' => (int) env('TRANSCRIPTAPI_REQUEST_DELAY_MS', 300),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Push Notifications Limits
    |--------------------------------------------------------------------------
    */
    'push' => [
        'test' => [
            'max_attempts' => (int) env('RATE_LIMIT_PUSH_TEST', 5),
            'decay_minutes' => (int) env('RATE_LIMIT_PUSH_TEST_DECAY', 1),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | AI/Prompts Limits
    |--------------------------------------------------------------------------
    */
    'prompts' => [
        'test' => [
            'max_attempts' => (int) env('RATE_LIMIT_PROMPTS_TEST', 10),
            'decay_minutes' => (int) env('RATE_LIMIT_PROMPTS_TEST_DECAY', 1),
        ],
        'suggest' => [
            'max_attempts' => (int) env('RATE_LIMIT_PROMPTS_SUGGEST', 5),
            'decay_minutes' => (int) env('RATE_LIMIT_PROMPTS_SUGGEST_DECAY', 1),
        ],
        'import' => [
            'max_attempts' => (int) env('RATE_LIMIT_PROMPTS_IMPORT', 5),
            'decay_minutes' => (int) env('RATE_LIMIT_PROMPTS_IMPORT_DECAY', 1),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Quality Preview Limits
    |--------------------------------------------------------------------------
    */
    'quality' => [
        'preview' => [
            'max_attempts' => (int) env('RATE_LIMIT_QUALITY_PREVIEW', 5),
            'decay_minutes' => (int) env('RATE_LIMIT_QUALITY_PREVIEW_DECAY', 1),
        ],
    ],
];
