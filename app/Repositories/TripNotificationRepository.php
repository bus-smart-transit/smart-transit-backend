<?php

namespace App\Repositories;

use App\Models\TripNotification;
use Illuminate\Support\Collection;

class TripNotificationRepository
{
    public function create(array $payload): TripNotification
    {
        return TripNotification::create($payload);
    }

    /**
     * Most recent notifications first, for the Operator bell/badge UI.
     */
    public function listRecent(int $limit = 50): Collection
    {
        return TripNotification::with(['trip.fleetRoute.route', 'trip.fleetRoute.fleet', 'actor.user'])
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();
    }

    public function countUnread(): int
    {
        return TripNotification::whereNull('read_at')->count();
    }

    public function markRead(int $id): bool
    {
        return TripNotification::where('id', $id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]) > 0;
    }

    /**
     * 2026-10-27: a group of Operator notifications marked read at once (the bell groups the same kind together).
     *
     * @param  list<int>  $ids
     */
    public function markManyRead(array $ids): int
    {
        return TripNotification::whereIn('id', $ids)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }

    public function markAllRead(): int
    {
        return TripNotification::whereNull('read_at')->update(['read_at' => now()]);
    }
}
