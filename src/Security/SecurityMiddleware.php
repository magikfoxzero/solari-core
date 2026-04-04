<?php

namespace NewSolari\Core\Security;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Comprehensive Security Middleware
 *
 * Handles:
 * - XSS Prevention via HTML sanitization
 * - IP Whitelist/Blacklist (ACL-style)
 * - Input validation and sanitization
 *
 * Note: CSRF protection is handled by VerifyCsrfToken middleware
 * using the double-submit cookie pattern (XSRF-TOKEN cookie vs X-XSRF-TOKEN header).
 */
class SecurityMiddleware
{
    /**
     * IP Whitelist (if set, only these IPs are allowed)
     * Empty array = whitelist disabled
     * Loaded from config/security.php or SECURITY_IP_WHITELIST env var
     */
    protected array $ipWhitelist;

    /**
     * IP Blacklist (these IPs are always denied)
     * Loaded from config/security.php or SECURITY_IP_BLACKLIST env var
     */
    protected array $ipBlacklist;

    /**
     * Fields that should allow some HTML (with sanitization)
     * All other fields will have HTML completely stripped
     * Loaded from config/security.php
     */
    protected array $richTextFields;

    /**
     * Allowed HTML tags for rich text fields
     * Loaded from config/security.php
     */
    protected array $allowedTags;

    /**
     * Allowed attributes for HTML tags
     * Loaded from config/security.php
     */
    protected array $allowedAttributes;

    /**
     * Maximum allowed request body size
     * Loaded from config/security.php or SECURITY_MAX_REQUEST_SIZE env var
     */
    protected int $maxRequestSize;

    /**
     * Routes that should allow enhanced HTML (with images, etc.)
     * Loaded from config/security.php
     */
    protected array $enhancedRichTextRoutes;

    /**
     * Enhanced allowed tags for specific routes
     * Loaded from config/security.php
     */
    protected array $enhancedAllowedTags;

    /**
     * Enhanced allowed attributes for specific routes
     * Loaded from config/security.php
     */
    protected array $enhancedAllowedAttributes;

    /**
     * Whether the current request is for an enhanced rich text route
     */
    protected bool $isEnhancedRoute = false;

    /**
     * Cached HTMLPurifier instances (lazily initialized)
     */
    protected ?\HTMLPurifier $plainTextPurifier = null;
    protected ?\HTMLPurifier $richTextPurifier = null;
    protected ?\HTMLPurifier $enhancedRichTextPurifier = null;

    /**
     * Create a new SecurityMiddleware instance.
     * Loads configuration from config/security.php
     */
    public function __construct()
    {
        // Load from config with fallbacks to default values
        $this->ipWhitelist = config('security.ip_whitelist', []);
        $this->ipBlacklist = config('security.ip_blacklist', []);
        $this->richTextFields = config('security.rich_text_fields', ['notes', 'description', 'content', 'body']);
        $this->allowedTags = config('security.allowed_tags', ['p', 'br', 'strong', 'em', 'u', 'ul', 'ol', 'li', 'a']);
        $this->allowedAttributes = config('security.allowed_attributes', ['a' => ['href', 'title']]);
        $this->maxRequestSize = (int) config('security.max_request_size', 2147483648);

        // Enhanced settings for specific routes (e.g., News with embedded images)
        $this->enhancedRichTextRoutes = config('security.enhanced_rich_text_routes', []);
        $this->enhancedAllowedTags = config('security.enhanced_allowed_tags', $this->allowedTags);
        $this->enhancedAllowedAttributes = config('security.enhanced_allowed_attributes', $this->allowedAttributes);

        // Ensure HTMLPurifier cache directory exists
        $cachePath = storage_path('framework/cache/htmlpurifier');
        if (!is_dir($cachePath)) {
            @mkdir($cachePath, 0775, true);
        }
    }

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // 1. Check IP ACLs (whitelist/blacklist)
        if (! $this->checkIpAccess($request)) {
            Log::warning('IP access denied', [
                'ip' => $request->ip(),
                'path' => $request->path(),
                'method' => $request->method(),
            ]);

            return response()->json([
                'value' => false,
                'result' => 'Access denied',
                'code' => 403,
            ], 403);
        }

        // 2. Check if this is an enhanced rich text route (e.g., News)
        $this->isEnhancedRoute = $this->isEnhancedRichTextRoute($request);

        // 3. Validate Content-Type for POST/PUT/PATCH requests
        if ($request->isMethod('POST') || $request->isMethod('PUT') || $request->isMethod('PATCH')) {
            $contentTypeError = $this->validateContentType($request);
            if ($contentTypeError) {
                return $contentTypeError;
            }

            // 4. Validate request size
            $sizeError = $this->validateRequestSize($request);
            if ($sizeError) {
                return $sizeError;
            }

            // 5. Sanitize input data to prevent XSS
            $this->sanitizeInput($request);
        }

