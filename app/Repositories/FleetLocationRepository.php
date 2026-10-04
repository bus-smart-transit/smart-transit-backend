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
                    'accuracy_m'  => $payload['accuracy']   ?? null,
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
                (fleet_id, trip_id, location, heading, speed_kmh, accuracy_m, recorded_at, updated_at)
            VALUES
                (?, ?, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography, ?, ?, ?, NOW(), NOW())
            ON CONFLICT (fleet_id) DO UPDATE SET
                trip_id     = EXCLUDED.trip_id,
                location    = EXCLUDED.location,
                heading     = EXCLUDED.heading,
                speed_kmh   = EXCLUDED.speed_kmh,
                accuracy_m  = EXCLUDED.accuracy_m,
                recorded_at = EXCLUDED.recorded_at,
                updated_at  = NOW()
        ", [
            $payload['fleet_id'],
            $payload['trip_id'],
            $payload['longitude'], // lng first
            $payload['latitude'],  // lat second
            $payload['heading']    ?? null,
            $payload['speed_kmh']  ?? null,
            $payload['accuracy']   ?? null,
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
     * Batch 18 (Location Match Rule): current lat/lng for a fleet's last
     * recorded position, or null if it has never reported one. Parses the
     * dual-path storage (plain "lat,lng" string on SQLite tests; real
     * PostGIS GEOGRAPHY(POINT) on Postgres) the same way getTripHistory()
     * below already does for fleet_location_history.
     */
    public function getCurrentLatLng(int $fleetId): ?array
    {
        $driver = DB::getDriverName();

        if ($driver === 'sqlite') {
            $row = DB::selectOne("
                SELECT
                    CAST(SUBSTR(location, 1, INSTR(location,',')-1) AS REAL) AS latitude,
                    CAST(SUBSTR(location, INSTR(location,',')+1) AS REAL)    AS longitude
                FROM fleet_locations
                WHERE fleet_id = ?
            ", [$fleetId]);
        } else {
            $row = DB::selectOne("
                SELECT
                    ST_Y(location::geometry) AS latitude,
                    ST_X(location::geometry) AS longitude
                FROM fleet_locations
                WHERE fleet_id = ?
            ", [$fleetId]);
        }

        if (! $row || $row->latitude === null || $row->longitude === null) {
            return null;
        }

        return ['latitude' => (float) $row->latitude, 'longitude' => (float) $row->longitude];
    }

    /**
     * Batch 26 (ticket tracking): the fleet's last position, only if it was reported for this
     * trip. Returns position, heading and how many seconds old it is, or null. The age is worked
     * out where the timestamp was written (the database clock on PostgreSQL, the application
     * clock on the SQLite test database) so a timezone difference cannot make it look stale.
     *
     * @return array{latitude: float, longitude: float, heading: float|null, age_seconds: int}|null
     */
    public function getCurrentForTrip(int $fleetId, int $tripId): ?array
    {
        if (DB::getDriverName() === 'sqlite') {
            $row = DB::selectOne("
                SELECT
                    CAST(SUBSTR(location, 1, INSTR(location,',')-1) AS REAL) AS latitude,
                    CAST(SUBSTR(location, INSTR(location,',')+1) AS REAL)    AS longitude,
                    heading,
                    accuracy_m,
                    recorded_at
                FROM fleet_locations
                WHERE fleet_id = ? AND trip_id = ?
            ", [$fleetId, $tripId]);
            $age = $row?->recorded_at
                ? max(0, now(config('app.timezone'))->diffInSeconds(\Illuminate\Support\Carbon::parse($row->recorded_at, config('app.timezone')), true))
                : null;
        } else {
            $row = DB::selectOne("
                SELECT
                    ST_Y(location::geometry) AS latitude,
                    ST_X(location::geometry) AS longitude,
                    heading,
                    accuracy_m,
                    GREATEST(0, EXTRACT(EPOCH FROM (NOW() - recorded_at))) AS age_seconds
                FROM fleet_locations
                WHERE fleet_id = ? AND trip_id = ?
            ", [$fleetId, $tripId]);
            $age = $row?->age_seconds;
        }

        if (! $row || $row->latitude === null || $row->longitude === null || $age === null) {
            return null;
        }

        return [
            'latitude' => (float) $row->latitude,
            'longitude' => (float) $row->longitude,
            'heading' => $row->heading !== null ? (float) $row->heading : null,
            'accuracy_m' => isset($row->accuracy_m) && $row->accuracy_m !== null ? (float) $row->accuracy_m : null,
            'age_seconds' => (int) round((float) $age),
        ];
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
                fl.accuracy_m,
                fl.recorded_at,
                f.plate_number,
                f.bus_company_name,
                f.body_no,
                f.fleet_type,
                t.status AS trip_status,
                t.leg_direction,
                t.last_acknowledged_stop_id,
                t.last_acknowledged_at,
                fr.route_id,
                r.route_name,
                r.origin,
                r.destination
            FROM fleet_locations fl
            JOIN fleets f ON f.fleet_id = fl.fleet_id
            LEFT JOIN trips t ON t.trip_id = fl.trip_id
            LEFT JOIN fleets_routes fr ON fr.fleet_route_id = t.fleet_route_id
            LEFT JOIN routes r ON r.route_id = fr.route_id
            WHERE t.status IN ('boarding', 'departed', 'in-progress')
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
                f.plate_number,
                f.bus_company_name,
                f.body_no
            FROM fleet_locations fl
            JOIN fleets f ON f.fleet_id = fl.fleet_id
            LEFT JOIN trips t ON t.trip_id = fl.trip_id
            WHERE ST_DWithin(
                fl.location,
                ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography,
                ?
            )
            AND t.status IN ('boarding', 'departed', 'in-progress')
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
