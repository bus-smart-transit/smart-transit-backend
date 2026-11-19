<?php

namespace App\Services;

use App\Models\RouteStop;
use App\Models\Trip;
use App\Repositories\FleetLocationRepository;
use App\Support\ServiceType;

/**
 * Batch 27 Part 2, Task 6: where is the bus right now, as a boarding point on the trip's route?
 *
 * The client never sends coordinates. The server takes the latest position the bus itself
 * uploaded for this trip (the upload already refuses fixes worse than the route-geometry
 * accuracy limit), snaps it to the route line for the leg's direction (PHP GeoLine, the same
 * snapping as everything else) and reports the distance along the leg. When the position is
 * missing, old, too imprecise or not on the route, nothing is guessed: the state says why and
 * the Chauffeur picks the boarding stop instead.
 */
class MidTripBoardingService
{
    public const STATE_GOOD = 'good';

    public const STATE_NONE = 'none';

    public const STATE_STALE = 'stale';

    public const STATE_POOR_ACCURACY = 'poor_accuracy';

    public const STATE_OFF_ROUTE = 'off_route';

    public const STATE_NO_GEOMETRY = 'no_geometry';

    public function __construct(
        private FleetLocationRepository $fleetLocationRepository,
        private RouteSnapService $routeSnapService,
        private CustomDropoffService $customDropoffService,
    ) {}

    /**
     * @return array{
     *   state: string,
     *   message: string|null,
     *   age_seconds: int|null,
     *   accuracy_m: float|null,
     *   boarding_point: array{latitude: float, longitude: float, distance_from_origin_km: float, distance_from_origin_m: int, distance_along_leg_m: float, distance_to_route_m: float}|null
     * }
     */
    public function resolve(Trip $trip): array
    {
        $routeId = (int) $trip->fleetRoute->route_id;
        $fleetId = (int) $trip->fleetRoute->fleet_id;
        $direction = $trip->leg_direction === 'reverse' ? 'reverse' : 'outbound';
        $cfg = config('booking.boarding_gps');

        if (! $this->routeSnapService->hasGeometry($routeId)) {
            return $this->weak(self::STATE_NO_GEOMETRY, 'This route has no generated path yet, so the bus position cannot be matched to it.');
        }

        $fix = $this->fleetLocationRepository->getCurrentForTrip($fleetId, (int) $trip->trip_id);
        if (! $fix) {
            return $this->weak(self::STATE_NONE, 'No GPS position has been received from this bus for the current trip.');
        }

        $age = (int) $fix['age_seconds'];
        $accuracy = $fix['accuracy_m'] ?? null;

        if ($age > (int) $cfg['max_age_seconds']) {
            return $this->weak(self::STATE_STALE, 'The last GPS position is '.$age.' seconds old.', $age, $accuracy);
        }

        if ($accuracy !== null && $accuracy > (float) $cfg['max_accuracy_m']) {
            return $this->weak(self::STATE_POOR_ACCURACY, 'The GPS position is only accurate to about '.round($accuracy).' m.', $age, $accuracy);
        }

        $located = $this->customDropoffService->locate($routeId, $direction, (float) $fix['latitude'], (float) $fix['longitude']);
        if (! $located) {
            return $this->weak(self::STATE_NO_GEOMETRY, 'This route has no generated path yet, so the bus position cannot be matched to it.', $age, $accuracy);
        }

        $snap = $located['snap'];
        if (! $snap['on_route']) {
            return $this->weak(self::STATE_OFF_ROUTE, 'The bus is '.round($snap['distance_to_route_m']).' m from the route line.', $age, $accuracy);
        }

        $km = $located['km_from_origin'];

        return [
            'state' => self::STATE_GOOD,
            'message' => null,
            'age_seconds' => $age,
            'accuracy_m' => $accuracy,
            'boarding_point' => [
                'latitude' => $snap['snapped_latitude'],
                'longitude' => $snap['snapped_longitude'],
                'distance_from_origin_km' => $km,
                'distance_from_origin_m' => (int) round($km * 1000),
                'distance_along_leg_m' => $snap['distance_along_route_m'],
                'distance_to_route_m' => $snap['distance_to_route_m'],
            ],
        ];
    }

    /**
     * Snap back (2026-10-26): the last stop this trip SERVES that the bus has already passed on its leg, for a point
     * $routeKm from the route's first stop. Outbound the bus travels up the route (the stop at or before the point);
     * reverse it travels down (the stop at or after it). A cash fare is counted from this stop, the usual stage-fare
     * rule, so collecting the fare later never lowers it. Null only if no served stop is behind the point.
     */
    public function lastStopPassed(Trip $trip, float $routeKm): ?RouteStop
    {
        $reverse = $trip->leg_direction === 'reverse';
        $served = RouteStop::with('stop')
            ->where('route_id', (int) $trip->fleetRoute->route_id)
            ->get()
            ->filter(fn (RouteStop $routeStop) => ServiceType::isServed($trip->service_type, $routeStop));
        $tolerance = 0.001; // a stop the bus is standing at counts as passed

        return $reverse
            ? $served->filter(fn ($s) => (float) $s->distance_from_origin_km >= $routeKm - $tolerance)->sortBy('distance_from_origin_km')->first()
            : $served->filter(fn ($s) => (float) $s->distance_from_origin_km <= $routeKm + $tolerance)->sortByDesc('distance_from_origin_km')->first();
    }

    private function weak(string $state, string $message, ?int $age = null, ?float $accuracy = null): array
    {
        return [
            'state' => $state,
            'message' => $message,
            'age_seconds' => $age,
            'accuracy_m' => $accuracy,
            'boarding_point' => null,
        ];
    }
}
