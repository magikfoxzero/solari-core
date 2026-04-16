<?php

namespace NewSolari\Core\Contracts;

/**
 * Contract for accessing system/partition/user registry settings.
 *
 * Decouples core from identity's RegistrySetting schema.
 * The identity module provides the concrete implementation.
 */
interface RegistryServiceContract
{
    /**
     * Get a system-scoped registry setting value.
     *
     * @param  string  $key  The setting key (e.g., 'system.account.email_verification.allow_unverified_login')
     * @param  mixed  $default  Default value if setting not found
     * @return mixed
     */
    public function getSystemSetting(string $key, mixed $default = null): mixed;
}
