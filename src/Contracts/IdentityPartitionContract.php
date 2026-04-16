<?php

namespace NewSolari\Core\Contracts;

/**
 * Contract for the identity partition model.
 *
 * Used by core's AuthenticationMiddleware for partition validation.
 */
interface IdentityPartitionContract
{
    /**
     * Get the partition's unique identifier.
     */
    public function getRecordId(): string;

    /**
     * Get the partition's display name.
     */
    public function getName(): string;
}
