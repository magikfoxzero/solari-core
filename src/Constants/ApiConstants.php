<?php

namespace NewSolari\Core\Constants;

class ApiConstants
{
    // Pagination
    public const PAGINATION_DEFAULT = 15;

    public const PAGINATION_MAX = 100; // Reduced from 200 to prevent performance issues

    public const PAGINATION_MIN = 1;

    public const PAGINATION_MAX_PAGE = 10000;

    // Export
    public const EXPORT_MAX_ROWS = 10000; // Maximum rows allowed in export to prevent DoS

    // Search
    public const SEARCH_TERM_MAX_LENGTH = 1000;

    // Geographic
    public const DEFAULT_RADIUS_KM = 10;

    public const LATITUDE_MIN = -90;

    public const LATITUDE_MAX = 90;

    public const LONGITUDE_MIN = -180;

    public const LONGITUDE_MAX = 180;

    public const RADIUS_MIN = 0.1;

    public const RADIUS_MAX = 200;

    // JWT
    public const JWT_EXPIRATION_SECONDS = 3600;

    public const JWT_MAX_REFRESH_SECONDS = 86400;

    public const JWT_JTI_LENGTH = 32;

    // Rate Limiting
    public const RETRY_AFTER_SECONDS = 300;

    // Validation
    public const STRING_MAX_LENGTH = 255;

    // Relationship
    public const DEFAULT_PRIORITY = 0;
}
