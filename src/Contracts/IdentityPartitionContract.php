<?php

namespace NewSolari\Core\Contracts;

/**
 * Contract for the identity partition model.
 *
 * Used by core's AuthenticationMiddleware for partition validation.
 *
 * Eloquent properties accessed via magic getters:
 * - record_id, name
 */
interface IdentityPartitionContract
{
    //
}
