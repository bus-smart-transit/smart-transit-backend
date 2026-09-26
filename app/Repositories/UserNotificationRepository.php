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

    public function markAllReadForUser(int $userId): int
    {
        return UserNotification::where('user_id', $userId)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }
}
