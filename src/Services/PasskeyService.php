<?php

namespace NewSolari\Core\Services;

use NewSolari\Core\Identity\Models\IdentityUser;
use NewSolari\Core\Identity\Models\UserPasskey;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Webauthn\AttestationStatement\AttestationStatementSupportManager;
use Webauthn\AuthenticatorAssertionResponse;
use Webauthn\AuthenticatorAssertionResponseValidator;
use Webauthn\AuthenticatorAttestationResponse;
use Webauthn\AuthenticatorAttestationResponseValidator;
use Webauthn\AuthenticatorSelectionCriteria;
use Webauthn\CeremonyStep\CeremonyStepManagerFactory;
use Webauthn\Denormalizer\WebauthnSerializerFactory;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialDescriptor;
use Webauthn\PublicKeyCredentialParameters;
use Webauthn\PublicKeyCredentialRequestOptions;
use Webauthn\PublicKeyCredentialRpEntity;
use Webauthn\PublicKeyCredentialSource;
use Webauthn\PublicKeyCredentialUserEntity;

/**
 * Service for managing WebAuthn/Passkey authentication.
 *
 * Handles:
 * - Passkey registration (attestation)
 * - Passkey authentication (assertion)
 * - Passkey management (list, delete, rename)
 *
 * Note: This service provides repository-like methods (findOneByCredentialId,
 * findAllForUserEntity, saveCredentialSource) for credential management.
 */
class PasskeyService
{
    private ?WebauthnSerializerFactory $serializerFactory = null;

    /**
     * Check if passkeys are enabled based on AUTH_MODE.
     */
    public function isEnabled(): bool
    {
        return config('passkeys.enabled', true);
    }

    /**
     * Get the current authentication mode.
     */
    public function getMode(): string
    {
        return config('passkeys.mode', 'hybrid');
    }

    /**
     * Check if password authentication is allowed.
     */
    public function isPasswordEnabled(): bool
    {
        return $this->getMode() !== 'passkeys_only';
    }

    /**
     * Get the configuration for the frontend.
     */
    public function getConfig(): array
    {
        return [
            'enabled' => $this->isEnabled(),
            'mode' => $this->getMode(),
            'passwords_enabled' => $this->isPasswordEnabled(),
        ];
    }

    /**
     * Create registration options for a user.
     */
    public function createRegistrationOptions(IdentityUser $user, ?string $rpId = null): array
    {
        $rpId = $rpId ?: config('passkeys.rp_id') ?: request()->getHost();
        $rpName = config('passkeys.rp_name', 'Solari');

        // Create the Relying Party entity
        $rpEntity = new PublicKeyCredentialRpEntity(
            name: $rpName,
            id: $rpId
        );

        // Create the user entity
        $userEntity = new PublicKeyCredentialUserEntity(
            name: $user->username,
            id: $user->record_id,
            displayName: $user->first_name ? trim("{$user->first_name} {$user->last_name}") : $user->username
        );

        // Get existing credentials to exclude (prevent duplicate registration)
        $excludeCredentials = $this->getCredentialDescriptorsForUser($user);

        // Create challenge
        $challenge = random_bytes(32);

        // Store challenge in cache for verification
        $challengeKey = $this->getChallengeKey($user->record_id, 'register');
        Cache::put($challengeKey, base64_encode($challenge), config('passkeys.challenge_ttl', 300));

        // Create the credential creation options
        $options = new PublicKeyCredentialCreationOptions(
            rp: $rpEntity,
            user: $userEntity,
            challenge: $challenge,
            pubKeyCredParams: $this->getSupportedAlgorithms(),
            timeout: config('passkeys.timeout', 60000),
            excludeCredentials: $excludeCredentials,
            authenticatorSelection: new AuthenticatorSelectionCriteria(
                userVerification: config('passkeys.user_verification', 'preferred'),
                residentKey: AuthenticatorSelectionCriteria::RESIDENT_KEY_REQUIREMENT_PREFERRED,
            ),
            attestation: config('passkeys.attestation', 'none')
        );

        return $this->serializeOptions($options);
    }

