<?php

namespace App\Services;

use App\Repositories\RouteRepository;
use App\Support\BusLabel;
use App\Support\ServiceType;
use App\Repositories\RouteStopRepository;
use App\Repositories\TripRepository;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * A2: the passenger trip-stops timeline, computed entirely on the server.
 *
 * - Stops are shown in the leg's travel direction (B1), so order, distances
 *   and offsets already reverse on even legs.
 * - ETA = scheduled departure (Manila) + the stop's scheduled offset, moved by
 *   real progress once the trip is under way (last acknowledged stop and time).
 *   When a value cannot be computed it is null ("ETA unavailable"); nothing is
 *   invented.
 * - Choosing an alighting stop, or a custom drop-off pin, recomputes the badge
 *   positions, duration, stop count, distance and fare here. The fare uses the
 *   existing FareRuleService (D4), the same call the ticket pricing makes.
 */
class TripTimelineService
{
    public function __construct(
        private TripRepository $tripRepository,
        private RouteRepository $routeRepository,
        private RouteStopRepository $routeStopRepository,
        private CustomDropoffService $customDropoffService,
        private FareRuleService $fareRuleService,
        private RouteDurationFactorService $durationFactors,
    ) {}

    /**
     * @param  array{boarding_stop_id?: int|null, alighting_stop_id?: int|null, dropoff_lat?: float|null, dropoff_lng?: float|null, seat_type?: string|null}  $selection
     *
     * @throws ValidationException
     */
    public function build(int $tripId, array $selection = []): array
    {
        $trip = $this->tripRepository->findById($tripId);
        if (! $trip || ! $trip->fleetRoute) {
            throw ValidationException::withMessages(['trip' => ['Trip not found.']]);
        }

        $timezone = config('app.timezone', 'Asia/Manila');
        $routeId = (int) $trip->fleetRoute->route_id;
        $route = $this->routeRepository->findById($routeId);
        $direction = $trip->leg_direction === 'reverse' ? 'reverse' : 'outbound';
        $service = ServiceType::normalize($trip->service_type);
        $express = $service === ServiceType::EXPRESS;
        $stopping = config('timeline.stopping_types');

        $routeStops = $this->routeStopRepository->getOrderedStopsForDirection($routeId, $direction)->values();
        if ($routeStops->isEmpty()) {
            throw ValidationException::withMessages(['trip' => ['This trip\'s route has no stops yet.']]);
        }

        $boardingId = isset($selection['boarding_stop_id']) ? (int) $selection['boarding_stop_id'] : null;
        $alightingId = isset($selection['alighting_stop_id']) ? (int) $selection['alighting_stop_id'] : null;
        $hasPin = isset($selection['dropoff_lat'], $selection['dropoff_lng']);
        // Batch 28 Stage 3, item 10: a designated drop-off point is chosen by id (never by coordinates).
        $pointId = isset($selection['alighting_point_id']) ? (int) $selection['alighting_point_id'] : null;

        if ($alightingId !== null && ($hasPin || $pointId !== null)) {
            throw ValidationException::withMessages(['alighting_stop_id' => ['Choose either a stop or a custom drop-off, not both.']]);
        }
        if ($pointId !== null && $hasPin) {
            throw ValidationException::withMessages(['alighting_point_id' => ['Choose either a drop-off point or a pin, not both.']]);
        }
        if ($express && ($hasPin || $pointId !== null)) {
            throw ValidationException::withMessages(['dropoff_lat' => ['A custom drop-off is not available on an express trip.']]);
        }
        if (($alightingId !== null || $hasPin || $pointId !== null) && $boardingId === null) {
            throw ValidationException::withMessages(['boarding_stop_id' => ['Choose where you board first.']]);
        }

        $indexById = $routeStops->pluck('stop_id')->flip();
        foreach (['boarding_stop_id' => $boardingId, 'alighting_stop_id' => $alightingId] as $field => $stopId) {
            if ($stopId === null) {
                continue;
            }
            if (! isset($indexById[$stopId])) {
                throw ValidationException::withMessages([$field => ['That stop is not on this trip\'s route.']]);
            }
            if (! ServiceType::isServed($service, $routeStops[$indexById[$stopId]])) {
                throw ValidationException::withMessages([$field => [$express ? 'This express trip does not stop there.' : 'Buses do not stop at that point.']]);
            }
        }
        if ($boardingId !== null && $alightingId !== null && $indexById[$alightingId] <= $indexById[$boardingId]) {
            throw ValidationException::withMessages(['alighting_stop_id' => ['Choose a stop after your boarding stop for this trip\'s direction.']]);
        }

        $custom = null;
        if ($pointId !== null) {
            $custom = $this->customDropoffService->evaluatePoint($routeId, $direction, $boardingId, null, $pointId);
        } elseif ($hasPin) {
            $custom = $this->customDropoffService->evaluate($routeId, $direction, $boardingId, (float) $selection['dropoff_lat'], (float) $selection['dropoff_lng']);
        }

        $base = $this->scheduledStart($trip, $timezone);
        // Batch 28 Stage 3, item 7: a configured peak-hour window scales every offset (1.0 when no window matches).
        $factor = $this->durationFactors->factorForTrip($trip);
        $live = $this->liveProgress($trip, $routeStops, $base, $service, $factor);
        $tripOver = in_array($trip->status, ['completed'], true);
        $etaAvailable = $trip->status !== 'cancelled' && $base !== null;

        $rows = [];
        foreach ($routeStops as $index => $routeStop) {
            $eta = $etaAvailable ? $this->etaFor($base, $this->durationFactors->scale(ServiceType::offsetMinutes($service, $routeStop), $factor), $live['delay_minutes'], $live['after_index'], $index) : null;
            $rows[] = [
                'stop_id' => (int) $routeStop->stop_id,
                'name' => $routeStop->stop->stop_name,
                'municipality' => $routeStop->stop->municipality,
                'type' => ServiceType::effectiveStopType($service, $routeStop),
                'sequence' => (int) $routeStop->stop_order,
                'distance_km' => round((float) $routeStop->distance_from_origin_km, 2),
                'eta' => $eta?->toIso8601String(),
                'eta_time' => $eta?->format('H:i'),
                'eta_source' => $eta === null ? null : ($live['applied'] && $index > $live['after_index'] ? 'live' : 'scheduled'),
                'progress' => $this->progressFor($trip->status, $tripOver, $live, $index),
                'custom' => false,
            ];
        }

        $fare = null;
        $customRow = null;
        if ($custom !== null) {
            $eta = $etaAvailable && $custom['offset_min'] !== null
                ? $base->copy()->addMinutes((int) round($custom['offset_min'] * $factor + ($live['applied'] ? $live['delay_minutes'] : 0)))
                : null;
            $customRow = [
                'stop_id' => null,
                'name' => $custom['alighting_point']['name'] ?? 'Custom drop-off',
                'municipality' => null,
                'type' => 'custom',
                'point_kind' => $custom['alighting_point']['kind'] ?? null,
                'alighting_point_id' => $custom['alighting_point']['id'] ?? null,
                'sequence' => null,
                'distance_km' => round($custom['km_from_origin'], 2),
                'eta' => $eta?->toIso8601String(),
                'eta_time' => $eta?->format('H:i'),
                'eta_source' => $eta === null ? null : ($live['applied'] ? 'live' : 'scheduled'),
                'progress' => 'upcoming',
                'custom' => true,
                'latitude' => $custom['snapped_latitude'],
                'longitude' => $custom['snapped_longitude'],
                'distance_from_route_m' => $custom['distance_to_route_m'],
            ];

            // Insert after the last stop that precedes the pin along the route.
            $insertAt = 0;
            foreach ($rows as $i => $row) {
                if ($row['distance_km'] <= $customRow['distance_km']) {
                    $insertAt = $i + 1;
                }
            }
            array_splice($rows, $insertAt, 0, [$customRow]);
        }

        $rows = $this->applyJourney($rows, $boardingId, $alightingId, $custom !== null);
        $journey = $this->journeySummary($rows, $stopping);

        if ($journey !== null && ! empty($selection['seat_type'])) {
            $fare = $this->fareFor($trip, $routeStops, $rows, $boardingId, $alightingId, $custom, (string) $selection['seat_type']);
            if ($fare && isset($fare['distance_km']) && $custom !== null) {
                $journey['distance_km'] = $fare['distance_km'];
            }
        }

        $first = $routeStops->first();
        $last = $routeStops->last();

        return [
            'trip' => [
                'trip_id' => (int) $trip->trip_id,
                'trip_date' => $trip->trip_date?->toDateString(),
                'departure_time' => $base?->format('H:i'),
                'status' => $trip->status,
                'leg_direction' => $direction,
                'service_type' => $service,
                'service' => \App\Support\TripRunInfo::service($service, $routeStops),
                'run_pattern' => \App\Support\TripRunInfo::runPattern(
                    $trip->trip_type,
                    $trip->shift_block_id ? (int) $trip->shift_block_id : null,
                    $trip->shift_block_id ? \App\Models\Trip::where('shift_block_id', $trip->shift_block_id)->count() : null,
                ),
                'duration_factor' => $factor,
                'route_id' => $routeId,
                'route_name' => $route?->route_name,
                'timezone' => $timezone,
            ],
            'bus' => [
                'fleet_id' => (int) $trip->fleetRoute->fleet_id,
                'label' => BusLabel::for($trip->fleetRoute->fleet),
                // fleets.fleet_type is public/private ownership; fleets.vehicle_class is the comfort class (item 5a).
                // Each is null when the operator has not set it; the screen then says "not specified".
                'fleet_type' => $trip->fleetRoute->fleet?->fleet_type,
                'class' => $trip->fleetRoute->fleet?->vehicle_class,
                'class_label' => \App\Support\VehicleClass::label($trip->fleetRoute->fleet?->vehicle_class),
            ],
            'header' => [
                'from' => $first->stop->stop_name,
                'bound_for' => $last->stop->stop_name,
                'via' => $this->viaNames($routeStops, $service),
                'duration_minutes' => $journey['duration_minutes'] ?? null,
                'stops_between' => $journey['stops_between'] ?? null,
                'distance_km' => $journey['distance_km'] ?? null,
            ],
            'journey' => [
                'boarding_stop_id' => $boardingId,
                'alighting_stop_id' => $alightingId,
                'custom_dropoff' => $custom !== null,
            ],
            'allow_custom_dropoff' => ! $express && (bool) ($route?->allow_custom_dropoff),
            'dropoff_mode' => $express || ! $route ? \App\Models\Route::MODE_OFF : $route->dropoffMode(),
            'custom_dropoff_availability' => $express
                ? ['available' => false, 'reason' => 'Custom drop-off is not available on an express trip.']
                : $this->customDropoffService->availability($routeId, $direction, $boardingId),
            'fare' => $fare,
            'collapse_min_stops' => (int) config('timeline.collapse_min_stops'),
            'refresh' => [
                'poll_interval_seconds' => (int) config('timeline.poll_interval_seconds'),
                'generated_at' => Carbon::now($timezone)->toIso8601String(),
                'eta_basis' => $live['applied'] ? 'live' : ($etaAvailable ? 'scheduled' : 'unavailable'),
            ],
            'stops' => $rows,
        ];
    }

