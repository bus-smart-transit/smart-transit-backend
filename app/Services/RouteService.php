<?php
namespace App\Services;
use App\Repositories\RouteRepository;
use App\Repositories\RouteStopRepository;
use App\Repositories\StopRepository;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class RouteService
{
    public function __construct(
        private RouteRepository $routeRepository,
        private RouteStopRepository $routeStopRepository,
        private StopRepository $stopRepository,
    ) {
    }

    // $payload = ['origin','destination','route_name']
    public function createRoute(array $payload): object
    {
        return $this->routeRepository->create($payload);
    }

    public function listRoutes(): object
    {
        return $this->routeRepository->all();
    }

    public function getRouteWithStops(int $routeId): ?object
    {
        return $this->routeRepository->findWithStops($routeId);
    }

    // $payload = ['stop_id','stop_order','distance_from_origin_km']
    // route_stop_table is folded into RouteService — no separate service/controller
    public function addStopToRoute(int $routeId, array $payload): object
    {
        $orderedStops = $this->routeStopRepository->getOrderedStops($routeId);

        $alreadyAssigned = $orderedStops->contains(function ($routeStop) use ($payload) {
            return (int) $routeStop->stop_id === (int) $payload['stop_id'];
        });

        if ($alreadyAssigned) {
            throw ValidationException::withMessages([
                'stop_id' => ['This stop is already assigned to the selected route.'],
            ]);
        }

        $orderTaken = $orderedStops->contains(function ($routeStop) use ($payload) {
            return (int) $routeStop->stop_order === (int) $payload['stop_order'];
        });

        if ($orderTaken) {
            throw ValidationException::withMessages([
                'stop_order' => ['This stop order is already used on the selected route.'],
            ]);
        }

        $distanceFromOrigin = isset($payload['distance_from_origin_km'])
            ? (float) $payload['distance_from_origin_km']
            : $this->estimateDistanceFromOrigin($orderedStops, (int) $payload['stop_id'], (int) $payload['stop_order']);

        return $this->routeStopRepository->create(array_merge(
            $payload,
            ['distance_from_origin_km' => $distanceFromOrigin],
            ['route_id' => $routeId]
        ));
    }

    public function removeStopFromRoute(int $routeStopId): bool
    {
        return $this->routeStopRepository->delete($routeStopId);
    }

    private function estimateDistanceFromOrigin(Collection $orderedStops, int $newStopId, int $newStopOrder): float
    {
        $newStop = $this->stopRepository->findById($newStopId);

        if (!$newStop || $newStop->latitude === null || $newStop->longitude === null || $orderedStops->isEmpty()) {
            return 0.0;
        }

        $orderedArray = $orderedStops->all();
        $prev = null;
        $next = null;

        foreach ($orderedArray as $routeStop) {
            if ((int) $routeStop->stop_order < $newStopOrder) {
                $prev = $routeStop;
                continue;
            }
            if ((int) $routeStop->stop_order > $newStopOrder) {
                $next = $routeStop;
                break;
            }
        }

        if ($prev && $next) {
            $prevStop = $prev->stop;
            $nextStop = $next->stop;
            if ($prevStop && $nextStop && $prevStop->latitude !== null && $prevStop->longitude !== null && $nextStop->latitude !== null && $nextStop->longitude !== null) {
                $between = max(0.001, $this->haversineKm((float) $prevStop->latitude, (float) $prevStop->longitude, (float) $nextStop->latitude, (float) $nextStop->longitude));
                $fromPrev = $this->haversineKm((float) $prevStop->latitude, (float) $prevStop->longitude, (float) $newStop->latitude, (float) $newStop->longitude);
                $fraction = min(1, max(0, $fromPrev / $between));
                return round((float) $prev->distance_from_origin_km + ($fraction * ((float) $next->distance_from_origin_km - (float) $prev->distance_from_origin_km)), 3);
            }

            return round(((float) $prev->distance_from_origin_km + (float) $next->distance_from_origin_km) / 2, 3);
        }

        if ($prev) {
            $prevStop = $prev->stop;
            if ($prevStop && $prevStop->latitude !== null && $prevStop->longitude !== null) {
                return round((float) $prev->distance_from_origin_km + $this->haversineKm((float) $prevStop->latitude, (float) $prevStop->longitude, (float) $newStop->latitude, (float) $newStop->longitude), 3);
            }
            return round((float) $prev->distance_from_origin_km, 3);
        }

        if ($next) {
            $nextStop = $next->stop;
            if ($nextStop && $nextStop->latitude !== null && $nextStop->longitude !== null) {
                return round(max(0, (float) $next->distance_from_origin_km - $this->haversineKm((float) $newStop->latitude, (float) $newStop->longitude, (float) $nextStop->latitude, (float) $nextStop->longitude)), 3);
            }
            return 0.0;
        }

        return 0.0;
    }

    private function haversineKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadiusKm = 6371;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadiusKm * $c;
    }
}
