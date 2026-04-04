<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Idempotency Key Expiration
    |--------------------------------------------------------------------------
    |
    | The number of seconds before an idempotency key expires. After this time,
    | the same idempotency key can be reused for a new request.
    |
    | Default: 86400 (24 hours)
    | Recommended range: 3600 (1 hour) to 604800 (7 days)
    |
    */

    'expiration_seconds' => (int) env('IDEMPOTENCY_EXPIRATION_SECONDS', 86400),

    /*
    |--------------------------------------------------------------------------
    | Cleanup Batch Size
    |--------------------------------------------------------------------------
    |
    | The number of expired keys to delete in each cleanup batch.
    | Smaller values reduce database load but take longer.
    |
    */

    'cleanup_batch_size' => (int) env('IDEMPOTENCY_CLEANUP_BATCH_SIZE', 1000),

    /*
    |--------------------------------------------------------------------------
    | Enable Logging
    |--------------------------------------------------------------------------
    |
    | Whether to log idempotency key operations. Useful for debugging.
    | Set to false in high-traffic production environments.
    |
    */

    'enable_logging' => (bool) env('IDEMPOTENCY_ENABLE_LOGGING', true),

];
