<?php

namespace App\Services;

use App\Repositories\FleetLocationRepository;
use App\Repositories\GpsSimulationRepository;
use App\Repositories\TicketRepository;
use App\Repositories\TripRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

class FleetLocationService
{
    public function __construct(
        private FleetLocationRepository $fleetLocationRepository,
        private TripRepository $tripRepository,
        private RouteSnapService $routeSnapService,
        private TripTimelineService $timelineService,
        private TicketRepository $ticketRepository,
        private GpsSimulationRepository $simulations,
        private WaitingBusService $waitingBuses,
    ) {}

    /**
     * $payload     = ['latitude','longitude','trip_id' nullable,'heading' nullable,'speed_kmh' nullable]
     * $companyUserId derives fleet_id server-side from driver's active trip
     *               (driver does NOT send fleet_id — we look it up to avoid trust issues)
     */
    public function updateLocation(int $companyUserId, array $payload): bool
    {
        $trip = $this->tripRepository->findCurrentByDriver($companyUserId);

        if (! $trip) {
            throw ValidationException::withMessages([
                'trip' => ['No active trip found for this driver.'],
            ]);
        }

        // Poor-accuracy fixes never overwrite the last accepted position.
        $accuracy = isset($payload['accuracy']) ? (float) $payload['accuracy'] : null;
        if ($accuracy !== null && $accuracy > (float) config('route_geometry.gps.max_accuracy_m')) {
            return false;
        }

        // Batch 49 item 4: while the Super Admin's simulator has control of this trip, the phone's positions are set aside
        // (accepted, not stored), so the two never fight over the bus's place on the map.
        if ($this->simulations->activeForTrip((int) $trip->trip_id)) {
            return true;
        }

        $this->fleetLocationRepository->upsertLocation(array_merge($payload, [
            'fleet_id' => $trip->fleetRoute->fleet_id,
            'trip_id' => $payload['trip_id'] ?? $trip->trip_id,
        ]));

        return true;
    }

    /**
     * Active positions with a server-computed projection onto the trip's
     * canonical route (the client never computes authoritative snapping).
     */
    public function getAllActiveLocations(): object
    {
        // 2026-11-04 (Batch 45 Todo 2): every open passenger map asks for the same list every 12 seconds, so it is
        // kept for a few seconds and shared. Callers must copy a row before changing it (the cached rows are shared).
        $seconds = (int) config('tracking.active_locations_cache_seconds');
        if ($seconds <= 0) {
            return $this->loadActiveLocations();
        }

        return Cache::remember('fleet:active-locations', $seconds, fn () => $this->loadActiveLocations());
    }

    private function loadActiveLocations(): object
    {
        // 2026-11-08 (user): until the Driver presses Depart a bus shows still at its first stop (WaitingBusService),
        // not at its phone's position; from Depart it is the live position. A bus running another trip stays live.
        $live = $this->fleetLocationRepository->getAllActiveLocations()
            ->reject(fn ($row) => ($row->trip_status ?? null) === 'boarding')
            ->map(function ($row) {
                $row->waiting = false;

                return $row;
            });
        $liveFleets = $live->map(fn ($row) => (int) $row->fleet_id)->all();
        $waiting = $this->waitingBuses->rows()
            ->reject(fn ($row) => in_array((int) $row->fleet_id, $liveFleets, true))
            ->map(function ($row) {
                $row->route_position = null;
                $row->trip_progress = $this->tripProgress((int) $row->trip_id, null);

                return $row;
            });

        return $live->map(function ($row) {
            $row->route_position = null;
            if ($row->route_id && $row->latitude !== null && $row->longitude !== null) {
                $row->route_position = $this->routeSnapService->snap(
                    (int) $row->route_id,
                    (float) $row->latitude,
                    (float) $row->longitude,
                    $row->leg_direction ?? 'outbound'
                );
            }
            $row->trip_progress = ! empty($row->trip_id) ? $this->tripProgress((int) $row->trip_id, $row->age_seconds ?? null) : null;

            return $row;
        })->concat($waiting)->values();
    }

