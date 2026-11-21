<?php

namespace App\Repositories;

use App\Models\TripStopAcknowledgement;
use Illuminate\Support\Collection;

/** 2026-11-21 (user): the log of stops marked as reached on each trip. */
class TripStopAcknowledgementRepository
{
    public function record(int $tripId, int $stopId, string $source, ?int $userId): TripStopAcknowledgement
    {
        return TripStopAcknowledgement::create([
            'trip_id' => $tripId,
            'stop_id' => $stopId,
            'source' => $source,
            'acknowledged_by_user_id' => $userId,
            'acknowledged_at' => now(),
        ]);
    }

    /**
     * The acknowledgements of the given trips, grouped by trip id.
     *
     * @param  array<int, int>  $tripIds
     * @return Collection<int, Collection<int, TripStopAcknowledgement>>
     */
    public function forTrips(array $tripIds): Collection
    {
        if ($tripIds === []) {
            return collect();
        }

        return TripStopAcknowledgement::query()->whereIn('trip_id', $tripIds)->orderBy('acknowledged_at')->get()->groupBy('trip_id');
    }
}
