<?php

namespace App\Services;

use App\Models\Trip;
use App\Repositories\FleetLocationRepository;
use App\Repositories\RouteStopRepository;
use Illuminate\Support\Collection;

/**
 * Batch 27 Part 2, Task 1: how a shift block progresses leg by leg.
 *
 * One place for the rules that used to be spread out (or missing):
 *  - the legs of a block, in travel order (departure time, then id);
 *  - the CURRENT leg = the first leg that is not completed or cancelled;
 *  - leg N+1 may only start (boarding or departure) when every earlier leg is completed or cancelled;
 *  - from the second leg on, the bus must be at that leg's origin terminal (the Location Match Rule);
 *  - the pairing scope: the first leg stands for the whole block, so one pairing and one PIN serve all legs.
 *
 * Start Shift and End Shift stay separate from all this: they only open and close the staff member's shift.
 */
class LegProgressionService
{
    /** Leg statuses that are finished for ordering and for End Shift. */
    public const TERMINAL = ['completed', 'cancelled'];

    public function __construct(
        private FleetLocationRepository $fleetLocationRepository,
        private RouteStopRepository $routeStopRepository,
    ) {}

    /** @return Collection<int, Trip> */
    public function legsOfBlock(int $shiftBlockId): Collection
    {
        return Trip::where('shift_block_id', $shiftBlockId)
            ->orderBy('departure_time')
            ->orderBy('trip_id')
            ->get();
    }

    /** The first leg that is not finished, or null when every leg is completed or cancelled. */
    public function currentLeg(int $shiftBlockId): ?Trip
    {
        return $this->legsOfBlock($shiftBlockId)->first(fn (Trip $leg) => ! in_array($leg->status, self::TERMINAL, true));
    }

    /** The trip that stands for the block in the pin table and in pairing tokens: its first leg. */
    public function pairingScopeTripId(int $tripId): int
    {
        $blockId = Trip::where('trip_id', $tripId)->value('shift_block_id');
        if (! $blockId) {
            return $tripId;
        }

        return (int) ($this->legsOfBlock((int) $blockId)->first()?->trip_id ?? $tripId);
    }

    /**
     * Why this leg may not start yet, or null. Earlier unfinished legs first (no out-of-order starts),
     * then the Location Match Rule from the second leg on.
     *
     * @return array{0: string, 1: int}|null [message, http status]
     */
    public function startBlockedReason(Trip $trip, bool $checkLocation): ?array
    {
        if (! $trip->shift_block_id) {
            return null;
        }

        $legs = $this->legsOfBlock((int) $trip->shift_block_id);
        $index = $legs->search(fn (Trip $leg) => (int) $leg->trip_id === (int) $trip->trip_id);
        if ($index === false) {
            return null;
        }

        $previous = $legs->slice(0, $index)->first(fn (Trip $leg) => ! in_array($leg->status, self::TERMINAL, true));
        if ($previous) {
            return ['Finish leg '.($legs->search(fn (Trip $l) => (int) $l->trip_id === (int) $previous->trip_id) + 1).' of this shift block first: each leg starts only after the one before it is completed or cancelled.', 422];
        }

        if ($index > 0 && $checkLocation && config('shift_blocks.leg_location_rule_enabled')) {
            return $this->locationBlockedReason($trip);
        }

        return null;
    }

    /** The bus must be within the geofence of this leg's origin terminal. */
    private function locationBlockedReason(Trip $trip): ?array
    {
        $trip->loadMissing('fleetRoute');
        $direction = $trip->leg_direction === 'reverse' ? 'reverse' : 'outbound';
        $origin = $this->routeStopRepository->getOrderedStopsForDirection((int) $trip->fleetRoute->route_id, $direction)->first()?->stop;
        if (! $origin || $origin->latitude === null || $origin->longitude === null) {
            return ['Could not determine this leg\'s origin terminal, so the bus location cannot be checked.', 422];
        }

        $position = $this->fleetLocationRepository->getCurrentLatLng((int) $trip->fleetRoute->fleet_id);
        if (! $position) {
            return ['No GPS position has been recorded for this bus yet, so its location at the terminal cannot be confirmed.', 422];
        }

        $radius = (int) ($origin->geofence_radius_m ?? config('shift_blocks.default_geofence_radius_m'));
        $distance = $this->haversineMeters($position['latitude'], $position['longitude'], (float) $origin->latitude, (float) $origin->longitude);
        if ($distance > $radius) {
            return ['The bus is '.round($distance).' m from '.$origin->stop_name." (it must be within {$radius} m) before this leg can start.", 422];
        }

        return null;
    }

    /**
     * Why End Shift is not allowed now, or null. Single source for the status payload and the guard,
     * for a standalone trip and for a shift block alike.
     */
    public function endBlockedReason(?Trip $anchorTrip): ?string
    {
        if (! $anchorTrip) {
            return null;
        }

        if ($anchorTrip->shift_block_id) {
            $legs = $this->legsOfBlock((int) $anchorTrip->shift_block_id);
            if ($legs->contains(fn (Trip $leg) => in_array($leg->status, ['boarding', 'departed', 'in-progress'], true))) {
                return 'Cannot end shift while a leg of your shift block is still boarding or in transit. Complete the trip first.';
            }
            $unfinished = $legs->filter(fn (Trip $leg) => ! in_array($leg->status, self::TERMINAL, true))->count();
            if ($unfinished > 0) {
                return "Cannot end shift: {$unfinished} leg(s) of your shift block are not completed or cancelled yet.";
            }

            return null;
        }

        if (in_array(strtolower((string) $anchorTrip->status), ['boarding', 'departed', 'in-progress'], true)) {
            return 'Cannot end shift while your assigned trip is still boarding or in transit. Complete the trip first.';
        }

        return null;
    }

    /** A shift left open is stale when its trip (or, for a block, every leg) is completed or cancelled. */
    public function isShiftStale(?Trip $trip): bool
    {
        if (! $trip) {
            return true;
        }
        if ($trip->shift_block_id) {
            return $this->currentLeg((int) $trip->shift_block_id) === null;
        }

        return in_array(strtolower((string) $trip->status), self::TERMINAL, true);
    }

    /** True when two trips are the same shift: the same trip, or two legs of one block. */
    public function sameShift(?object $a, ?object $b): bool
    {
        if (! $a || ! $b) {
            return false;
        }
        if ((int) ($a->trip_id ?? 0) === (int) ($b->trip_id ?? -1)) {
            return true;
        }

        $blockA = $a->shift_block_id ?? null;

        return $blockA !== null && (int) $blockA === (int) ($b->shift_block_id ?? 0);
    }

    /** Great-circle distance in metres (same formula as ShiftBlockService and the reporting repository). */
    private function haversineMeters(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earth = 6371000.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return $earth * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
