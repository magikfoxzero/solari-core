<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Module Discovery
    |--------------------------------------------------------------------------
    |
    | Path to scan for module.json files. Each module must have a
    | backend/module.json file containing id, name, type, and optional
    | frontend manifest.
    |
    | Enable/disable modules via env: MODULE_<SLUG>_ENABLED=false
    | All modules default to enabled.
    |
    */
    'discovery_path' => env('MODULE_DISCOVERY_PATH', base_path('../modules')),

    /*
    | Cache TTL in seconds for discovered module manifests.
    | Set to 0 to disable caching (useful for development).
    | Clear manually with: php artisan module:clear-cache
    */
    'cache_ttl' => (int) env('MODULE_CACHE_TTL', 3600),

    /*
    |--------------------------------------------------------------------------
    | Core Module Toggles
    |--------------------------------------------------------------------------
    |
    | Core modules that don't have module.json files (they live in
    | webos/packages/core/). These need explicit config entries for
    | the module.enabled middleware to find them.
    |
    */
    'system'   => ['enabled' => env('MODULE_SYSTEM_ENABLED', true)],
    'auth'     => ['enabled' => env('MODULE_AUTH_ENABLED', true)],
    'passkeys' => ['enabled' => env('MODULE_PASSKEYS_ENABLED', true)],
];
