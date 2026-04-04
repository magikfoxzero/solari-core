<?php

/**
 * Security Middleware Configuration
 *
 * This configuration file controls the security middleware settings including
 * IP access control lists, rich text sanitization, and request size limits.
 *
 * @see App\Http\Middleware\SecurityMiddleware
 */

return [
    /*
    |--------------------------------------------------------------------------
    | IP Access Control
    |--------------------------------------------------------------------------
    |
    | Configure IP whitelisting and blacklisting for API access.
    | Supports individual IPs and CIDR notation (e.g., '192.168.1.0/24').
    |
    | Whitelist: If non-empty, ONLY these IPs can access the API.
    |            Set to empty array to disable whitelist (allow all).
    |
    | Blacklist: These IPs are ALWAYS denied access, even if whitelisted.
    |
    */

    'ip_whitelist' => array_filter(
        explode(',', env('SECURITY_IP_WHITELIST', '')),
        fn ($ip) => !empty(trim($ip))
    ),

    'ip_blacklist' => array_filter(
        explode(',', env('SECURITY_IP_BLACKLIST', '')),
        fn ($ip) => !empty(trim($ip))
    ),

    /*
    |--------------------------------------------------------------------------
    | Rich Text Fields
    |--------------------------------------------------------------------------
    |
    | Field names that should allow limited HTML content (with sanitization).
    | All other fields will have HTML completely stripped.
    |
    */

    'rich_text_fields' => [
        'notes',
        'description',
        'content',
        'body',
    ],

    /*
    |--------------------------------------------------------------------------
    | Allowed HTML Tags
    |--------------------------------------------------------------------------
    |
    | HTML tags that are permitted in rich text fields. All other tags
    | will be removed during sanitization.
    |
    */

    'allowed_tags' => [
        'p', 'br', 'strong', 'em', 'u', 'ul', 'ol', 'li', 'a',
    ],

    /*
    |--------------------------------------------------------------------------
    | Enhanced Rich Text Routes
    |--------------------------------------------------------------------------
    |
    | Routes that should allow enhanced HTML including images. These routes
    | bypass the standard sanitization and use a more permissive set of
    | allowed tags and attributes (including img with data: URLs).
    |
    */

    'enhanced_rich_text_routes' => [
        'api/news',
    ],

    'enhanced_allowed_tags' => [
        'p', 'br', 'strong', 'em', 'u', 'ul', 'ol', 'li', 'a',
        'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
        'blockquote', 'code', 'pre', 'hr',
        'img', 's', 'span', 'div',
    ],

    'enhanced_allowed_attributes' => [
        'a' => ['href', 'title', 'target', 'rel'],
        'img' => ['src', 'alt', 'title', 'class', 'width', 'data-size'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Allowed HTML Attributes
    |--------------------------------------------------------------------------
    |
    | Attributes that are permitted on specific HTML tags. Attributes not
    | listed here will be stripped from the tag.
    |
    */

    'allowed_attributes' => [
        'a' => ['href', 'title'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Maximum Request Size
    |--------------------------------------------------------------------------
    |
    | Maximum allowed request body size in bytes. Default is 2GB to support
    | large file uploads. Set to 0 for unlimited (not recommended).
    |
    */

    'max_request_size' => env('SECURITY_MAX_REQUEST_SIZE', 2147483648), // 2GB default
];
