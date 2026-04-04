<?php

namespace NewSolari\Core\Identity\Controllers;

use NewSolari\Core\Events\UserBlocked;
use NewSolari\Core\Events\UserUnblocked;
use NewSolari\Core\Http\BaseController;
use NewSolari\Core\Identity\Models\IdentityUser;
use NewSolari\Core\Identity\Models\UserBlock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class UserBlockController extends BaseController
{
    /**
     * Get the list of users blocked by the authenticated user.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $user = $this->getAuthenticatedUser($request);
            $partitionId = $request->header('X-Partition-ID');

            $query = UserBlock::where('blocker_user_id', $user->record_id);

            if ($partitionId) {
                $query->where('partition_id', $partitionId);
            }

            $blocks = $query
                ->with(['blocked:record_id,username'])
                ->orderBy('created_at', 'desc')
                ->get();

            $data = $blocks->map(function ($block) {
                $blockedUser = $block->blocked;

                return [
                    'record_id' => $block->record_id,
                    'blocked_user_id' => $block->blocked_user_id,
                    'display_name' => $blockedUser->username ?? 'Unknown User',
                    'reason' => $block->reason,
                    'created_at' => $block->created_at?->toIso8601String(),
                ];
            })->toArray();

            return $this->successResponse([
                'data' => $data,
                'count' => count($data),
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to fetch blocked users', [
                'error' => $e->getMessage(),
            ]);

            return $this->errorResponse('Failed to fetch blocked users', 500);
        }
    }

    /**
     * Block a user.
     */
    public function block(Request $request, string $userId): JsonResponse
    {
        try {
            $user = $this->getAuthenticatedUser($request);

            $validator = Validator::make(array_merge($request->all(), ['user_id' => $userId]), [
                'user_id' => 'required|string|max:36',
                'reason' => 'nullable|string|max:500',
            ]);

            if ($validator->fails()) {
                return $this->errorResponse($validator->errors()->first(), 422);
            }

            if ($user->record_id === $userId) {
                return $this->errorResponse('Cannot block yourself', 400);
            }

            $targetUser = IdentityUser::where('record_id', $userId)->first();
            if (! $targetUser) {
                return $this->errorResponse('User not found', 404);
            }

            if ($targetUser->partition_id !== $user->partition_id) {
                return $this->errorResponse('Cannot block users from other partitions', 403);
            }

            if (UserBlock::isBlocked($user->record_id, $userId)) {
                return $this->errorResponse('User is already blocked', 400);
            }

            $block = UserBlock::blockUser(
                $user->record_id,
                $userId,
                $user->partition_id,
                $request->input('reason')
            );

            if (! $block) {
                return $this->errorResponse('Failed to block user', 500);
            }

            event(new UserBlocked($block));

            Log::info('User blocked', [
                'blocker_id' => $user->record_id,
                'blocked_id' => $userId,
                'partition_id' => $user->partition_id,
            ]);

            return $this->successResponse([
                'message' => 'User blocked successfully',
                'record_id' => $block->record_id,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to block user', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);

            return $this->errorResponse('Failed to block user', 500);
        }
    }

    /**
     * Unblock a user.
     */
    public function unblock(Request $request, string $userId): JsonResponse
    {
        try {
            $user = $this->getAuthenticatedUser($request);

            if (! UserBlock::isBlocked($user->record_id, $userId)) {
                return $this->errorResponse('User is not blocked', 404);
            }

            $removed = UserBlock::unblockUser($user->record_id, $userId);

            if (! $removed) {
                return $this->errorResponse('Failed to unblock user', 500);
            }

            event(new UserUnblocked($user->record_id, $userId));

            Log::info('User unblocked', [
                'blocker_id' => $user->record_id,
                'unblocked_id' => $userId,
            ]);

            return $this->successResponse([
                'message' => 'User unblocked successfully',
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to unblock user', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);

            return $this->errorResponse('Failed to unblock user', 500);
        }
    }

    /**
     * Check if a user is blocked.
     */
    public function status(Request $request, string $userId): JsonResponse
    {
        try {
            $user = $this->getAuthenticatedUser($request);

            $isBlocked = UserBlock::isBlocked($user->record_id, $userId);
            $hasBlockedMe = UserBlock::isBlocked($userId, $user->record_id);

            return $this->successResponse([
                'is_blocked' => $isBlocked,
                'has_blocked_me' => $hasBlockedMe,
                'any_block_exists' => $isBlocked || $hasBlockedMe,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to check block status', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);

            return $this->errorResponse('Failed to check block status', 500);
        }
    }
}