    private function scheduledStart($trip, string $timezone): ?Carbon
    {
        $time = $trip->departure_time ?: $trip->fleetRoute?->start_time;
        if (! $trip->trip_date || ! $time) {
            return null;
        }

        return Carbon::parse($trip->trip_date->toDateString().' '.$time, $timezone);
    }

    /**
     * Real progress: the delay observed at the last acknowledged stop is carried
     * to the stops still ahead. Only when the trip has departed and that stop has
     * both a timestamp and a scheduled time.
     *
     * @return array{applied: bool, delay_minutes: float, after_index: int}
     */
    private function liveProgress($trip, $routeStops, ?Carbon $base, string $service = ServiceType::REGULAR, float $factor = 1.0): array
    {
        $none = ['applied' => false, 'delay_minutes' => 0.0, 'after_index' => -1];
        if (! in_array($trip->status, ['departed', 'in-progress', 'completed'], true) || ! $trip->last_acknowledged_stop_id) {
            return $none;
        }

        $ackIndex = $routeStops->search(fn ($s) => (int) $s->stop_id === (int) $trip->last_acknowledged_stop_id);
        if ($ackIndex === false) {
            return $none;
        }

        $result = ['applied' => false, 'delay_minutes' => 0.0, 'after_index' => (int) $ackIndex];
        $offset = $this->durationFactors->scale(ServiceType::offsetMinutes($service, $routeStops[$ackIndex]), $factor);
        if ($base === null || $offset === null || ! $trip->last_acknowledged_at) {
            return $result;
        }

        $scheduledAck = $base->copy()->addMinutes((int) $offset);
        $result['delay_minutes'] = (float) $scheduledAck->diffInMinutes($trip->last_acknowledged_at, false);
        $result['applied'] = true;

        return $result;
    }

