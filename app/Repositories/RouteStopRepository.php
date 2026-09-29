<?php
namespace App\Repositories;
use App\Models\RouteStop;
use Illuminate\Support\Collection;

class RouteStopRepository
{
    public function create(array $payload): RouteStop
    {
        return RouteStop::create($payload);
    }

    public function getOrderedStops(int $routeId): Collection
    {
        return RouteStop::with('stop')
            ->where('route_id', $routeId)
            ->orderBy('stop_order')
            ->get();
    }

    /**
     * B1: stops as travelled on a given leg. 'outbound' is the stored order;
     * 'reverse' flips the sequence and mirrors cumulative values
     * (distance = total - d, scheduled offset = total_offset - o).
     * Returned models are in-memory copies and are never persisted.
     */
    public function getOrderedStopsForDirection(int $routeId, ?string $direction = 'outbound'): Collection
    {
        $stops = $this->getOrderedStops($routeId);

        if ($direction !== 'reverse') {
            return $stops;
        }

        $totalKm = (float) $stops->max('distance_from_origin_km');
        $hasOffsets = $stops->contains(fn ($s) => $s->getAttribute('scheduled_offset_min') !== null);
        $totalOffset = $hasOffsets ? (int) $stops->max('scheduled_offset_min') : null;

        return $stops->reverse()->values()->map(function ($routeStop, $index) use ($totalKm, $totalOffset) {
            $copy = $routeStop->replicate();
            $copy->id = $routeStop->id;
            $copy->setRelation('stop', $routeStop->stop);
            $copy->stop_order = $index + 1;
            $copy->distance_from_origin_km = round($totalKm - (float) $routeStop->distance_from_origin_km, 3);
            if ($totalOffset !== null && $routeStop->getAttribute('scheduled_offset_min') !== null) {
                $copy->scheduled_offset_min = $totalOffset - (int) $routeStop->scheduled_offset_min;
            }

            return $copy;
        });
    }

    // Exact lookup for a known stop on a known route — used to price
    // stop-based tickets on the fly, no interpolation needed since both
    // points are exact.
    public function findByRouteAndStop(int $routeId, int $stopId): ?RouteStop
    {
        return RouteStop::where('route_id', $routeId)
            ->where('stop_id', $stopId)
            ->first();
    }

    public function delete(int $routeStopId): bool
    {
        return RouteStop::where('id', $routeStopId)->delete() > 0;
    }

    /** @return array<int, int> */
    public function routeIdsForStop(int $stopId): array
    {
        return RouteStop::where('stop_id', $stopId)->distinct()->pluck('route_id')->map(fn ($id) => (int) $id)->all();
    }

    public function updateStopType(int $routeId, int $routeStopId, string $stopType): bool
    {
        $routeStop = RouteStop::where('route_id', $routeId)->where('id', $routeStopId)->first();
        if (! $routeStop) {
            return false;
        }
        $routeStop->stop_type = $stopType;
        $routeStop->save();

        return true;
    }
}