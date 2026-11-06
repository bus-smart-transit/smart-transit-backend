<?php

namespace App\Services;

use App\Repositories\RouteRepository;
use App\Repositories\RouteStopRepository;
use App\Repositories\StopRepository;
use App\Repositories\TripRepository;
use App\Support\StopSpacing;
use Illuminate\Validation\ValidationException;

/**
 * Stops. 2026-11-06 (Batch 47 part A): a stop's place is where a company admin pins it on the map; the system no
 * longer guesses coordinates from the name. The pin must be inside the region and near a road
 * (StopCoordinateValidator). A move is refused while a trip on one of its routes is running; otherwise future trips
 * use the new place (the route line, distances and stop times are redrawn in the queue) and sold tickets keep their
 * price. A stop of the same company very close by gives a warning, not a refusal.
 */
class StopService
{
    public function __construct(
        private StopRepository $stopRepository,
        private StopCoordinateValidator $coordinateValidator,
        private RouteStopRepository $routeStopRepository,
        private RouteGeometryGenerator $geometryGenerator,
        private RegionService $regionService,
        private TripRepository $trips,
        private RouteRepository $routeRepository,
    ) {}

    /** @param array{stop_name: string, latitude: float, longitude: float, stop_code?: ?string, municipality?: ?string, location_label?: ?string, company_id?: ?int} $payload */
    public function createStop(array $payload): object
    {
        $lat = (float) $payload['latitude'];
        $lng = (float) $payload['longitude'];
        $this->coordinateValidator->assertValid($lat, $lng);

        $stop = $this->stopRepository->create(array_filter([
            'stop_name' => $payload['stop_name'],
            'stop_code' => $payload['stop_code'] ?? null, // null = the model derives one from the name
            'latitude' => $lat,
            'longitude' => $lng,
            'municipality' => $this->regionService->canonicalMunicipality($payload['municipality'] ?? null),
            'company_id' => $payload['company_id'] ?? null, // owner (null: the platform Admin's)
            'location_label' => $this->label($payload['location_label'] ?? null),
            'location_source' => 'pinned',
            'pinned_at' => now(),
        ], fn ($value, $key) => ! in_array($key, ['stop_code', 'company_id', 'location_label'], true) || $value !== null, ARRAY_FILTER_USE_BOTH));

        $stop->setAttribute('warnings', $this->nearbyWarnings($stop->stop_id, $lat, $lng, $stop->company_id));

        return $stop;
    }

    public function listStops(?int $companyId = null): object
    {
        return $this->stopRepository->all($companyId);
    }

    public function findStop(int $stopId): ?object
    {
        return $this->stopRepository->findById($stopId);
    }

    /** 2026-11-06: is the stop on a route of another company (so it may not be moved or deleted by this one)? */
    public function usedByAnotherCompany(int $stopId, int $companyId): bool
    {
        return $this->routeStopRepository->routeCompaniesForStop($stopId)
            ->contains(fn ($routeCompanyId) => (int) $routeCompanyId !== $companyId);
    }

    /** $payload: stop_name, stop_code, municipality, location_label, latitude + longitude (a new pin), all optional. */
    public function updateStop(int $stopId, array $payload): ?object
    {
        $existing = $this->stopRepository->findById($stopId);
        if (! $existing) {
            return null;
        }
        $update = array_intersect_key($payload, array_flip(['stop_name', 'stop_code', 'municipality', 'location_label']));

        $moved = false;
        if (array_key_exists('latitude', $payload) && array_key_exists('longitude', $payload) && $payload['latitude'] !== null && $payload['longitude'] !== null) {
            $lat = (float) $payload['latitude'];
            $lng = (float) $payload['longitude'];
            $moved = abs($lat - (float) $existing->latitude) > 0.0000001 || abs($lng - (float) $existing->longitude) > 0.0000001;
            if ($moved) {
                $routeIds = $this->routeStopRepository->routeIdsForStop($stopId);
                if ($routeIds !== [] && $this->trips->anyRunningOnRoutes($routeIds)) {
                    throw ValidationException::withMessages(['latitude' => ['A trip on a route with this stop is running now. Move the stop after the trip ends.']]);
                }
                $this->coordinateValidator->assertValid($lat, $lng);
            }
            // Saving a pin (moved or confirmed in place) marks the stop as pinned on the map.
            $update += ['latitude' => $lat, 'longitude' => $lng, 'location_source' => 'pinned', 'pinned_at' => now()];
        }

        // A blank code means "leave it as it is"; a stop always keeps one.
        if (array_key_exists('stop_code', $update) && blank($update['stop_code'])) {
            unset($update['stop_code']);
        }
        if (array_key_exists('municipality', $update)) {
            $update['municipality'] = $this->regionService->canonicalMunicipality($update['municipality']);
        }
        if (array_key_exists('location_label', $update)) {
            $update['location_label'] = $this->label($update['location_label']);
        }

        if ($update !== []) {
            $this->stopRepository->update($stopId, $update);
        }

        // Moving a stop changes every route that passes through it: redraw line, distances and stop times.
        if ($moved) {
            foreach ($this->routeStopRepository->routeIdsForStop($stopId) as $routeId) {
                $this->geometryGenerator->queueRegeneration($routeId);
            }
        }

        $stop = $this->stopRepository->findById($stopId);
        if ($stop && $moved) {
            $stop->setAttribute('warnings', array_merge(
                $this->nearbyWarnings($stopId, (float) $stop->latitude, (float) $stop->longitude, $stop->company_id),
                $this->detourWarnings($stopId, (float) $stop->latitude, (float) $stop->longitude),
            ));
        }

        return $stop;
    }

    public function deleteStop(int $stopId): bool
    {
        $routeIds = $this->routeStopRepository->routeIdsForStop($stopId);
        $deleted = $this->stopRepository->delete($stopId);

        if ($deleted) {
            foreach ($routeIds as $routeId) {
                $this->routeStopRepository->compactStopOrder($routeId);
                $this->geometryGenerator->queueRegeneration($routeId);
            }
        }

        return $deleted;
    }

    /** "Downtown / Poblacion": trimmed, spaces collapsed; empty means none. */
    private function label(?string $label): ?string
    {
        $clean = trim((string) preg_replace('/\s+/', ' ', (string) $label));

        return $clean === '' ? null : $clean;
    }

    /** For each route the stop is on: a warning when its new place is well off the way between its neighbours. */
    private function detourWarnings(int $stopId, float $lat, float $lng): array
    {
        $warnings = [];
        foreach ($this->routeStopRepository->routeIdsForStop($stopId) as $routeId) {
            $ordered = $this->routeStopRepository->getOrderedStops((int) $routeId);
            $own = $ordered->first(fn ($rs) => (int) $rs->stop_id === $stopId);
            if (! $own) {
                continue;
            }
            $routeName = (string) $this->routeRepository->findById((int) $routeId)?->route_name;
            array_push($warnings, ...StopSpacing::detourWarnings($ordered, $lat, $lng, (int) $own->stop_order, $routeName, $stopId));
        }

        return $warnings;
    }

    /** A plain warning for each stop of the same company closer than config('stops.duplicate_warning_m'). */
    private function nearbyWarnings(int $stopId, float $lat, float $lng, mixed $companyId): array
    {
        $meters = (int) config('stops.duplicate_warning_m', 30);

        return $this->stopRepository->closeTo($lat, $lng, $meters, $companyId !== null ? (int) $companyId : null, $stopId)
            ->map(fn ($other) => "\"{$other->stop_name}\" is only ".max(1, (int) round($other->distance_m)).' m away. Is this the same stop?')
            ->values()
            ->all();
    }
}