    /**
     * Verify registration response and create passkey.
     */
    public function verifyRegistration(
        IdentityUser $user,
        array $response,
        ?string $deviceName = null,
        ?string $rpId = null
    ): UserPasskey {
        $rpId = $rpId ?: config('passkeys.rp_id') ?: request()->getHost();

        // Retrieve stored challenge
        $challengeKey = $this->getChallengeKey($user->record_id, 'register');
        $storedChallenge = Cache::get($challengeKey);

        if (!$storedChallenge) {
            throw new \RuntimeException('Registration challenge not found or expired');
        }

        // Delete challenge after retrieval (single use)
        Cache::forget($challengeKey);

        // Deserialize the response
        $publicKeyCredential = $this->deserializeCredential($response);

        if (!$publicKeyCredential->response instanceof AuthenticatorAttestationResponse) {
            throw new \RuntimeException('Invalid attestation response');
        }

        // Create ceremony step manager with allowed origins (v5.x API)
        $csmFactory = new CeremonyStepManagerFactory();

        // Build the list of allowed origins
        $currentHost = request()->getHost();
        $allowedOrigins = ['https://' . $currentHost];

        // Add configured allowed origins (for Android/iOS apps)
        $configuredOrigins = config('passkeys.allowed_origins', []);
        foreach ($configuredOrigins as $origin) {
            if (!empty($origin)) {
                $allowedOrigins[] = $origin;
            }
        }

        // Set allowed origins (including Android app origins)
        $csmFactory->setAllowedOrigins($allowedOrigins, true);

        $creationCSM = $csmFactory->creationCeremony();

        // Create validator (v5.x only takes ceremonyStepManager)
        $validator = AuthenticatorAttestationResponseValidator::create($creationCSM);

        // Validate the response
        $publicKeyCredentialSource = $validator->check(
            authenticatorAttestationResponse: $publicKeyCredential->response,
            publicKeyCredentialCreationOptions: new PublicKeyCredentialCreationOptions(
                rp: new PublicKeyCredentialRpEntity(
                    name: config('passkeys.rp_name', 'Solari'),
                    id: $rpId
                ),
                user: new PublicKeyCredentialUserEntity(
                    name: $user->username,
                    id: $user->record_id,
                    displayName: $user->first_name ? trim("{$user->first_name} {$user->last_name}") : $user->username
                ),
                challenge: base64_decode($storedChallenge),
                pubKeyCredParams: $this->getSupportedAlgorithms()
            ),
            host: request()->getHost()
        );

        // Store the passkey
        $passkey = UserPasskey::create([
            'user_id' => $user->record_id,
            'credential_id' => $publicKeyCredentialSource->publicKeyCredentialId,
            'public_key' => $publicKeyCredentialSource->credentialPublicKey,
            'sign_count' => $publicKeyCredentialSource->counter,
            'transports' => $publicKeyCredentialSource->transports ?? [],
            'device_name' => $deviceName,
            'aaguid' => $publicKeyCredentialSource->aaguid->toString(),
        ]);

        Log::info('Passkey registered', [
            'user_id' => $user->record_id,
            'passkey_id' => $passkey->id,
            'device_name' => $deviceName,
        ]);

        return $passkey;
    }

    /**
     * Create authentication options.
     */
    public function createAuthenticationOptions(?string $username = null, ?string $rpId = null): array
    {
        $rpId = $rpId ?: config('passkeys.rp_id') ?: request()->getHost();

        // Create challenge
        $challenge = random_bytes(32);

        // Get allowed credentials if username is provided
        $allowCredentials = [];
        $userId = null;

        if ($username) {
            $user = IdentityUser::withoutGlobalScope('partition')
                ->where('username', $username)
                ->first();

            if ($user) {
                $userId = $user->record_id;
                $allowCredentials = $this->getCredentialDescriptorsForUser($user);
            }
        }

        // Store challenge in cache
        $sessionId = Str::random(32);
        $challengeKey = $this->getChallengeKey($sessionId, 'authenticate');
        Cache::put($challengeKey, [
            'challenge' => base64_encode($challenge),
            'user_id' => $userId,
        ], config('passkeys.challenge_ttl', 300));

        // Create the credential request options
        $options = new PublicKeyCredentialRequestOptions(
            challenge: $challenge,
            rpId: $rpId,
            allowCredentials: $allowCredentials,
            userVerification: config('passkeys.user_verification', 'preferred'),
            timeout: config('passkeys.timeout', 60000)
        );

        $serialized = $this->serializeOptions($options);
        $serialized['session_id'] = $sessionId;

        return $serialized;
    }

