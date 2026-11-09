<?php

namespace App\Repositories;

use App\Models\Stop;
use App\Support\Geo\GeoLine;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class StopRepository
{
    public function create(array $payload): Stop
    {
        return Stop::create($payload);
    }

    /**
     * Batch 52 item 1: the ids of the stops whose town is one of $towns (exact region spellings).
     *
     * @param  list<string>  $towns
     * @return list<int>
     */
    public function idsInTowns(array $towns): array
    {
        if ($towns === []) {
            return [];
        }

        return Stop::query()->whereIn('municipality', $towns)->pluck('stop_id')->map(fn ($id) => (int) $id)->all();
    }

    public function findById(int $stopId): ?Stop
    {
        return Stop::find($stopId);
    }

    /**
     * 2026-11-06 (Batch 47 part A): the stops of the same company (or with no company) within $meters of a point,
     * nearest first, each with distance_m. Distance worked out in PHP on a small box, so it works on SQLite too.
     */
    public function closeTo(float $lat, float $lng, int $meters, ?int $companyId, int $exceptStopId): Collection
    {
        $dLat = $meters / 111320;
        $dLng = $meters / (111320 * max(0.01, cos(deg2rad($lat))));

        return Stop::query()
            ->whereBetween('latitude', [$lat - $dLat, $lat + $dLat])
            ->whereBetween('longitude', [$lng - $dLng, $lng + $dLng])
            ->where('stop_id', '!=', $exceptStopId)
            ->when($companyId !== null, fn ($q) => $q->where('company_id', $companyId), fn ($q) => $q->whereNull('company_id'))
            ->get()
            ->map(function (Stop $stop) use ($lat, $lng) {
                $stop->setAttribute('distance_m', GeoLine::haversineM($lat, $lng, (float) $stop->latitude, (float) $stop->longitude));

                return $stop;
            })
            ->filter(fn (Stop $stop) => $stop->distance_m <= $meters)
            ->sortBy('distance_m')
            ->values();
    }

    /** Every stop, or (2026-11-06) only one company's when $companyId is given. */
    public function all(?int $companyId = null): Collection
    {
        return Stop::orderBy('stop_name')->when($companyId !== null, fn ($q) => $q->where('company_id', $companyId))->get();
    }

    public function update(int $stopId, array $payload): bool
    {
        return Stop::where('stop_id', $stopId)->update($payload) > 0;
    }

    public function delete(int $stopId): bool
    {
        return Stop::where('stop_id', $stopId)->delete() > 0;
    }

    /**
     * Returns a [stop_id => stop_name] map for the given IDs.
     */
    public function getNamesByIds(array $stopIds): Collection
    {
        return Stop::query()
            ->whereIn('stop_id', array_values(array_unique($stopIds)))
            ->pluck('stop_name', 'stop_id');
    }

    public function findNearestStop(
        float $lat,
        float $lng,
        ?int $routeId = null,
        float $maxDistanceKm = 0.5

    ): ?Stop {

        Log::info('GPS quote request', [
            'lat' => $lat,
            'lng' => $lng,
            'routeId' => $routeId,
            'maxDistanceKm' => $maxDistanceKm,
        ]);

        $distanceExpr = '(6371 * acos(
                LEAST(1, GREATEST(-1,
                    cos(radians(?)) * cos(radians(latitude)) *
                    cos(radians(longitude) - radians(?)) +
                    sin(radians(?)) * sin(radians(latitude))
                ))
            ))';

        $query = Stop::selectRaw("stops.*, {$distanceExpr} AS distance_km", [$lat, $lng, $lat]);

        if ($routeId !== null) {
            $query->whereHas('routeStops', function ($q) use ($routeId) {
                $q->where('route_id', $routeId);
            });
        }

        // Log::info('DB check inside findNearestStop', [
        //     'connection' => DB::connection()->getDatabaseName(),
        //     'stop_count' => Stop::count(),
        //     'route_stop_count' => \App\Models\RouteStop::count(),
        // ]);

        return $query
            ->whereRaw("{$distanceExpr} <= ?", [$lat, $lng, $lat, $maxDistanceKm])
            ->orderByRaw("{$distanceExpr} ASC", [$lat, $lng, $lat])
            ->first();
    }
}
