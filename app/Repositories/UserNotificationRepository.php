<?php

namespace App\Repositories;

use App\Models\UserNotification;
use Illuminate\Support\Collection;

class UserNotificationRepository
{
    public function createForUser(int $userId, string $type, string $title, string $body, array $data = []): UserNotification
    {
        return UserNotification::create([
            'user_id' => $userId,
            'type' => $type,
            'title' => $title,
            'body' => $body,
            'data' => $data,
        ]);
    }

    /**
     * Most recent notifications for a user, newest first. Capped at 50 —
     * this is a recent-activity feed, not a full historical archive.
     */
    public function listRecentForUser(int $userId, int $limit = 50): Collection
    {
        return UserNotification::where('user_id', $userId)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();
    }

    public function countUnreadForUser(int $userId): int
    {
        return UserNotification::where('user_id', $userId)
            ->whereNull('read_at')
            ->count();
    }

    public function markReadForUser(int $notificationId, int $userId): bool
    {
        return UserNotification::where('notification_id', $notificationId)
            ->where('user_id', $userId)
            ->whereNull('read_at')
            ->update(['read_at' => now()]) > 0;
    }

    /**
     * 2026-10-27: a group of this user's notifications marked read at once (the bell groups the same kind together).
     * Ids that are not this user's are ignored.
     *
     * @param  list<int>  $notificationIds
     */
    public function markManyReadForUser(array $notificationIds, int $userId): int
    {
        return UserNotification::whereIn('notification_id', $notificationIds)
            ->where('user_id', $userId)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }

    public function markAllReadForUser(int $userId): int
    {
        return UserNotification::where('user_id', $userId)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }
}
