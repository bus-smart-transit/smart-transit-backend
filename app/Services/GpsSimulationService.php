<?php

namespace App\Services;

use App\Jobs\RunGpsSimulationTick;
use App\Models\GpsSimulation;
use App\Models\Trip;
use App\Models\User;
use App\Repositories\FleetLocationRepository;
use App\Repositories\GpsSimulationRepository;
use App\Repositories\RouteGeometryRepository;
use App\Repositories\RouteStopRepository;
use App\Support\BusLabel;
use App\Support\Geo\GeoLine;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Batch 49 item 4 (user, 2026-11-07): the Super Admin's "Take control of GPS location", to show tracking in the defense.
 *
 * Only a trip that has departed can be simulated, only while GPS_SIMULATOR_ENABLED (the master switch the user sets on
 * the VPS) is on. While a simulation has control, the Driver phone's positions for that trip are set aside
 * (FleetLocationService) and the simulator writes the position instead, marked `is_simulated`:
 *  - every screen that shows it says "Simulated";
 *  - nothing that decides uses it (FleetLocationRepository::getCurrentForTrip hides it): no later-stop booking, no
 *    Chauffeur GPS boarding, no refund last point;
 *  - it never goes to the location history the reports read.
 * It stops by itself after route_geometry.gps.simulator_max_minutes, at the end of the route, or when the trip ends.
 * Every start and control is audit-logged. Ticks run on the queue (RunGpsSimulationTick).
 */
class GpsSimulationService
{
    public function __construct(
        private GpsSimulationRepository $simulations,
        private FleetLocationRepository $locations,
        private RouteGeometryRepository $geometry,
        private RouteStopRepository $routeStops,
        private RouteSnapService $routeSnap,
    ) {}

    public static function enabled(): bool
    {
        return (bool) config('route_geometry.gps.simulator_enabled');
    }

    /** @return array<string, mixed> what the screen needs: the switch, the choices, the simulations and the trips */
    public function overview(): array
    {
        $active = $this->simulations->active();
        $controlled = $active->pluck('trip_id')->map(fn ($id) => (int) $id)->all();

        return [
            'enabled' => self::enabled(),
            'speeds' => array_values(config('route_geometry.gps.simulator_speeds')),
            'max_minutes' => (int) config('route_geometry.gps.simulator_max_minutes'),
            'simulations' => $active->map(fn (GpsSimulation $sim) => $this->present($sim))->values()->all(),
            'trips' => $this->simulations->departedTrips()->map(fn (Trip $trip) => [
                'trip_id' => (int) $trip->trip_id,
                'route_name' => $trip->fleetRoute?->route?->route_name,
                'bus_label' => BusLabel::for($trip->fleetRoute?->fleet),
                'trip_date' => $trip->trip_date?->toDateString(),
                'departure_time' => substr((string) $trip->departure_time, 0, 5),
                'status' => $trip->status,
                'has_route_line' => $trip->fleetRoute ? $this->geometry->get((int) $trip->fleetRoute->route_id) !== null : false,
                'simulated' => in_array((int) $trip->trip_id, $controlled, true),
            ])->values()->all(),
        ];
    }

    /** @throws ValidationException */
    public function start(User $admin, int $tripId, int $speed): GpsSimulation
    {
        $this->assertEnabled();
        $this->assertSpeed($speed);
        $trip = Trip::with('fleetRoute')->find($tripId);
        if (! $trip || ! TripProgressService::isRunning($trip->status)) {
            throw ValidationException::withMessages(['trip_id' => ['Only a trip that has departed can be simulated.']]);
        }
        $line = $this->line($trip);
        if ($line === null) {
            throw ValidationException::withMessages(['trip_id' => ['This route has no route line yet, so the bus cannot be moved along it.']]);
        }

        $simulation = DB::transaction(function () use ($admin, $trip, $speed, $line) {
            if ($this->simulations->activeForTrip((int) $trip->trip_id)) {
                throw ValidationException::withMessages(['trip_id' => ['This trip is already simulated. Stop it first.']]);
            }

            return $this->simulations->create([
                'trip_id' => $trip->trip_id,
                'fleet_id' => $trip->fleetRoute->fleet_id,
                'started_by_user_id' => $admin->user_id,
                'speed' => $speed,
                'status' => GpsSimulation::RUNNING,
                'along_m' => $this->startAlong($trip, $line),
                'last_tick_at' => now(),
                'expires_at' => now()->addMinutes((int) config('route_geometry.gps.simulator_max_minutes')),
            ]);
        });

        $this->writePoint($simulation, $line);
        AuditLogger::log('gps_simulation.start', 'Trip', (int) $trip->trip_id, ['simulation_id' => $simulation->id, 'speed' => $speed]);
        $this->schedule($simulation);

        return $simulation->fresh();
    }

