<?php

namespace App\Services;

use App\Models\Trip;
use App\Repositories\FleetLocationRepository;
use App\Repositories\RouteStopRepository;
use Illuminate\Support\Collection;

/**
 * Batch 49 item 2 (user, 2026-11-07): ONE owner for "is this stop still ahead of a bus that has left?". A trip that
 * has departed stays bookable online from a stop the bus has not reached, until the Driver taps that stop or the bus's
 * live position shows it has gone past it. Search, checkout and the payment confirmation all ask this class.
 *
 * Only real data decides. The stop is ahead when ALL of these hold:
 *  - the Driver has not acknowledged it (trips.last_acknowledged_stop_id is behind it);
 *  - the bus has a fresh position (no older than tracking.stale_seconds) that lies on its route line;
 *  - that position is not past the stop by more than booking.later_stop_passed_margin_m.
 * Without a fresh on-route position nothing is known, so no stop of a departed trip is bookable ("Not provided").
 * A trip that has not departed is not this class's concern: every stop of it is ahead.
 */
class TripProgressService
{
    public const RUNNING = ['departed', 'in-progress'];

    /** @var array<int, array<string, mixed>> per trip, for one request */
    private array $snapshots = [];

    public function __construct(
        private RouteStopRepository $routeStops,
        private FleetLocationRepository $locations,
        private RouteSnapService $routeSnap,
        private DropoffRestrictionService $restrictions,
    ) {}

    public static function isRunning(?string $status): bool
    {
        return in_array((string) $status, self::RUNNING, true);
    }

    /** True when a passenger can still board this trip at this stop (always true before the bus leaves). */
    public function stopIsAhead(Trip $trip, int $stopId): bool
    {
        if (! self::isRunning($trip->status)) {
            return true;
        }
        $snapshot = $this->snapshot($trip);
        $index = $snapshot['index_of'][$stopId] ?? null;
        if (! $snapshot['known'] || $index === null || $index <= $snapshot['ack_index']) {
            return false;
        }
        $along = $snapshot['along'][$index] ?? null;

        return $along !== null && $snapshot['bus_along_m'] <= $along + (float) config('booking.later_stop_passed_margin_m');
    }

    /**
     * What passengers see about a trip that has left: "Departed" from a stop behind the bus, "On the way" with the next
     * stop ahead, or null for a trip that has not left. The next stop is null (shown as "Not provided") when unknown.
     *
     * @return array{state: string, label: string, next_stop: string|null}|null
     */
    public function labelFor(Trip $trip, ?int $boardingStopId): ?array
    {
        if ($trip->status === 'boarding') {
            return ['state' => 'boarding', 'label' => 'Boarding', 'next_stop' => null];
        }
        if (! self::isRunning($trip->status)) {
            return null;
        }
        if ($boardingStopId === null || ! $this->stopIsAhead($trip, $boardingStopId)) {
            return ['state' => 'departed', 'label' => 'Departed', 'next_stop' => null];
        }

        return ['state' => 'on_the_way', 'label' => 'On the way', 'next_stop' => $this->nextStopName($trip)];
    }

    private function nextStopName(Trip $trip): ?string
    {
        $snapshot = $this->snapshot($trip);
        foreach ($snapshot['stops'] as $index => $routeStop) {
            if ($this->stopIsAhead($trip, (int) $routeStop->stop_id) && in_array($routeStop->stop_type ?? 'stop', config('timeline.stopping_types'), true)) {
                return $routeStop->stop?->stop_name;
            }
        }

        return null;
    }

    /** @return array{known: bool, ack_index: int, bus_along_m: float|null, stops: Collection, index_of: array<int, int>, along: array<int, float|null>} */
    private function snapshot(Trip $trip): array
    {
        $key = (int) $trip->trip_id;
        if (isset($this->snapshots[$key])) {
            return $this->snapshots[$key];
        }

        $trip->loadMissing('fleetRoute');
        $routeId = (int) $trip->fleetRoute->route_id;
        $direction = $trip->leg_direction === 'reverse' ? 'reverse' : 'outbound';
        $stops = $this->routeStops->getOrderedStopsForDirection($routeId, $direction)->values();

        $indexOf = [];
        foreach ($stops as $index => $routeStop) {
            $indexOf[(int) $routeStop->stop_id] = $index;
        }
        $ackIndex = $trip->last_acknowledged_stop_id !== null ? ($indexOf[(int) $trip->last_acknowledged_stop_id] ?? -1) : -1;

        // Where each stop sits along the line in this direction (route_stops.distance_from_origin_m is outbound).
        $length = $direction === 'reverse' ? $this->restrictions->routeLengthM($routeId) : null;
        $along = [];
        foreach ($stops as $index => $routeStop) {
            $outbound = $routeStop->distance_from_origin_m;
            $along[$index] = $outbound === null ? null : ($direction === 'reverse' ? ($length === null ? null : $length - (float) $outbound) : (float) $outbound);
        }

        $busAlong = null;
        $position = $this->locations->getCurrentForTrip((int) $trip->fleetRoute->fleet_id, (int) $trip->trip_id);
        if ($position !== null && $position['age_seconds'] <= (int) config('tracking.stale_seconds')) {
            $snap = $this->routeSnap->snap($routeId, (float) $position['latitude'], (float) $position['longitude'], $direction);
            if ($snap !== null && ($snap['on_route'] ?? false)) {
                $busAlong = (float) $snap['distance_along_route_m'];
            }
        }

        return $this->snapshots[$key] = [
            'known' => $busAlong !== null,
            'ack_index' => (int) $ackIndex,
            'bus_along_m' => $busAlong,
            'stops' => $stops,
            'index_of' => $indexOf,
            'along' => $along,
        ];
    }
}
