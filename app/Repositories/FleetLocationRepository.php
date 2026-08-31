<?php
namespace App\Repositories;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;

class FleetLocationRepository
{
    // ⚠ ST_MakePoint(lng, lat) — longitude FIRST, then latitude. Always.
    public function upsertLocation(array $payload): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'sqlite') {
            // SQLite (test) path: store "lat,lng" string
            $locationStr = $payload['latitude'] . ',' . $payload['longitude'];
            DB::table('fleet_locations')->updateOrInsert(
                ['fleet_id' => $payload['fleet_id']],
                [
                    'trip_id'     => $payload['trip_id']    ?? null,
                    'location'    => $locationStr,
                    'heading'     => $payload['heading']    ?? null,
                    'speed_kmh'   => $payload['speed_kmh']  ?? null,
                    'recorded_at' => now(),
                    'updated_at'  => now(),
                ]
            );

            DB::table('fleet_location_history')->insert([
                'fleet_id'    => $payload['fleet_id'],
                'trip_id'     => $payload['trip_id']    ?? null,
                'location'    => $locationStr,
                'heading'     => $payload['heading']    ?? null,
                'speed_kmh'   => $payload['speed_kmh']  ?? null,
                'recorded_at' => now(),
            ]);

            return;
        }

        // PostgreSQL + PostGIS path
        DB::statement("
            INSERT INTO fleet_locations
                (fleet_id, trip_id, location, heading, speed_kmh, recorded_at, updated_at)
            VALUES
                (?, ?, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography, ?, ?, NOW(), NOW())
            ON CONFLICT (fleet_id) DO UPDATE SET
                trip_id     = EXCLUDED.trip_id,
                location    = EXCLUDED.location,
                heading     = EXCLUDED.heading,
                speed_kmh   = EXCLUDED.speed_kmh,
                recorded_at = EXCLUDED.recorded_at,
                updated_at  = NOW()
        ", [
            $payload['fleet_id'],
            $payload['trip_id'],
            $payload['longitude'], // lng first
            $payload['latitude'],  // lat second
            $payload['heading']    ?? null,
            $payload['speed_kmh']  ?? null,
        ]);

        // Append to history (time-series, no conflict handling)
        DB::statement("
            INSERT INTO fleet_location_history
                (fleet_id, trip_id, location, heading, speed_kmh, recorded_at)
            VALUES
                (?, ?, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography, ?, ?, NOW())
        ", [
            $payload['fleet_id'],
            $payload['trip_id'],
            $payload['longitude'],
            $payload['latitude'],
            $payload['heading']    ?? null,
            $payload['speed_kmh']  ?? null,
        ]);
    }

    /**
     * Return GPS breadcrumb trail for a trip (most recent first, capped at 500 rows).
     */
    public function getTripHistory(int $tripId, int $limit = 500): Collection
    {
        $driver = DB::getDriverName();

        if ($driver === 'sqlite') {
            return collect(DB::select("
                SELECT history_id, fleet_id, trip_id,
                       CAST(SUBSTR(location, 1, INSTR(location,',')-1) AS REAL) AS latitude,
                       CAST(SUBSTR(location, INSTR(location,',')+1) AS REAL)    AS longitude,
                       heading, speed_kmh, recorded_at
                FROM fleet_location_history
                WHERE trip_id = ?
                ORDER BY recorded_at DESC
                LIMIT ?
            ", [$tripId, $limit]));
        }

        return collect(DB::select("
            SELECT
                history_id,
                fleet_id,
                trip_id,
                ST_Y(location::geometry) AS latitude,
                ST_X(location::geometry) AS longitude,
                heading,
                speed_kmh,
                recorded_at
            FROM fleet_location_history
            WHERE trip_id = ?
            ORDER BY recorded_at DESC
            LIMIT ?
        ", [$tripId, $limit]));
    }

    public function getAllActiveLocations(): Collection
    {
        return collect(DB::select("
            SELECT
                fl.fleet_location_id,
                fl.fleet_id,
                fl.trip_id,
                ST_Y(fl.location::geometry) AS latitude,
                ST_X(fl.location::geometry) AS longitude,
                fl.heading,
                fl.speed_kmh,
                fl.recorded_at,
                f.plate_number,
                t.status AS trip_status
            FROM fleet_locations fl
            JOIN fleets f ON f.fleet_id = fl.fleet_id
            LEFT JOIN trips t ON t.trip_id = fl.trip_id
            WHERE t.status IN ('boarding', 'departed', 'in-progress')
               OR fl.updated_at > NOW() - INTERVAL '2 minutes'
        "));
    }

    public function findNearest(array $payload, float $radiusMeters = 25000): ?object
    {
        $results = DB::select("
            SELECT
                fl.fleet_id,
                fl.trip_id,
                ST_Y(fl.location::geometry) AS latitude,
                ST_X(fl.location::geometry) AS longitude,
                ST_Distance(
                    fl.location,
                    ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography
                ) AS distance_meters,
                f.plate_number
            FROM fleet_locations fl
            JOIN fleets f ON f.fleet_id = fl.fleet_id
            LEFT JOIN trips t ON t.trip_id = fl.trip_id
            WHERE ST_DWithin(
                fl.location,
                ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography,
                ?
            )
            AND (t.status IN ('boarding', 'departed', 'in-progress')
                OR fl.updated_at > NOW() - INTERVAL '2 minutes')
            ORDER BY distance_meters ASC
            LIMIT 1
        ", [
            $payload['longitude'], $payload['latitude'],
            $payload['longitude'], $payload['latitude'],
            $radiusMeters,
        ]);

        return $results[0] ?? null;
    }
}
