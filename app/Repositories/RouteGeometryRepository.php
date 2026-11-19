<?php

namespace App\Repositories;

use Illuminate\Support\Facades\DB;

/**
 * Canonical route geometry storage. PostGIS geography(LineString, 4326) on
 * Postgres, GeoJSON text on the sqlite test database. Always exchanged as a
 * GeoJSON LineString with coordinates in [lng, lat] order.
 */
class RouteGeometryRepository
{
    public function get(int $routeId): ?array
    {
        if (DB::getDriverName() === 'pgsql') {
            $row = DB::selectOne(
                'SELECT ST_AsGeoJSON(route_geometry, 6) AS geojson FROM routes WHERE route_id = ?',
                [$routeId]
            );
            $json = $row->geojson ?? null;
        } else {
            $json = DB::table('routes')->where('route_id', $routeId)->value('route_geometry');
        }

        if (! $json) {
            return null;
        }

        $decoded = json_decode((string) $json, true);
        if (! is_array($decoded) || ($decoded['type'] ?? null) !== 'LineString' || count($decoded['coordinates'] ?? []) < 2) {
            return null;
        }

        return $decoded;
    }

    public function save(int $routeId, array $geometry): void
    {
        $json = json_encode(['type' => 'LineString', 'coordinates' => $geometry['coordinates']]);

        if (DB::getDriverName() === 'pgsql') {
            DB::update(
                'UPDATE routes SET route_geometry = ST_SetSRID(ST_GeomFromGeoJSON(?), 4326)::geography,
                    geometry_stale = false, geometry_generated_at = NOW(), geometry_error = NULL,
                    updated_at = NOW()
                 WHERE route_id = ?',
                [$json, $routeId]
            );

            return;
        }

        DB::table('routes')->where('route_id', $routeId)->update([
            'route_geometry' => $json,
            'geometry_stale' => false,
            'geometry_generated_at' => now(),
            'geometry_error' => null,
        ]);
    }

    /** Keeps the previous geometry; only flags it for admin attention. */
    public function markStale(int $routeId, ?string $error = null): void
    {
        DB::table('routes')->where('route_id', $routeId)->update([
            'geometry_stale' => true,
            'geometry_error' => $error !== null ? mb_substr($error, 0, 500) : null,
        ]);
    }

    /** @return array{geometry_stale: bool, geometry_generated_at: ?string, geometry_error: ?string}|null */
    public function status(int $routeId): ?array
    {
        $row = DB::table('routes')->where('route_id', $routeId)
            ->select('geometry_stale', 'geometry_generated_at', 'geometry_error')->first();

        return $row ? [
            'geometry_stale' => (bool) $row->geometry_stale,
            'geometry_generated_at' => $row->geometry_generated_at,
            'geometry_error' => $row->geometry_error,
        ] : null;
    }
}