    /**
     * Verify authentication response and return user.
     */
    public function verifyAuthentication(array $response, string $sessionId, ?string $rpId = null): IdentityUser
    {
        $rpId = $rpId ?: config('passkeys.rp_id') ?: request()->getHost();

        // Retrieve stored challenge
        $challengeKey = $this->getChallengeKey($sessionId, 'authenticate');
        $challengeData = Cache::get($challengeKey);

        if (!$challengeData) {
            throw new \RuntimeException('Authentication challenge not found or expired');
        }

        // Delete challenge after retrieval (single use)
        Cache::forget($challengeKey);

        $storedChallenge = $challengeData['challenge'];

        // Deserialize the response
        $publicKeyCredential = $this->deserializeCredential($response);

        if (!$publicKeyCredential->response instanceof AuthenticatorAssertionResponse) {
            throw new \RuntimeException('Invalid assertion response');
        }

        // Debug: Log the origin from clientDataJSON
        $clientData = $publicKeyCredential->response->clientDataJSON;
        Log::debug('Passkey authentication attempt', [
            'origin' => $clientData->origin ?? 'unknown',
            'type' => $clientData->type ?? 'unknown',
            'rpId' => $rpId,
            'currentHost' => request()->getHost(),
        ]);

        // Find the passkey by credential ID (uses indexed hash for efficiency)
        $credentialId = $publicKeyCredential->rawId;
        $passkey = UserPasskey::findByCredentialId($credentialId);

        if (!$passkey) {
            throw new \RuntimeException('Passkey not found');
        }

        // Get the user
        $user = $passkey->user;

        if (!$user) {
            throw new \RuntimeException('User not found');
        }

        // Check if user is active
        if (!$user->is_active) {
            throw new \RuntimeException('Account is disabled');
        }

        // Create ceremony step manager with allowed origins for mobile apps
        $csmFactory = new CeremonyStepManagerFactory();

        // Build the list of allowed origins
        $currentHost = request()->getHost();
        $allowedOrigins = ['https://' . $currentHost];

        // Add configured allowed origins (for Android/iOS apps)
        $configuredOrigins = config('passkeys.allowed_origins', []);
        foreach ($configuredOrigins as $origin) {
            if (!empty($origin)) {
                $allowedOrigins[] = $origin;
            }
        }

        // Set allowed origins (including Android app origins like android:apk-key-hash:...)
        Log::debug('Passkey allowed origins', ['origins' => $allowedOrigins]);
        $csmFactory->setAllowedOrigins($allowedOrigins, true); // true = allow subdomains

        $requestCSM = $csmFactory->requestCeremony();

        // Create validator (v5.x API)
        $validator = AuthenticatorAssertionResponseValidator::create($requestCSM);

        // Create credential source from passkey
        $credentialSource = $this->passkeyToCredentialSource($passkey);

        // Validate the response (v5.x signature: source, response, options, host, userHandle)
        $validator->check(
            publicKeyCredentialSource: $credentialSource,
            authenticatorAssertionResponse: $publicKeyCredential->response,
            publicKeyCredentialRequestOptions: new PublicKeyCredentialRequestOptions(
                challenge: base64_decode($storedChallenge),
                rpId: $rpId,
                userVerification: config('passkeys.user_verification', 'preferred')
            ),
            host: $currentHost,
            userHandle: $user->record_id
        );

        // Update sign count
        $newSignCount = $publicKeyCredential->response->authenticatorData->signCount;
        $passkey->updateSignCount($newSignCount);

        Log::info('Passkey authentication successful', [
            'user_id' => $user->record_id,
            'passkey_id' => $passkey->id,
        ]);

        return $user;
    }

    /**
     * Get user's passkeys.
     */
    public function getUserPasskeys(IdentityUser $user): array
    {
        return $user->passkeys()
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(fn ($p) => $p->toApiArray())
            ->toArray();
    }

    /**
     * Delete a passkey.
     */
    public function deletePasskey(IdentityUser $user, string $passkeyId): bool
    {
        $passkey = $user->passkeys()->where('id', $passkeyId)->first();

        if (!$passkey) {
            return false;
        }

        // Ensure user has at least one auth method remaining
        $remainingPasskeys = $user->passkeys()->where('id', '!=', $passkeyId)->count();

        // Check if user has a usable password:
        // 1. Passwords must be enabled in the current mode
        // 2. User must have password_required = true (meaning they set up password auth)
        // Note: We can't just check password_hash because the mutator hashes even null values
        $passwordsEnabled = $this->isPasswordEnabled();
        $hasPassword = $passwordsEnabled && $user->password_required;

        if ($remainingPasskeys === 0 && !$hasPassword) {
            throw new \RuntimeException('Cannot delete last passkey without a password set');
        }

        $passkey->delete();

        Log::info('Passkey deleted', [
            'user_id' => $user->record_id,
            'passkey_id' => $passkeyId,
        ]);

        return true;
    }

