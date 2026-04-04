<?php

namespace NewSolari\Core\Services;

use NewSolari\Core\Identity\Models\IdentityUser;
use NewSolari\Core\Identity\Models\RecordShare;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Service for managing record sharing between users.
 *
 * Provides methods for sharing records, revoking shares, and querying shared records.
 */
class RecordSharingService
{
    /**
     * Error type for transient errors that can be retried.
     */
    public const ERROR_TYPE_RETRYABLE = 'retryable';

    /**
     * Error type for permanent errors (business logic, validation).
     */
    public const ERROR_TYPE_PERMANENT = 'permanent';

    /**
     * SQL states that indicate retryable errors.
     */
    protected array $retryableSqlStates = [
        '40001', // Serialization failure (deadlock)
        '40P01', // PostgreSQL deadlock detected
        'HY000', // General error (often connection issues)
        '08006', // Connection failure
        '08S01', // Communication link failure
    ];

    protected AuthorizationService $authService;

    public function __construct(AuthorizationService $authService)
    {
        $this->authService = $authService;
    }

    /**
     * Share a record with a user.
     *
     * @throws \InvalidArgumentException
     */
    public function share(
        Model $entity,
        IdentityUser $recipient,
        IdentityUser $sharer,
        string $permission = 'read',
        ?string $message = null,
        ?\DateTimeInterface $expiresAt = null
    ): RecordShare {
        // Validation
        $this->validateShareRequest($entity, $recipient, $sharer);

        return DB::transaction(function () use ($entity, $recipient, $sharer, $permission, $message, $expiresAt) {
            $share = $entity->shareWith($recipient, $sharer, $permission, $message, $expiresAt);

            Log::info('Record shared', [
                'share_id' => $share->record_id,
                'entity_type' => $entity->getTable(),
                'entity_id' => $entity->getKey(),
                'shared_with' => $recipient->record_id,
                'shared_by' => $sharer->record_id,
                'permission' => $permission,
            ]);

            return $share;
        });
    }

    /**
     * Revoke a share.
     */
    public function unshare(Model $entity, IdentityUser $recipient, IdentityUser $revoker): bool
    {
        $result = $entity->unshareWith($recipient, $revoker);

        if ($result) {
            Log::info('Share revoked', [
                'entity_type' => $entity->getTable(),
                'entity_id' => $entity->getKey(),
                'revoked_from' => $recipient->record_id,
                'revoked_by' => $revoker->record_id,
            ]);
        }

        return $result;
    }

    /**
     * Get all records shared with a user.
     */
    public function getSharedWithUser(
        IdentityUser $user,
        ?string $entityType = null,
        ?string $partitionId = null
    ): Collection {
        $query = RecordShare::active()
            ->forUser($user->record_id)
            ->with(['shareable', 'sharedByUser']);

        if ($entityType) {
            $query->where('shareable_type', $entityType);
        }

        if ($partitionId) {
            $query->inPartition($partitionId);
        }

        return $query->get();
    }

    /**
     * Get all shares for a specific entity.
     */
    public function getSharesForEntity(Model $entity): Collection
    {
        return $entity->activeShares()
            ->with('sharedWithUser')
            ->get();
    }

    /**
     * Update share permission.
     */
    public function updatePermission(
        RecordShare $share,
        string $newPermission,
        IdentityUser $updater
    ): RecordShare {
        $share->update([
            'permission' => $newPermission,
            'updated_by' => $updater->record_id,
        ]);

        Log::info('Share permission updated', [
            'share_id' => $share->record_id,
            'new_permission' => $newPermission,
            'updated_by' => $updater->record_id,
        ]);

        return $share->fresh();
    }

