<?php
namespace App\Repositories;
use App\Models\Stop;
use Illuminate\Support\Collection;

class StopRepository
{
    public function create(array $payload): Stop
    {
        return Stop::create($payload);
    }

    public function findById(int $stopId): ?Stop
    {
        return Stop::find($stopId);
    }

    public function all(): Collection
    {
        return Stop::orderBy('stop_name')->get();
    }

    public function update(int $stopId, array $payload): bool
    {
        return Stop::where('stop_id', $stopId)->update($payload) > 0;
    }

    public function delete(int $stopId): bool
    {
        return Stop::where('stop_id', $stopId)->delete() > 0;
    }

    public function findNearestStop(
        float $lat,
        float $lng,
        ?int $routeId = null,
        float $maxDistanceKm = 0.5
    ): ?Stop {
        $query = Stop::selectRaw('
                stops.*,
                (6371 * acos(
                    LEAST(1, GREATEST(-1,
                        cos(radians(?)) * cos(radians(latitude)) *
                        cos(radians(longitude) - radians(?)) +
                        sin(radians(?)) * sin(radians(latitude))
                    ))
                )) AS distance_km
            ', [$lat, $lng, $lat]);

        if ($routeId !== null) {
            $query->whereHas('routeStops', function ($q) use ($routeId) {
                $q->where('route_id', $routeId);
            });
        }

        return $query
            ->having('distance_km', '<=', $maxDistanceKm)
            ->orderBy('distance_km')
            ->first();
    }
}