    /**
     * Rename a passkey.
     */
    public function renamePasskey(IdentityUser $user, string $passkeyId, string $name): bool
    {
        $passkey = $user->passkeys()->where('id', $passkeyId)->first();

        if (!$passkey) {
            return false;
        }

        $passkey->device_name = $name;
        $passkey->save();

        return true;
    }

    /**
     * PublicKeyCredentialSourceRepository: Find credential by ID.
     */
    public function findOneByCredentialId(string $publicKeyCredentialId): ?PublicKeyCredentialSource
    {
        $passkey = UserPasskey::findByCredentialId($publicKeyCredentialId);

        if (!$passkey) {
            return null;
        }

        return $this->passkeyToCredentialSource($passkey);
    }

    /**
     * PublicKeyCredentialSourceRepository: Find credentials for user handle.
     */
    public function findAllForUserEntity(PublicKeyCredentialUserEntity $publicKeyCredentialUserEntity): array
    {
        $user = IdentityUser::withoutGlobalScope('partition')
            ->where('record_id', $publicKeyCredentialUserEntity->id)
            ->first();

        if (!$user) {
            return [];
        }

        return $user->passkeys
            ->map(fn ($p) => $this->passkeyToCredentialSource($p))
            ->toArray();
    }

    /**
     * PublicKeyCredentialSourceRepository: Save credential source.
     */
    public function saveCredentialSource(PublicKeyCredentialSource $publicKeyCredentialSource): void
    {
        // Find existing passkey and update counter
        $passkey = UserPasskey::findByCredentialId($publicKeyCredentialSource->publicKeyCredentialId);

        if ($passkey) {
            $passkey->sign_count = $publicKeyCredentialSource->counter;
            $passkey->last_used_at = now();
            $passkey->save();
        }
    }

    /**
     * Convert a UserPasskey to PublicKeyCredentialSource.
     */
    private function passkeyToCredentialSource(UserPasskey $passkey): PublicKeyCredentialSource
    {
        return new PublicKeyCredentialSource(
            publicKeyCredentialId: $passkey->credential_id,
            type: PublicKeyCredentialDescriptor::CREDENTIAL_TYPE_PUBLIC_KEY,
            transports: $passkey->transports ?? [],
            attestationType: 'none',
            trustPath: new \Webauthn\TrustPath\EmptyTrustPath(),
            aaguid: \Symfony\Component\Uid\Uuid::fromString($passkey->aaguid ?? '00000000-0000-0000-0000-000000000000'),
            credentialPublicKey: $passkey->public_key,
            userHandle: $passkey->user_id,
            counter: $passkey->sign_count
        );
    }

    /**
     * Get credential descriptors for a user (for excludeCredentials/allowCredentials).
     */
    private function getCredentialDescriptorsForUser(IdentityUser $user): array
    {
        return $user->passkeys
            ->map(fn ($p) => new PublicKeyCredentialDescriptor(
                type: PublicKeyCredentialDescriptor::CREDENTIAL_TYPE_PUBLIC_KEY,
                id: $p->credential_id,
                transports: $p->transports ?? []
            ))
            ->toArray();
    }

    /**
     * Get supported algorithms.
     */
    private function getSupportedAlgorithms(): array
    {
        return [
            new PublicKeyCredentialParameters(
                type: PublicKeyCredentialDescriptor::CREDENTIAL_TYPE_PUBLIC_KEY,
                alg: -7 // ES256
            ),
            new PublicKeyCredentialParameters(
                type: PublicKeyCredentialDescriptor::CREDENTIAL_TYPE_PUBLIC_KEY,
                alg: -257 // RS256
            ),
        ];
    }

    /**
     * Generate a cache key for challenges.
     */
    private function getChallengeKey(string $identifier, string $type): string
    {
        return "passkey_challenge_{$type}_{$identifier}";
    }

    /**
     * Serialize options for JSON response.
     */
    private function serializeOptions(PublicKeyCredentialCreationOptions|PublicKeyCredentialRequestOptions $options): array
    {
        $factory = new WebauthnSerializerFactory(AttestationStatementSupportManager::create());
        $serializer = $factory->create();

        $json = $serializer->serialize($options, 'json');
        return json_decode($json, true);
    }

    /**
     * Deserialize credential from request.
     */
    private function deserializeCredential(array $data): PublicKeyCredential
    {
        $factory = new WebauthnSerializerFactory(AttestationStatementSupportManager::create());
        $serializer = $factory->create();

        return $serializer->deserialize(json_encode($data), PublicKeyCredential::class, 'json');
    }
}
