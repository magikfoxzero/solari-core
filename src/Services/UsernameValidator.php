<?php

namespace NewSolari\Core\Services;

use NewSolari\Core\Security\NaughtyWordsFilter;

/**
 * Username validation service.
 *
 * Validates usernames against:
 * - Reserved system usernames (configurable via RESERVED_USERNAMES env)
 * - Naughty words filter (configured via NAUGHTY_WORDS_LIST env)
 *
 * Default reserved usernames include:
 * - Admin accounts: admin, administrator, root, superuser, guest, backup, sysadmin
 * - System services: mail, smtp, pop, ftp, daemon, nobody, www, webmaster, postmaster, mysql, apache, sshd
 * - Network reserved: isatap, autoconfig, localhost, domain, public, user, test, support, info
 */
class UsernameValidator
{
    /**
     * Reserved usernames loaded from environment
     *
     * @var array<string>
     */
    protected array $reservedUsernames = [];

    /**
     * Reserved usernames map for O(1) lookup
     *
     * @var array<string, bool>
     */
    protected array $reservedMap = [];

    /**
     * Default reserved usernames (used when env not configured)
     */
    protected const DEFAULT_RESERVED = [
        // Admin accounts
        'admin', 'administrator', 'root', 'superuser', 'guest', 'backup', 'sysadmin',
        // System services
        'mail', 'smtp', 'pop', 'ftp', 'daemon', 'nobody', 'www', 'webmaster', 'postmaster', 'mysql', 'apache', 'sshd',
        // Network reserved
        'isatap', 'autoconfig', 'localhost', 'domain', 'public', 'user', 'test', 'support', 'info',
    ];

    /**
     * Naughty words filter instance
     */
    protected NaughtyWordsFilter $naughtyWordsFilter;

    public function __construct()
    {
        $this->loadReservedUsernames();
        $this->naughtyWordsFilter = new NaughtyWordsFilter();
    }

    /**
     * Load reserved usernames from environment variable.
     *
     * Expected format: RESERVED_USERNAMES="admin,root,test,magik,partition"
     * If not set, uses DEFAULT_RESERVED list.
     */
    protected function loadReservedUsernames(): void
    {
        $envValue = env('RESERVED_USERNAMES', '');

        if (empty($envValue)) {
            // Use defaults if no env configured
            $this->reservedUsernames = self::DEFAULT_RESERVED;
        } else {
            // Parse CSV string
            $words = array_map('trim', explode(',', $envValue));
            $this->reservedUsernames = array_values(array_filter($words, fn ($w) => ! empty($w)));
        }

        // Build lookup map
        $this->reservedMap = [];
        foreach ($this->reservedUsernames as $username) {
            $this->reservedMap[strtolower($username)] = true;
        }
    }

    /**
     * Validate a username.
     *
     * Blocks:
     * - Exact matches with reserved words (e.g., "admin", "root")
     * - Usernames starting with reserved words (e.g., "admin123", "root_user")
     *
     * Allows:
     * - Usernames ending with reserved words (e.g., "testuser", "sysadminteam")
     * - Usernames with reserved words in the middle (e.g., "johnadmin123")
     *
     * @param  string  $username  The username to validate
     * @return array{valid: bool, reason: ?string} Validation result
     */
    public function validate(string $username): array
    {
        $username = trim($username);
        $lowerUsername = strtolower($username);

        // Check if username is exactly a reserved word
        if (isset($this->reservedMap[$lowerUsername])) {
            return [
                'valid' => false,
                'reason' => 'This username is reserved and cannot be used',
            ];
        }

        // Check if username starts with a reserved word
        // This blocks "admin123" or "root_user" but allows "testuser" or "sysadminteam"
        foreach ($this->reservedUsernames as $reserved) {
            $lowerReserved = strtolower($reserved);
            if (str_starts_with($lowerUsername, $lowerReserved)) {
                return [
                    'valid' => false,
                    'reason' => 'Username cannot start with a reserved word',
                ];
            }
        }

        // Check against naughty words filter (uses word boundary matching)
        if ($this->naughtyWordsFilter->containsNaughtyWord($username)) {
            return [
                'valid' => false,
                'reason' => 'Username contains inappropriate content',
            ];
        }

        return [
            'valid' => true,
            'reason' => null,
        ];
    }

    /**
     * Check if a username is valid.
     *
     * @param  string  $username  The username to check
     * @return bool True if valid, false otherwise
     */
    public function isValid(string $username): bool
    {
        return $this->validate($username)['valid'];
    }

    /**
     * Get the validation error message for a username.
     *
     * @param  string  $username  The username to check
     * @return string|null Error message or null if valid
     */
    public function getErrorMessage(string $username): ?string
    {
        return $this->validate($username)['reason'];
    }

    /**
     * Get the list of reserved usernames.
     *
     * @return array<string>
     */
    public function getReservedUsernames(): array
    {
        return $this->reservedUsernames;
    }
}
