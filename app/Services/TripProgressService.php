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
 *
 * Batch 50 (user, 2026-11-08): `live()` is the one answer to "where is the bus": the stops passed (the Driver's taps and
 * the live position, whichever is further), the stop it is at or heading to, whether it has arrived at its final stop,
 * and the live ETA to every stop ahead (distance left along the route line at the bus's reported speed). While the GPS
 * simulator is switched on (route_geometry.gps.simulator_enabled, the user's demo switch) a simulated position counts
 * like a phone's; refunds and the Chauffeur's GPS boarding never use it (they read the repository directly).
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

    /**
     * Batch 50: where a running bus is. passed_index: the last stop it has passed (-1 none); arrived: it is at (or the
     * Driver tapped) its final stop; eta: stop index => seconds until the bus reaches it (live GPS only, stops ahead).
     *
     * @return array{running: bool, known: bool, passed_index: int, at_index: int|null, arrived: bool, eta_seconds: array<int, int>, simulated: bool}
     */
    public function live(Trip $trip): array
    {
        $none = ['running' => false, 'known' => false, 'passed_index' => -1, 'at_index' => null, 'arrived' => false, 'eta_seconds' => [], 'simulated' => false];
        if (! self::isRunning($trip->status)) {
            return $none;
        }
        $snap = $this->snapshot($trip);
        $last = $snap['stops']->count() - 1;
        $passed = $snap['ack_index'];
        $at = null;
        $eta = [];
        if ($snap['known']) {
            $radius = (float) config('tracking.at_stop_radius_m');
            $metresPerSecond = max(1.0, ((float) ($snap['speed_kmh'] ?? 0) > 3 ? (float) $snap['speed_kmh'] : (float) config('tracking.eta_fallback_kmh')) / 3.6);
            foreach ($snap['along'] as $index => $along) {
                if ($along === null) {
                    continue;
                }
                $gap = $along - $snap['bus_along_m'];
                if ($gap < -$radius) {
                    $passed = max($passed, $index);        // well behind the bus
                } elseif (abs($gap) <= $radius && $index > $snap['ack_index']) {
                    $at ??= $index;                        // at this stop now
                } elseif ($index > $passed) {
                    $eta[$index] = (int) max(0, round($gap / $metresPerSecond - (int) ($snap['age_seconds'] ?? 0)));
                }
            }
        }
        // Arrived: the Driver tapped the last stop, or the bus is (or its last position, however old, is) at it.
        $finalAlong = $last >= 0 ? ($snap['along'][$last] ?? null) : null;
        $parkedAtEnd = $finalAlong !== null && $snap['last_along_m'] !== null && $snap['last_along_m'] >= $finalAlong - (float) config('tracking.at_stop_radius_m');
        $arrived = $last >= 0 && ($snap['ack_index'] >= $last || $at === $last || $passed >= $last || $parkedAtEnd);
        if ($arrived) {
            $passed = $last;
            $at = $last;
            $eta = [];
        }

        return ['running' => true, 'known' => $snap['known'], 'passed_index' => $passed, 'at_index' => $at, 'arrived' => $arrived, 'eta_seconds' => $eta, 'simulated' => $snap['simulated']];
    }

    /**
     * Batch 60 item 1 (user, 2026-11-11): has the bus reached or gone past this ticket's destination? True when the
     * Driver has tapped that stop (or a later one), or the bus has arrived at its final stop, or the fresh live position
     * is past the destination by booking.ticket_expiry_passed_margin_m (GPS noise margin). A pinpoint drop-off (no stop)
     * uses its stored distance along the route. Without a tap or a fresh on-route position nothing is known: false.
     */
    public function destinationReached(Trip $trip, object $ticket): bool
    {
        if (! self::isRunning($trip->status)) {
            return false;
        }
        $snap = $this->snapshot($trip);
        $index = ($ticket->destination_stop_id ?? null) !== null ? ($snap['index_of'][(int) $ticket->destination_stop_id] ?? null) : null;
        if ($index !== null && $index <= $snap['ack_index']) {
            return true;
        }
        if ($this->live($trip)['arrived']) {
            return true;
        }
        if (! $snap['known']) {
            return false;
        }
        $along = $index !== null ? ($snap['along'][$index] ?? null) : $this->alongFromOutboundM($trip, $ticket->alighting_distance_m ?? null);

        return $along !== null && $snap['bus_along_m'] > $along + (float) config('booking.ticket_expiry_passed_margin_m');
    }

    /** A stored outbound distance (metres from the route's first stop) as metres along this trip's direction. */
    private function alongFromOutboundM(Trip $trip, mixed $outboundM): ?float
    {
        if ($outboundM === null) {
            return null;
        }
        if ($trip->leg_direction !== 'reverse') {
            return (float) $outboundM;
        }
        $length = $this->restrictions->routeLengthM((int) $trip->fleetRoute->route_id);

        return $length === null ? null : $length - (float) $outboundM;
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
        $lastAlong = null;
        $position = $this->locations->getCurrentForTrip((int) $trip->fleetRoute->fleet_id, (int) $trip->trip_id, includeSimulated: (bool) config('route_geometry.gps.simulator_enabled'));
        if ($position !== null) {
            $snap = $this->routeSnap->snap($routeId, (float) $position['latitude'], (float) $position['longitude'], $direction);
            if ($snap !== null && ($snap['on_route'] ?? false)) {
                // Batch 50: an old position still says where the bus stopped (a bus parked at its last stop has arrived);
                // only a fresh one is used for the ETA and for selling stops ahead.
                $lastAlong = (float) $snap['distance_along_route_m'];
                if ($position['age_seconds'] <= (int) config('tracking.stale_seconds')) {
                    $busAlong = $lastAlong;
                }
            }
        }

        return $this->snapshots[$key] = [
            'known' => $busAlong !== null,
            'ack_index' => (int) $ackIndex,
            'bus_along_m' => $busAlong,
            'last_along_m' => $lastAlong,
            'speed_kmh' => $position['speed_kmh'] ?? null,
            'age_seconds' => $position['age_seconds'] ?? null,
            'simulated' => (bool) ($position['simulated'] ?? false),
            'stops' => $stops,
            'index_of' => $indexOf,
            'along' => $along,
        ];
    }
}