    private function etaFor(?Carbon $base, $offset, float $delay, int $afterIndex, int $index): ?Carbon
    {
        if ($base === null || $offset === null) {
            return null;
        }

        $minutes = (int) $offset + ($index > $afterIndex ? $delay : 0);

        return $base->copy()->addMinutes((int) round($minutes));
    }

    private function progressFor(string $status, bool $tripOver, array $live, int $index): string
    {
        if ($tripOver) {
            return 'passed';
        }
        if (! in_array($status, ['departed', 'in-progress'], true)) {
            return 'upcoming';
        }
        if ($index <= $live['after_index']) {
            return 'passed';
        }

        return $index === $live['after_index'] + 1 ? 'current' : 'upcoming';
    }

    /** Marks boarding/alighting rows and which rows lie inside the passenger's journey. */
    private function applyJourney(array $rows, ?int $boardingId, ?int $alightingId, bool $hasCustom): array
    {
        $stopping = config('timeline.stopping_types');
        $boardIndex = $boardingId !== null ? $this->indexOfStop($rows, $boardingId) : null;
        $alightIndex = null;
        if ($alightingId !== null) {
            $alightIndex = $this->indexOfStop($rows, $alightingId);
        } elseif ($hasCustom) {
            foreach ($rows as $i => $row) {
                if ($row['custom']) {
                    $alightIndex = $i;
                }
            }
        }

        foreach ($rows as $i => &$row) {
            $row['is_boarding'] = $boardIndex !== null && $i === $boardIndex;
            $row['is_alighting'] = $alightIndex !== null && $i === $alightIndex;
            $row['in_journey'] = ($boardIndex === null || $i >= $boardIndex) && ($alightIndex === null || $i <= $alightIndex);
            $row['selectable'] = ! $row['custom']
                && in_array($row['type'], $stopping, true)
                && ($boardIndex === null || $i > $boardIndex);
        }
        unset($row);

        return $rows;
    }