    /**
     * pause | resume | stop | jump (to a stop of the trip) | speed.
     *
     * @throws ValidationException
     */
    public function control(int $simulationId, string $action, ?int $stopId = null, ?int $speed = null): GpsSimulation
    {
        $simulation = DB::transaction(function () use ($simulationId, $action, $stopId, $speed) {
            $simulation = $this->simulations->findForUpdate($simulationId);
            if (! $simulation || $simulation->status === GpsSimulation::STOPPED) {
                throw ValidationException::withMessages(['simulation' => ['This simulation has already stopped.']]);
            }
            match ($action) {
                'pause' => $simulation->status = GpsSimulation::PAUSED,
                'resume' => $simulation->fill(['status' => GpsSimulation::RUNNING, 'last_tick_at' => now()]),
                'stop' => $simulation->fill(['status' => GpsSimulation::STOPPED, 'stopped_at' => now(), 'stop_reason' => 'stopped']),
                'jump' => $simulation->along_m = $this->stopAlong($simulation->trip, (int) $stopId),
                'speed' => $simulation->speed = $this->assertSpeed((int) $speed),
                default => throw ValidationException::withMessages(['action' => ['Unknown action.']]),
            };
            $simulation->save();

            return $simulation;
        });

        if ($action === 'jump') {
            $line = $this->line($simulation->trip);
            if ($line !== null) {
                $this->writePoint($simulation, $line);
            }
        }
        AuditLogger::log('gps_simulation.'.$action, 'Trip', (int) $simulation->trip_id, array_filter(['simulation_id' => $simulation->id, 'stop_id' => $stopId, 'speed' => $speed]));
        if ($action === 'resume') {
            $this->schedule($simulation);
        }

        return $simulation->fresh();
    }

    /**
     * One step of a running simulation, run by the queue. Returns true when it should run again.
     */
    public function tick(int $simulationId, string $chain): bool
    {
        if (Cache::get(self::chainKey($simulationId)) !== $chain) {
            return false; // a newer start or resume took over
        }

        return DB::transaction(function () use ($simulationId) {
            $simulation = $this->simulations->findForUpdate($simulationId);
            if (! $simulation || $simulation->status !== GpsSimulation::RUNNING) {
                return false;
            }
            $trip = $simulation->trip;
            if (! $trip || ! TripProgressService::isRunning($trip->status)) {
                $this->finish($simulation, 'trip_ended');

                return false;
            }
            if (now()->gte($simulation->expires_at)) {
                $this->finish($simulation, 'time_limit');

                return false;
            }
            $line = $this->line($trip);
            if ($line === null) {
                $this->finish($simulation, 'no_route_line');

                return false;
            }

            $elapsed = $simulation->last_tick_at ? max(0.0, (float) $simulation->last_tick_at->diffInSeconds(now(), true)) : 0.0;
            $elapsed = min($elapsed, 4 * (int) config('route_geometry.gps.simulator_tick_seconds'));
            $metresPerSecond = (float) config('route_geometry.gps.simulator_base_kmh') / 3.6 * $simulation->speed;
            $length = GeoLine::lengthM($line);
            $simulation->along_m = min($length, $simulation->along_m + $metresPerSecond * $elapsed);
            $simulation->last_tick_at = now();
            $simulation->save();
            $this->writePoint($simulation, $line);

            if ($simulation->along_m >= $length) {
                $this->finish($simulation, 'route_end');

                return false;
            }

            return true;
        });
    }

    /** @return array<string, mixed> */
    public function present(GpsSimulation $simulation): array
    {
        $trip = $simulation->trip;
        $line = $trip ? $this->line($trip) : null;
        $point = $line ? $this->pointAt($line, $simulation->along_m) : null;

        return [
            'id' => (int) $simulation->id,
            'trip_id' => (int) $simulation->trip_id,
            'route_id' => $trip?->fleetRoute ? (int) $trip->fleetRoute->route_id : null,
            'leg_direction' => $trip?->leg_direction === 'reverse' ? 'reverse' : 'outbound',
            'route_name' => $trip?->fleetRoute?->route?->route_name,
            'bus_label' => BusLabel::for($trip?->fleetRoute?->fleet),
            'status' => $simulation->status,
            'speed' => (int) $simulation->speed,
            'progress_percent' => $line ? (int) round($simulation->along_m / max(1.0, GeoLine::lengthM($line)) * 100) : null,
            'position' => $point ? ['latitude' => $point['lat'], 'longitude' => $point['lng']] : null,
            'stops' => $trip ? $this->routeStops->getOrderedStopsForDirection((int) $trip->fleetRoute->route_id, $trip->leg_direction === 'reverse' ? 'reverse' : 'outbound')
                ->map(fn ($rs) => ['stop_id' => (int) $rs->stop_id, 'name' => $rs->stop?->stop_name])->values()->all() : [],
            'expires_at' => $simulation->expires_at?->toIso8601String(),
            'stop_reason' => $simulation->stop_reason,
        ];
    }

    private function finish(GpsSimulation $simulation, string $reason): void
    {
        $simulation->fill(['status' => GpsSimulation::STOPPED, 'stopped_at' => now(), 'stop_reason' => $reason])->save();
        AuditLogger::log('gps_simulation.auto_stop', 'Trip', (int) $simulation->trip_id, ['simulation_id' => $simulation->id, 'reason' => $reason]);
    }