        return $next($request);
    }

    /**
     * Validate Content-Type header for POST/PUT/PATCH requests
     *
     * API-MED-NEW-011: Handle edge cases per RFC 7231:
     * - Case insensitivity (APPLICATION/JSON, Application/Json)
     * - Whitespace handling
     * - Charset parameters (application/json; charset=utf-8)
     * - +json suffix types (application/json-patch+json per RFC 6902)
     * - Alternative JSON types (text/json)
     */
    protected function validateContentType(Request $request): ?Response
    {
        $rawContentType = $request->header('Content-Type', '');
        $contentType = $this->normalizeContentType($rawContentType);

        // Skip validation for truly empty requests (no body)
        if (empty($request->getContent())) {
            return null;
        }

        // Allow multipart/form-data for file uploads
        if (str_starts_with($contentType, 'multipart/form-data')) {
            return null;
        }

        // Allow application/x-www-form-urlencoded for simple forms
        if (str_starts_with($contentType, 'application/x-www-form-urlencoded')) {
            return null;
        }

        // Allow application/json and text/json
        if ($contentType === 'application/json' || $contentType === 'text/json') {
            return null;
        }

        // Handle +json suffix types (RFC 6838) - e.g., application/json-patch+json
        if (preg_match('/^application\/[\w.-]+\+json$/', $contentType)) {
            return null;
        }

        // Fall back to Laravel's isJson() detection
        if ($request->isJson()) {
            return null;
        }

        // If request has content but invalid Content-Type, reject it
        if (! empty($request->getContent())) {
            Log::warning('Invalid Content-Type header', [
                'content_type' => $rawContentType,
                'normalized' => $contentType,
                'path' => $request->path(),
                'method' => $request->method(),
                'ip' => $request->ip(),
            ]);

            return response()->json([
                'value' => false,
                'result' => 'Invalid Content-Type. Expected application/json, multipart/form-data, or application/x-www-form-urlencoded',
                'code' => 415,
            ], 415);
        }

        return null;
    }

    /**
     * Normalize Content-Type header for consistent comparison.
     *
     * API-MED-NEW-011: Handles RFC 7231 edge cases:
     * - Converts to lowercase
     * - Trims whitespace
     * - Extracts media type (removes charset and other parameters)
     * - Validates format (type/subtype)
     */
    protected function normalizeContentType(string $contentType): string
    {
        // Trim and convert to lowercase
        $contentType = strtolower(trim($contentType));

        // Extract media type (part before semicolon)
        if (str_contains($contentType, ';')) {
            $parts = explode(';', $contentType, 2);
            $contentType = trim($parts[0]);
        }

        // Validate format: type/subtype (RFC 7231)
        // Allow word chars, dots, dashes, and plus signs
        if (! empty($contentType) && ! preg_match('/^[\w!#$&\-^.+]+\/[\w!#$&\-^.+]+$/', $contentType)) {
            return '';
        }

        return $contentType;
    }

    /**
     * Validate request body size
     */
    protected function validateRequestSize(Request $request): ?Response
    {
        $contentLength = $request->header('Content-Length');

        // If Content-Length header is present, validate it
        if ($contentLength !== null && (int) $contentLength > $this->maxRequestSize) {
            Log::warning('Request size exceeded', [
                'content_length' => $contentLength,
                'max_size' => $this->maxRequestSize,
                'path' => $request->path(),
                'method' => $request->method(),
                'ip' => $request->ip(),
            ]);

            $sizeMb = $this->maxRequestSize / 1048576;
            $sizeDisplay = $sizeMb >= 1024 ? round($sizeMb / 1024, 1).' GB' : $sizeMb.' MB';

            return response()->json([
                'value' => false,
                'result' => 'Request body too large. Maximum size is '.$sizeDisplay,
                'code' => 413,
            ], 413);
        }

        return null;
    }

    /**
     * Check IP whitelist/blacklist (ACL-style processing)
     *
     * Top-down processing:
     * 1. If in blacklist -> DENY
     * 2. If whitelist enabled and not in whitelist -> DENY
     * 3. Otherwise -> ALLOW
     */
    protected function checkIpAccess(Request $request): bool
    {
        $clientIp = $request->ip();

        // Rule 1: Blacklist takes precedence (explicit deny)
        if ($this->isIpInList($clientIp, $this->ipBlacklist)) {
            return false;
        }

        // Rule 2: If whitelist is enabled, IP must be in whitelist
        if (! empty($this->ipWhitelist)) {
            return $this->isIpInList($clientIp, $this->ipWhitelist);
        }

        // Rule 3: Default allow (no whitelist configured)
        return true;
    }

    /**
     * Check if IP is in given list (supports CIDR notation)
     */
    protected function isIpInList(string $ip, array $list): bool
    {
        foreach ($list as $range) {
            if ($this->ipMatchesRange($ip, $range)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if IP matches a range (supports CIDR notation for IPv4 and IPv6)
     */
    protected function ipMatchesRange(string $ip, string $range): bool
    {
        // Exact match
        if ($ip === $range) {
            return true;
        }

        // CIDR notation (e.g., 192.168.1.0/24 or 2001:db8::/32)
        if (strpos($range, '/') !== false) {
            [$subnet, $mask] = explode('/', $range);
            $mask = (int) $mask;

            // Determine IP version
            $isIpv6 = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
            $isSubnetIpv6 = filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;

            // IP and subnet must be same version
            if ($isIpv6 !== $isSubnetIpv6) {
                return false;
            }

            if ($isIpv6) {
                // IPv6 CIDR matching using inet_pton()
                return $this->ipv6CidrMatch($ip, $subnet, $mask);
            } else {
                // IPv4 CIDR matching
                $ipLong = ip2long($ip);
                $subnetLong = ip2long($subnet);

                // Handle invalid IP/subnet
                if ($ipLong === false || $subnetLong === false) {
                    return false;
                }

                $maskLong = -1 << (32 - $mask);

                return ($ipLong & $maskLong) === ($subnetLong & $maskLong);
            }
        }

        return false;
    }

    /**
     * Check if IPv6 address matches CIDR range
     */
    protected function ipv6CidrMatch(string $ip, string $subnet, int $mask): bool
    {
        $ipBin = inet_pton($ip);
        $subnetBin = inet_pton($subnet);

        // Handle invalid addresses
        if ($ipBin === false || $subnetBin === false) {
            return false;
        }

        // Calculate how many full bytes and remaining bits to compare
        $fullBytes = intdiv($mask, 8);
        $remainingBits = $mask % 8;

        // Compare full bytes
        for ($i = 0; $i < $fullBytes; $i++) {
            if ($ipBin[$i] !== $subnetBin[$i]) {
                return false;
            }
        }

        // Compare remaining bits if any
        if ($remainingBits > 0 && $fullBytes < 16) {
            $bitMask = 0xFF << (8 - $remainingBits);
            if ((ord($ipBin[$fullBytes]) & $bitMask) !== (ord($subnetBin[$fullBytes]) & $bitMask)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Sanitize all input data to prevent XSS attacks
     */
    protected function sanitizeInput(Request $request): void
    {
        $input = $request->all();
        $sanitized = $this->sanitizeArray($input);
        $request->merge($sanitized);
    }

    /**
     * Recursively sanitize an array
     */
    protected function sanitizeArray(array $data, string $parentKey = ''): array
    {
        $sanitized = [];

        foreach ($data as $key => $value) {
            $fullKey = $parentKey ? "{$parentKey}.{$key}" : $key;

            if (is_array($value)) {
                $sanitized[$key] = $this->sanitizeArray($value, $fullKey);
            } elseif (is_string($value)) {
                $sanitized[$key] = $this->sanitizeString($value, $fullKey);
            } else {
                $sanitized[$key] = $value;
            }
        }

        return $sanitized;
    }

    /**
     * Sanitize a string value based on field type
     */
    protected function sanitizeString(string $value, string $fieldKey): string
    {
        // Check if this is a rich text field that should preserve some HTML
        $fieldName = $this->getFieldName($fieldKey);

        if (in_array($fieldName, $this->richTextFields)) {
            return $this->sanitizeRichText($value);
        }

        // For all other fields, strip ALL HTML tags
        return $this->sanitizePlainText($value);
    }

    /**
     * Get the base field name from a dotted key (e.g., "addresses.0.notes" -> "notes")
     */
    protected function getFieldName(string $key): string
    {
        $parts = explode('.', $key);

        return end($parts);
    }

    /**
     * Sanitize plain text fields - strip ALL HTML
     */
    protected function sanitizePlainText(string $value): string
    {
        return $this->getPlainTextPurifier()->purify($value);
    }

    /**
     * Sanitize rich text fields - allow safe HTML only
     */
    protected function sanitizeRichText(string $value): string
    {
        return $this->getRichTextPurifier()->purify($value);
    }

    /**
     * Get or create the plain text HTMLPurifier instance (strips all HTML).
     */
    private function getPlainTextPurifier(): \HTMLPurifier
    {
        if ($this->plainTextPurifier === null) {
            $config = \HTMLPurifier_Config::createDefault();
            $config->set('HTML.Allowed', '');
            $config->set('Cache.SerializerPath', storage_path('framework/cache/htmlpurifier'));
            $this->plainTextPurifier = new \HTMLPurifier($config);
        }
        return $this->plainTextPurifier;
    }

    /**
     * Get or create the rich text HTMLPurifier instance (allows safe HTML).
     * Returns the enhanced or standard variant based on the current route.
     */
    private function getRichTextPurifier(): \HTMLPurifier
    {
        $prop = $this->isEnhancedRoute ? 'enhancedRichTextPurifier' : 'richTextPurifier';
        if ($this->$prop === null) {
            $tags = $this->isEnhancedRoute ? $this->enhancedAllowedTags : $this->allowedTags;
            $config = \HTMLPurifier_Config::createDefault();
            $config->set('HTML.Allowed', $this->buildHtmlPurifierAllowedString($tags));
            $config->set('HTML.Nofollow', true);
            $config->set('URI.AllowedSchemes', ['http' => true, 'https' => true, 'mailto' => true]);
            $config->set('Attr.AllowedFrameTargets', ['_blank']);
            $config->set('Cache.SerializerPath', storage_path('framework/cache/htmlpurifier'));
            if ($this->isEnhancedRoute) {
                $config->set('URI.AllowedSchemes', ['http' => true, 'https' => true, 'mailto' => true, 'data' => true]);
                // Restrict data: URIs to images only (prevent data:text/html XSS)
                $config->set('Cache.DefinitionImpl', null);
                $uri = $config->getDefinition('URI');
                $uri->addFilter(new class extends \HTMLPurifier_URIFilter {
                    /** @var string */
                    public $name = 'RestrictDataUri';

                    /**
                     * @param \HTMLPurifier_URI $uri
                     * @param \HTMLPurifier_Config $config
                     * @param \HTMLPurifier_Context $context
                     * @return bool
                     */
                    public function filter(&$uri, $config, $context)
                    {
                        if ($uri->scheme === 'data') {
                            return str_starts_with($uri->path ?? '', 'image/');
                        }
                        return true;
                    }
                }, $config);
            }
            $this->$prop = new \HTMLPurifier($config);
        }
        return $this->$prop;
    }

    /**
     * Build the HTMLPurifier allowed elements string from the tags array.
     */
    protected function buildHtmlPurifierAllowedString(array $tags): string
    {
        $tagAttrMap = [
            'a' => 'a[href|target|rel|class]',
            'img' => 'img[src|alt|title|width|class]',
            'table' => 'table[class]',
            'td' => 'td[class]',
            'th' => 'th[class]',
            'div' => 'div[class]',
            'span' => 'span[class]',
        ];

        $parts = [];
        foreach ($tags as $tag) {
            $parts[] = $tagAttrMap[$tag] ?? $tag;
        }
        return implode(',', $parts);
    }

    /**
     * Check if the current request is for an enhanced rich text route
     */
    protected function isEnhancedRichTextRoute(Request $request): bool
    {
        $path = $request->path();

        foreach ($this->enhancedRichTextRoutes as $route) {
            if (str_starts_with($path, $route)) {
                return true;
            }
        }

        return false;
    }


    /**
     * Add IP to whitelist (for runtime configuration)
     */
    public function addToWhitelist(string $ip): void
    {
        if (! in_array($ip, $this->ipWhitelist)) {
            $this->ipWhitelist[] = $ip;
            Log::info('IP added to whitelist', ['ip' => $ip]);
        }
    }

    /**
     * Add IP to blacklist (for runtime configuration)
     */
    public function addToBlacklist(string $ip): void
    {
        if (! in_array($ip, $this->ipBlacklist)) {
            $this->ipBlacklist[] = $ip;
            Log::info('IP added to blacklist', ['ip' => $ip]);
        }
    }

    /**
     * Remove IP from whitelist
     */
    public function removeFromWhitelist(string $ip): void
    {
        $this->ipWhitelist = array_filter($this->ipWhitelist, fn ($item) => $item !== $ip);
        Log::info('IP removed from whitelist', ['ip' => $ip]);
    }

    /**
     * Remove IP from blacklist
     */
    public function removeFromBlacklist(string $ip): void
    {
        $this->ipBlacklist = array_filter($this->ipBlacklist, fn ($item) => $item !== $ip);
        Log::info('IP removed from blacklist', ['ip' => $ip]);
    }
}
