<?php

namespace NewSolari\Core\Identity\Controllers;

use NewSolari\Core\Http\BaseController;
use NewSolari\Core\Identity\Models\IdentityUser;
use NewSolari\Core\Identity\Models\UserSoftBan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class UserSoftBanController extends BaseController
{
    /**
     * List all active soft bans in the partition. Admin only.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $user = $this->getAuthenticatedUser($request);
            $partitionId = $this->getPartitionId($request);

            if (!$user->is_system_user && !$user->isPartitionAdmin($partitionId)) {
                return $this->errorResponse('Permission denied', 403);
            }

            $bans = UserSoftBan::where('partition_id', $partitionId)
                ->where('deleted', false)
                ->where(function ($query) {
                    $query->whereNull('banned_until')
                        ->orWhere('banned_until', '>', now());
                })
                ->with(['user:record_id,username', 'bannedByUser:record_id,username'])
                ->orderBy('created_at', 'desc')
                ->get();

            $data = $bans->map(function ($ban) {
                return [
                    'record_id' => $ban->record_id,
                    'user_id' => $ban->user_id,
                    'username' => $ban->user->username ?? 'Unknown User',
                    'banned_by' => $ban->banned_by,
                    'banned_by_username' => $ban->bannedByUser->username ?? 'Unknown User',
                    'reason' => $ban->reason,
                    'banned_until' => $ban->banned_until?->toIso8601String(),
                    'created_at' => $ban->created_at?->toIso8601String(),
                ];
            })->toArray();

            return $this->successResponse([
                'data' => $data,
                'count' => count($data),
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to fetch soft bans', [
                'error' => $e->getMessage(),
            ]);

            return $this->errorResponse('Failed to fetch soft bans', 500);
        }
    }

    /**
     * Get soft ban details for a specific user. Admin only.
     */
    public function show(Request $request, string $userId): JsonResponse
    {
        try {
            $user = $this->getAuthenticatedUser($request);
            $partitionId = $this->getPartitionId($request);

            if (!$user->is_system_user && !$user->isPartitionAdmin($partitionId)) {
                return $this->errorResponse('Permission denied', 403);
            }

            $includeHistory = $request->query('include_history') === 'true';

            if ($includeHistory) {
                $bans = UserSoftBan::withDeleted()
                    ->where('user_id', $userId)
                    ->where('partition_id', $partitionId)
                    ->with(['bannedByUser:record_id,username'])
                    ->orderBy('created_at', 'desc')
                    ->get();

                $data = $bans->map(function ($ban) {
                    return [
                        'record_id' => $ban->record_id,
                        'user_id' => $ban->user_id,
                        'banned_by' => $ban->banned_by,
                        'banned_by_username' => $ban->bannedByUser->username ?? 'Unknown User',
                        'reason' => $ban->reason,
                        'banned_until' => $ban->banned_until?->toIso8601String(),
                        'deleted' => $ban->deleted,
                        'deleted_by' => $ban->deleted_by,
                        'deleted_at' => $ban->deleted_at?->toIso8601String(),
                        'created_at' => $ban->created_at?->toIso8601String(),
                    ];
                })->toArray();

                return $this->successResponse([
                    'data' => $data,
                    'count' => count($data),
                ]);
            }

            $ban = UserSoftBan::getActiveBan($userId);

            if (!$ban || $ban->partition_id !== $partitionId) {
                return $this->successResponse([
                    'data' => null,
                    'is_banned' => false,
                ]);
            }

            return $this->successResponse([
                'data' => [
                    'record_id' => $ban->record_id,
                    'user_id' => $ban->user_id,
                    'banned_by' => $ban->banned_by,
                    'reason' => $ban->reason,
                    'banned_until' => $ban->banned_until?->toIso8601String(),
                    'created_at' => $ban->created_at?->toIso8601String(),
                ],
                'is_banned' => true,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to fetch soft ban status', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);

            return $this->errorResponse('Failed to fetch soft ban status', 500);
        }
    }

    /**
     * Create a soft ban for a user. Admin only.
     */
    public function store(Request $request, string $userId): JsonResponse
    {
        try {
            $user = $this->getAuthenticatedUser($request);
            $partitionId = $this->getPartitionId($request);

            if (!$user->is_system_user && !$user->isPartitionAdmin($partitionId)) {
                return $this->errorResponse('Permission denied', 403);
            }

            $validator = Validator::make($request->all(), [
                'reason' => 'required|string|max:2000',
                'duration_days' => 'nullable|integer|min:1|max:365',
            ]);

            if ($validator->fails()) {
                return $this->errorResponse($validator->errors()->first(), 422);
            }

            // Check target user exists
            $targetUser = IdentityUser::where('record_id', $userId)->first();
            if (!$targetUser) {
                return $this->errorResponse('User not found', 404);
            }

            // Check no active ban exists
            $existingBan = UserSoftBan::getActiveBan($userId);
            if ($existingBan && $existingBan->partition_id === $partitionId) {
                return $this->errorResponse('User already has an active soft ban', 409);
            }

            $bannedUntil = null;
            $durationDays = $request->input('duration_days');
            if ($durationDays) {
                $bannedUntil = now()->addDays((int) $durationDays);
            }

            $ban = UserSoftBan::create([
                'record_id' => Str::uuid()->toString(),
                'user_id' => $userId,
                'banned_by' => $user->record_id,
                'reason' => $request->input('reason'),
                'banned_until' => $bannedUntil,
                'partition_id' => $partitionId,
            ]);

            UserSoftBan::clearBanCache($userId);

            Log::info('User soft-banned', [
                'user_id' => $userId,
                'banned_by' => $user->record_id,
                'partition_id' => $partitionId,
                'duration_days' => $durationDays,
            ]);

            return $this->successResponse([
                'message' => 'User soft-banned successfully',
                'record_id' => $ban->record_id,
                'banned_until' => $ban->banned_until?->toIso8601String(),
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to soft-ban user', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);

            return $this->errorResponse('Failed to soft-ban user', 500);
        }
    }

    /**
     * Remove a soft ban for a user (soft delete). Admin only.
     */
    public function destroy(Request $request, string $userId): JsonResponse
    {
        try {
            $user = $this->getAuthenticatedUser($request);
            $partitionId = $this->getPartitionId($request);

            if (!$user->is_system_user && !$user->isPartitionAdmin($partitionId)) {
                return $this->errorResponse('Permission denied', 403);
            }

            $ban = UserSoftBan::getActiveBan($userId);

            if (!$ban || $ban->partition_id !== $partitionId) {
                return $this->errorResponse('No active soft ban found for this user', 404);
            }

            $ban->update([
                'deleted' => true,
                'deleted_by' => $user->record_id,
                'deleted_at' => now(),
            ]);

            UserSoftBan::clearBanCache($userId);

            Log::info('User soft-ban removed', [
                'user_id' => $userId,
                'removed_by' => $user->record_id,
                'partition_id' => $partitionId,
            ]);

            return $this->successResponse([
                'message' => 'Soft ban removed successfully',
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to remove soft ban', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);

            return $this->errorResponse('Failed to remove soft ban', 500);
        }
    }
}