    /**
     * Batch 45 Feature C (decided 2026-11-04, Item 11): what the "buses near you" panel shows for one running bus.
     * - next stop and its ETA from the trip timeline (TripTimelineService, the one ETA owner);
     * - eta_basis 'live' only while the timeline has live progress AND the GPS is fresh; with a GPS position older than
     *   tracking.stale_seconds it falls back to the scheduled time ('scheduled', gps_stale true);
     * - the scheduled time and the delay are given apart;
     * - passengers = tickets on board now (status boarded), the count the Operator's occupancy uses; null when unknown.
     * Null values mean "Not provided"; nothing is guessed.
     *
     * @return array<string, mixed>|null
     */
    private function tripProgress(int $tripId, mixed $ageSeconds): ?array
    {
        try {
            $timeline = $this->timelineService->build($tripId);
        } catch (ValidationException) {
            return null;
        }

        $stops = collect($timeline['stops'] ?? [])->where('custom', false)->values();
        $arrived = (bool) ($timeline['refresh']['arrived'] ?? false);
        $next = $arrived ? null : ($stops->first(fn (array $stop) => $stop['progress'] === 'current')
            ?? $stops->first(fn (array $stop) => $stop['progress'] === 'upcoming'));
        $gpsStale = $ageSeconds !== null && (float) $ageSeconds > (int) config('tracking.stale_seconds');
        $live = ($next['eta_source'] ?? null) === 'live' && ! $gpsStale;
        $final = $stops->last();
        $finalLive = ($final['eta_source'] ?? null) === 'live' && ! $gpsStale;
        $departedAt = $this->tripRepository->findById($tripId)?->departed_at;

        return [
            // Batch 50 (user, 2026-11-08): what the Active buses card shows. Departed at, the destination and when the bus
            // gets there (live from its position when known, else the timetable), the stops it has passed (gray on the
            // map), and whether it has arrived at its final stop.
            'departed_at' => $departedAt ? Carbon::parse($departedAt)->setTimezone(config('app.timezone'))->format('H:i') : null,
            'destination' => $final['name'] ?? null,
            'destination_eta_time' => $arrived ? null : ($finalLive ? ($final['eta_time'] ?? null) : ($final['scheduled_time'] ?? null)),
            'destination_eta_basis' => $arrived || $final === null ? null : ($finalLive ? 'live' : 'scheduled'),
            'arrived' => $arrived,
            'passed_stop_ids' => $stops->where('progress', 'passed')->pluck('stop_id')->map(fn ($id) => (int) $id)->values()->all(),
            'next_stop_eta' => $next ? ($live ? ($next['eta'] ?? null) : null) : null,

            'next_stop' => $next['name'] ?? null,
            'eta_time' => $next ? ($live ? $next['eta_time'] : $next['scheduled_time']) : null,
            'eta_basis' => $next === null || ($live ? $next['eta_time'] : $next['scheduled_time']) === null ? null : ($live ? 'live' : 'scheduled'),
            'scheduled_time' => $next['scheduled_time'] ?? null,
            'delay_minutes' => $live ? ($timeline['refresh']['delay_minutes'] ?? null) : null,
            'gps_stale' => $gpsStale,
            'passengers' => $this->ticketRepository->countBoardedByType($tripId, 'seated')
                + $this->ticketRepository->countBoardedByType($tripId, 'standing'),
        ];
    }

    // $payload = ['latitude','longitude']
    public function findNearestFleet(array $payload): ?object
    {
        return $this->fleetLocationRepository->findNearest($payload);
    }

    public function getTripHistory(int $tripId, int $limit = 500): object
    {
        return $this->fleetLocationRepository->getTripHistory($tripId, $limit);
    }
}
