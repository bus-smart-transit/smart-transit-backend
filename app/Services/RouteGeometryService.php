<?php
namespace App\Services;

use App\Models\Route;
use App\Repositories\RouteRepository;
use Illuminate\Support\Collection;

class RouteGeometryService
{
    // How far off a route's actual path (in km) a point can be and still
    // count as "on" that route.
    private const MAX_OFF_ROUTE_KM = 0.5;

    public function __construct(private RouteRepository $routeRepository)
    {
    }

    /**
     * Finds the best-fitting route for a pickup + drop-off coordinate pair,
     * used by the passenger "search" flow (route unknown ahead of time).
     */
    public function matchRouteForTrip(float $originLat, float $originLng, float $destLat, float $destLng): array
    {
        $routes = $this->routeRepository->allWithOrderedStops();
        $candidates = [];

        foreach ($routes as $route) {
            $match = $this->interpolateForRoute($route, $originLat, $originLng, $destLat, $destLng);
            if ($match) {
                $candidates[] = $match;
            }
        }

        if (empty($candidates)) {
            throw new \RuntimeException('Could not match your pickup and drop-off to any known route.');
        }

        usort($candidates, fn($a, $b) => $a['score'] <=> $b['score']);

        return $candidates[0];
    }

    /**
     * Same interpolation, but against a SPECIFIC, already-known route — used
     * at ticket purchase time, when the trip (and therefore its route) is
     * already fixed. Never trusts a client-supplied amount from an earlier
     * browse-time quote — always re-derives from scratch.
     */
    public function interpolateForKnownRoute(int $routeId, float $originLat, float $originLng, float $destLat, float $destLng): array
    {
        $route = $this->routeRepository->findById($routeId);

        if (!$route) {
            throw new \RuntimeException('Route not found.');
        }

        $match = $this->interpolateForRoute($route, $originLat, $originLng, $destLat, $destLng);

        if (!$match) {
            throw new \RuntimeException('Could not match your pickup and drop-off to this trip\'s route.');
        }

        return $match;
    }

    private function interpolateForRoute(Route $route, float $originLat, float $originLng, float $destLat, float $destLng): ?array
    {
        if ($route->routeStops->count() < 2) {
            return null;
        }

        $origin = $this->interpolateAlongRoute($route->routeStops, $originLat, $originLng);
        $destination = $this->interpolateAlongRoute($route->routeStops, $destLat, $destLng);

        if ($origin['excess_km'] > self::MAX_OFF_ROUTE_KM || $destination['excess_km'] > self::MAX_OFF_ROUTE_KM) {
            return null;
        }

        return [
            'route' => $route,
            'origin' => $origin,
            'destination' => $destination,
            'score' => $origin['excess_km'] + $destination['excess_km'],
        ];
    }

    /**
     * For each consecutive stop pair (A, B), treats distAP + distPB vs.
     * distAB as a proxy for "how close is the point to segment AB" — cheap
     * to compute with plain Haversine, no spatial database extension
     * required. Whichever segment has the smallest excess is where the
     * point most plausibly sits; its position within that segment is
     * estimated by distAP / distAB, then mapped onto that segment's REAL
     * road distance range (distance_from_origin_km), not the straight-line
     * range.
     */
    private function interpolateAlongRoute(Collection $orderedStops, float $lat, float $lng): array
    {
        $best = null;

        for ($i = 0; $i < $orderedStops->count() - 1; $i++) {
            $stopA = $orderedStops[$i]->stop;
            $stopB = $orderedStops[$i + 1]->stop;

            $distAB = $this->haversineKm($stopA->latitude, $stopA->longitude, $stopB->latitude, $stopB->longitude);
            $distAP = $this->haversineKm($stopA->latitude, $stopA->longitude, $lat, $lng);
            $distPB = $this->haversineKm($lat, $lng, $stopB->latitude, $stopB->longitude);

            $excess = ($distAP + $distPB) - $distAB;
            $fraction = $distAB > 0 ? min(1, max(0, $distAP / $distAB)) : 0;

            $interpolatedDistance = $orderedStops[$i]->distance_from_origin_km
                + $fraction * ($orderedStops[$i + 1]->distance_from_origin_km - $orderedStops[$i]->distance_from_origin_km);

            if ($best === null || $excess < $best['excess_km']) {
                $best = [
                    'distance_from_origin_km' => $interpolatedDistance,
                    'excess_km' => $excess,
                ];
            }
        }

        return $best;
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