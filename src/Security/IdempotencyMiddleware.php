<?php

namespace NewSolari\Core\Security;

use NewSolari\Core\Identity\Models\IdempotencyKey;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Idempotency Middleware
 *
 * API-MED-NEW-007: Ensures bulk operations are retry-safe by caching
 * responses keyed by client-provided idempotency keys.
 *
 * Usage:
 * - Client sends Idempotency-Key header with unique identifier
 * - First request: processed normally, response cached
 * - Retry with same key + body: returns cached response with Idempotency-Replayed header
 * - Same key, different body: returns 422 error
 * - No key provided: backward compatible, processes normally
 */
class IdempotencyMiddleware
{
    /**
     * The HTTP header name for idempotency key.
     */
    private const IDEMPOTENCY_HEADER = 'Idempotency-Key';

    /**
     * Maximum length for idempotency key.
     */
    private const MAX_KEY_LENGTH = 255;

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $idempotencyKey = $request->header(self::IDEMPOTENCY_HEADER);

        // If no idempotency key provided, process normally (backward compatible)
        if (empty($idempotencyKey)) {
            return $next($request);
        }

        // Validate key format
        if (! $this->isValidKey($idempotencyKey)) {
            return response()->json([
                'value' => false,
                'result' => 'Invalid Idempotency-Key format. Must be 1-255 alphanumeric characters, dashes, or underscores.',
                'code' => 400,
            ], 400);
        }

        // Get authenticated user
        $user = $request->user();
        if (! $user) {
            // If no user, skip idempotency (shouldn't happen with auth middleware)
            return $next($request);
        }

        $requestPath = $request->path();
        $requestMethod = $request->method();
        $requestHash = IdempotencyKey::hashRequestBody($request->all());

        // Check for existing idempotency key
        $existing = IdempotencyKey::findExisting(
            $idempotencyKey,
            $user->record_id,
            $requestPath,
            $requestMethod
        );

        if ($existing) {
            // Verify request body matches
            if (! $existing->matchesRequestHash($requestHash)) {
                Log::warning('Idempotency key reused with different parameters', [
                    'key' => $idempotencyKey,
                    'user_id' => $user->record_id,
                    'path' => $requestPath,
                    'stored_hash' => substr($existing->request_hash, 0, 16) . '...',
                    'new_hash' => substr($requestHash, 0, 16) . '...',
                ]);

                return response()->json([
                    'value' => false,
                    'result' => 'Idempotency key was already used with different request parameters. Use a new key for different requests.',
                    'code' => 422,
                ], 422);
            }

            // Return cached response with replay header
            Log::info('Replaying idempotent response', [
                'key' => $idempotencyKey,
                'user_id' => $user->record_id,
                'path' => $requestPath,
                'original_status' => $existing->response_status,
            ]);

            return response($existing->response_body, $existing->response_status)
                ->header('Content-Type', 'application/json')
                ->header('Idempotency-Replayed', 'true')
                ->header('Idempotency-Original-Request', $existing->created_at->toIso8601String());
        }

        // Process request
        $response = $next($request);

        // Cache successful responses (2xx status codes)
        if ($response->getStatusCode() >= 200 && $response->getStatusCode() < 300) {
            $this->storeIdempotencyKey(
                $idempotencyKey,
                $user->record_id,
                $requestPath,
                $requestMethod,
                $requestHash,
                $response
            );
        }

        return $response;
    }

    /**
     * Validate the idempotency key format.
     */
    protected function isValidKey(string $key): bool
    {
        // Must be 1-255 chars, alphanumeric with dashes and underscores
        // UUIDs are the recommended format
        if (strlen($key) > self::MAX_KEY_LENGTH || strlen($key) < 1) {
            return false;
        }

        // Allow UUIDs, alphanumeric, dashes, underscores
        return preg_match('/^[a-zA-Z0-9\-_]+$/', $key) === 1;
    }

    /**
     * Store the idempotency key and response.
     */
    protected function storeIdempotencyKey(
        string $idempotencyKey,
        string $userId,
        string $requestPath,
        string $requestMethod,
        string $requestHash,
        Response $response
    ): void {
        try {
            $expirationSeconds = IdempotencyKey::getExpirationSeconds();

            IdempotencyKey::create([
                'idempotency_key' => $idempotencyKey,
                'user_id' => $userId,
                'request_path' => $requestPath,
                'request_method' => $requestMethod,
                'request_hash' => $requestHash,
                'response_status' => $response->getStatusCode(),
                'response_body' => $response->getContent(),
                'expires_at' => now()->addSeconds($expirationSeconds),
            ]);

            Log::debug('Stored idempotency key', [
                'key' => $idempotencyKey,
                'user_id' => $userId,
                'path' => $requestPath,
                'expires_in' => $expirationSeconds,
            ]);
        } catch (\Exception $e) {
            // Don't fail the request if we can't store the key
            // This could happen on duplicate key race condition
            Log::warning('Failed to store idempotency key', [
                'key' => $idempotencyKey,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