    /**
     * Bulk share with multiple users.
     * All shares are wrapped in a single transaction to prevent partial failures.
     * Classifies errors as retryable or permanent for better client handling.
     */
    public function shareWithMultiple(
        Model $entity,
        array $recipientIds,
        IdentityUser $sharer,
        string $permission = 'read',
        ?string $message = null,
        ?\DateTimeInterface $expiresAt = null
    ): array {
        $results = [
            'success' => [],
            'failed' => [],
            'has_retryable_errors' => false,
        ];

        // Wrap entire bulk operation in a transaction
        return DB::transaction(function () use ($entity, $recipientIds, $sharer, $permission, $message, $expiresAt, &$results) {
            foreach ($recipientIds as $recipientId) {
                try {
                    $recipient = IdentityUser::find($recipientId);
                    if (!$recipient) {
                        $results['failed'][] = [
                            'user_id' => $recipientId,
                            'reason' => 'User not found',
                            'error_type' => self::ERROR_TYPE_PERMANENT,
                            'retryable' => false,
                        ];
                        continue;
                    }

                    // Validate and share directly (avoiding nested transaction from share())
                    $this->validateShareRequest($entity, $recipient, $sharer);
                    $share = $entity->shareWith($recipient, $sharer, $permission, $message, $expiresAt);

                    Log::info('Record shared (bulk)', [
                        'share_id' => $share->record_id,
                        'entity_type' => $entity->getTable(),
                        'entity_id' => $entity->getKey(),
                        'shared_with' => $recipient->record_id,
                        'shared_by' => $sharer->record_id,
                        'permission' => $permission,
                    ]);

                    $results['success'][] = $share->record_id;
                } catch (\InvalidArgumentException $e) {
                    // Business logic errors are never retryable
                    $results['failed'][] = [
                        'user_id' => $recipientId,
                        'reason' => $e->getMessage(),
                        'error_type' => self::ERROR_TYPE_PERMANENT,
                        'retryable' => false,
                    ];
                } catch (QueryException $e) {
                    // Database errors - check if retryable
                    $isRetryable = $this->isRetryableException($e);
                    $results['failed'][] = [
                        'user_id' => $recipientId,
                        'reason' => $e->getMessage(),
                        'error_type' => $isRetryable ? self::ERROR_TYPE_RETRYABLE : self::ERROR_TYPE_PERMANENT,
                        'retryable' => $isRetryable,
                    ];
                    if ($isRetryable) {
                        $results['has_retryable_errors'] = true;
                    }
                } catch (\Exception $e) {
                    // Unknown errors - default to non-retryable
                    $results['failed'][] = [
                        'user_id' => $recipientId,
                        'reason' => $e->getMessage(),
                        'error_type' => self::ERROR_TYPE_PERMANENT,
                        'retryable' => false,
                    ];
                }
            }

            return $results;
        });
    }

    /**
     * Check if a user has share-based access to an entity for a given action.
     */
    public function userHasShareAccess(Model $entity, IdentityUser $user, string $action): bool
    {
        // Check if entity uses Shareable trait
        if (!method_exists($entity, 'userHasShareAccess')) {
            return false;
        }

        return $entity->userHasShareAccess($user, $action);
    }

    /**
     * Validate share request.
     *
     * @throws \InvalidArgumentException
     */
    protected function validateShareRequest(Model $entity, IdentityUser $recipient, IdentityUser $sharer): void
    {
        // Entity must use Shareable trait
        if (!method_exists($entity, 'shareWith')) {
            throw new \InvalidArgumentException('Entity does not support sharing');
        }

        // Cannot share with self
        if ($recipient->record_id === $sharer->record_id) {
            throw new \InvalidArgumentException('Cannot share a record with yourself');
        }

        // Recipient must exist in same partition
        if ($recipient->partition_id !== $entity->partition_id) {
            throw new \InvalidArgumentException('Recipient must be in the same partition');
        }

        // Sharer must have permission
        if (!$entity->canUserShare($sharer)) {
            throw new \InvalidArgumentException('You do not have permission to share this record');
        }
    }

    /**
     * Determine if an exception is retryable (transient error).
     *
     * Retryable errors include database deadlocks, connection failures,
     * and other transient issues that may succeed on retry.
     */
    protected function isRetryableException(\Exception $e): bool
    {
        // Laravel's built-in deadlock exception
        if ($e instanceof DeadlockException) {
            return true;
        }

        // Check SQL state codes for retryable conditions
        if ($e instanceof QueryException) {
            $sqlState = $e->errorInfo[0] ?? null;
            if (in_array($sqlState, $this->retryableSqlStates, true)) {
                return true;
            }

            // Check for common retryable error patterns in message
            $message = strtolower($e->getMessage());
            $retryablePatterns = [
                'deadlock',
                'lock wait timeout',
                'connection reset',
                'server has gone away',
                'lost connection',
            ];

            foreach ($retryablePatterns as $pattern) {
                if (str_contains($message, $pattern)) {
                    return true;
                }
            }
        }

        return false;
    }
}
