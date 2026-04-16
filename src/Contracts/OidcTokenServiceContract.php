<?php

namespace NewSolari\Core\Contracts;

/**
 * Contract for the OIDC token service.
 *
 * Used by BaseController's generateJwtToken() and identity controllers.
 * The identity package provides the concrete RS256 implementation.
 */
interface OidcTokenServiceContract
{
    public function issueAccessToken(array $claims): string;

    public function validateToken(string $token): ?object;

    public function getPublicKeyPem(): string;

    public function encodeToken(array $payload): string;

    public function decodeTokenWithLeeway(string $token, int $leeway = 0): ?object;

    public function getJwks(): array;
}