    private function schedule(GpsSimulation $simulation): void
    {
        $chain = (string) Str::uuid();
        Cache::put(self::chainKey((int) $simulation->id), $chain, now()->addMinutes((int) config('route_geometry.gps.simulator_max_minutes') + 5));
        RunGpsSimulationTick::dispatch((int) $simulation->id, $chain)
            ->delay(now()->addSeconds((int) config('route_geometry.gps.simulator_tick_seconds')));
    }

    private static function chainKey(int $simulationId): string
    {
        return 'gps-simulation-chain:'.$simulationId;
    }

    /** @throws ValidationException */
    private function assertEnabled(): void
    {
        if (! self::enabled()) {
            throw ValidationException::withMessages(['simulator' => ['The GPS simulator is turned off. It is turned on in the server settings (GPS_SIMULATOR_ENABLED).']]);
        }
    }

    /** @throws ValidationException */
    private function assertSpeed(int $speed): int
    {
        if (! in_array($speed, config('route_geometry.gps.simulator_speeds'), true)) {
            throw ValidationException::withMessages(['speed' => ['Choose one of the offered speeds.']]);
        }

        return $speed;
    }

    /** @return list<array{0: float, 1: float}>|null the route line in the trip's direction */
    private function line(Trip $trip): ?array
    {
        $trip->loadMissing('fleetRoute');
        $geometry = $trip->fleetRoute ? $this->geometry->get((int) $trip->fleetRoute->route_id) : null;
        if (! $geometry || count($geometry['coordinates'] ?? []) < 2) {
            return null;
        }

        return $trip->leg_direction === 'reverse' ? GeoLine::reverse($geometry['coordinates']) : $geometry['coordinates'];
    }

    /** Where the bus is now: its last real position on the route, else the last stop the Driver tapped, else the start. */
    private function startAlong(Trip $trip, array $line): float
    {
        $direction = $trip->leg_direction === 'reverse' ? 'reverse' : 'outbound';
        $real = $this->locations->getCurrentForTrip((int) $trip->fleetRoute->fleet_id, (int) $trip->trip_id);
        if ($real) {
            $snap = $this->routeSnap->snap((int) $trip->fleetRoute->route_id, (float) $real['latitude'], (float) $real['longitude'], $direction);
            if ($snap && ($snap['on_route'] ?? false)) {
                return (float) $snap['distance_along_route_m'];
            }
        }
        if ($trip->last_acknowledged_stop_id) {
            try {
                return $this->stopAlong($trip, (int) $trip->last_acknowledged_stop_id);
            } catch (ValidationException) {
                // fall through to the start of the line
            }
        }

        return 0.0;
    }

    /** @throws ValidationException */
    private function stopAlong(?Trip $trip, int $stopId): float
    {
        $routeStop = $trip ? $this->routeStops->findByRouteAndStop((int) $trip->fleetRoute->route_id, $stopId) : null;
        $line = $trip ? $this->line($trip) : null;
        if (! $routeStop || $routeStop->distance_from_origin_m === null || $line === null) {
            throw ValidationException::withMessages(['stop_id' => ['That stop is not on this trip\'s route line.']]);
        }
        $outbound = (float) $routeStop->distance_from_origin_m;

        return $trip->leg_direction === 'reverse' ? max(0.0, GeoLine::lengthM($line) - $outbound) : $outbound;
    }

    private function writePoint(GpsSimulation $simulation, array $line): void
    {
        $point = $this->pointAt($line, $simulation->along_m);
        if ($point === null) {
            return;
        }
        $this->locations->upsertLocation([
            'fleet_id' => $simulation->fleet_id,
            'trip_id' => $simulation->trip_id,
            'latitude' => round($point['lat'], 7),
            'longitude' => round($point['lng'], 7),
            'heading' => $point['heading'],
            'speed_kmh' => $simulation->status === GpsSimulation::RUNNING ? round((float) config('route_geometry.gps.simulator_base_kmh') * $simulation->speed, 1) : 0.0,
            'accuracy' => 5.0,
            'is_simulated' => true,
        ]);
    }

    /** @return array{lat: float, lng: float, heading: float|null}|null */
    private function pointAt(array $line, float $along): ?array
    {
        $length = GeoLine::lengthM($line);
        $from = max(0.0, min($along, $length - 1.0));
        $piece = GeoLine::slice($line, $from, min($length, $from + 1.0));
        if (count($piece) < 2) {
            return null;
        }
        [$lng1, $lat1] = $piece[0];
        [$lng2, $lat2] = $piece[count($piece) - 1];
        $p1 = deg2rad($lat1);
        $p2 = deg2rad($lat2);
        $dl = deg2rad($lng2 - $lng1);
        $heading = round(fmod(rad2deg(atan2(sin($dl) * cos($p2), cos($p1) * sin($p2) - sin($p1) * cos($p2) * cos($dl))) + 360.0, 360.0), 1);

        return ['lat' => (float) $lat1, 'lng' => (float) $lng1, 'heading' => $heading];
    }
}
