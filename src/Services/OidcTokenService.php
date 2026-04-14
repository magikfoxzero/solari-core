<?php

namespace NewSolari\Core\Services;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Support\Str;

/**
 * RS256 token service for monorepo mode.
 *
 * Reads key paths and config from the core jwt.oidc.* config namespace.
 * In microservice mode this class is not used — tokens are issued by
 * the remote identity service and validated via JWKS in the middleware.
 */
class OidcTokenService
{
    private ?string $privateKey = null;
    private ?string $publicKey = null;

    public function issueAccessToken(array $claims): string
    {
        $now = time();

        $payload = [
            'iss' => config('jwt.oidc.issuer'),
            'aud' => 'solari',
            'iat' => $claims['iat'] ?? $now,
            'nbf' => $now,
            'exp' => $now + config('jwt.oidc.token_lifetime', 14400),
            'jti' => Str::uuid()->toString(),
            'sub' => $claims['sub'],
            'partition_id' => $claims['partition_id'],
            'username' => $claims['username'],
            'email' => $claims['email'] ?? null,
            'first_name' => $claims['first_name'] ?? null,
            'last_name' => $claims['last_name'] ?? null,
            'display_name' => $claims['display_name'] ?? null,
            'is_system_user' => $claims['is_system_user'] ?? false,
            'is_active' => $claims['is_active'] ?? true,
            'is_partition_admin' => $claims['is_partition_admin'] ?? false,
        ];

        // Include original_iat for absolute session cap enforcement in sliding refresh mode
        if (isset($claims['original_iat'])) {
            $payload['original_iat'] = $claims['original_iat'];
        }

        return JWT::encode(
            $payload,
            $this->getPrivateKey(),
            'RS256',
            config('jwt.oidc.key_id', 'solari-rs256-2026-04')
        );
    }

    public function validateToken(string $token): ?object
    {
        try {
            return JWT::decode($token, new Key($this->getPublicKey(), 'RS256'));
        } catch (\Exception) {
            return null;
        }
    }

    private function getPrivateKey(): string
    {
        if ($this->privateKey === null) {
            $path = config('jwt.oidc.private_key_path');
            if (!$path || !file_exists($path)) {
                throw new \RuntimeException(
                    'OIDC private key not found at: ' . ($path ?: '(not configured)') .
                    '. Generate with: openssl genpkey -algorithm RSA -out storage/keys/oidc-private.pem -pkeyopt rsa_keygen_bits:2048'
                );
            }
            $this->privateKey = file_get_contents($path);
        }

        return $this->privateKey;
    }

    private function getPublicKey(): string
    {
        if ($this->publicKey === null) {
            $path = config('jwt.oidc.public_key_path');
            if (!$path || !file_exists($path)) {
                throw new \RuntimeException(
                    'OIDC public key not found at: ' . ($path ?: '(not configured)') .
                    '. Generate with: openssl rsa -in storage/keys/oidc-private.pem -pubout -out storage/keys/oidc-public.pem'
                );
            }
            $this->publicKey = file_get_contents($path);
        }

        return $this->publicKey;
    }
}
