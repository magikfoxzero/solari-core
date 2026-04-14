<?php

namespace NewSolari\Core\Contracts;

/**
 * Contract for the OIDC token service.
 *
 * Used by BaseController's generateJwtToken() method.
 * The identity package provides the concrete RS256 implementation.
 */
interface OidcTokenServiceContract
{
    public function issueAccessToken(array $claims): string;

    public function validateToken(string $token): ?object;
}