    private function indexOfStop(array $rows, int $stopId): ?int
    {
        foreach ($rows as $i => $row) {
            if (! $row['custom'] && $row['stop_id'] === $stopId) {
                return $i;
            }
        }

        return null;
    }

    /** Duration, stops strictly between, and distance for boarding -> alighting. */
    private function journeySummary(array $rows, array $stopping): ?array
    {
        $board = null;
        $alight = null;
        foreach ($rows as $i => $row) {
            if ($row['is_boarding']) {
                $board = $i;
            }
            if ($row['is_alighting']) {
                $alight = $i;
            }
        }
        if ($board === null || $alight === null) {
            return null;
        }

        $between = 0;
        for ($i = $board + 1; $i < $alight; $i++) {
            if (in_array($rows[$i]['type'], $stopping, true)) {
                $between++;
            }
        }

        $duration = null;
        if ($rows[$board]['eta'] !== null && $rows[$alight]['eta'] !== null) {
            $duration = (int) Carbon::parse($rows[$board]['eta'])->diffInMinutes(Carbon::parse($rows[$alight]['eta']), false);
        }

        return [
            'duration_minutes' => $duration,
            'stops_between' => $between,
            'distance_km' => round($rows[$alight]['distance_km'] - $rows[$board]['distance_km'], 2),
        ];
    }

    /** Major (`stop`-type) intermediate stops of the leg, capped in config. */
    private function viaNames($routeStops, string $service = ServiceType::REGULAR): array
    {
        return $routeStops->slice(1, max(0, $routeStops->count() - 2))
            ->filter(fn ($s) => ServiceType::effectiveStopType($service, $s) === 'stop')
            ->take((int) config('timeline.via_max'))
            ->map(fn ($s) => $s->stop->stop_name)
            ->values()
            ->all();
    }

    /** @return array{amount: float, distance_km: float, seat_type: string}|array{error: string}|null */
    private function fareFor($trip, $routeStops, array $rows, ?int $boardingId, ?int $alightingId, ?array $custom, string $seatType): ?array
    {
        try {
            if ($custom !== null) {
                $boarding = $routeStops->firstWhere('stop_id', $boardingId);
                $quote = $this->fareRuleService->getQuoteForTripFromCoordinates(
                    (int) $trip->fleetRoute->fleet_id,
                    (int) $trip->fleetRoute->route_id,
                    [
                        'origin_lat' => (float) $boarding->stop->latitude,
                        'origin_lng' => (float) $boarding->stop->longitude,
                        'destination_lat' => $custom['snapped_latitude'],
                        'destination_lng' => $custom['snapped_longitude'],
                        'seat_type' => $seatType,
                        'service_type' => $trip->service_type,
                        'on' => $trip->trip_date?->toDateString(),
                    ]
                );

                return ['amount' => (float) $quote['amount'], 'distance_km' => (float) $quote['distance_km'], 'seat_type' => $seatType];
            }

            $amount = $this->fareRuleService->getQuote([
                'route_id' => (int) $trip->fleetRoute->route_id,
                'fleet_id' => (int) $trip->fleetRoute->fleet_id,
                'service_type' => $trip->service_type,
                'origin_stop_id' => $boardingId,
                'destination_stop_id' => $alightingId,
                'seat_type' => $seatType,
                'leg_direction' => $trip->leg_direction,
                'on' => $trip->trip_date?->toDateString(),
            ]);

            $km = 0.0;
            foreach ($rows as $row) {
                if ($row['is_boarding']) {
                    $km -= $row['distance_km'];
                }
                if ($row['is_alighting']) {
                    $km += $row['distance_km'];
                }
            }

            return ['amount' => (float) $amount, 'distance_km' => round($km, 2), 'seat_type' => $seatType];
        } catch (\App\Exceptions\FareUnavailableException) {
            // No rule for this bus's class and service type (an express trip needs an express rule): never borrowed.
            return ['error' => 'Fare unavailable', 'unavailable' => true];
        } catch (\Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }
}
