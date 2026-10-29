<?php

namespace App\Console\Commands;

use App\Models\Trip;
use App\Repositories\FleetLocationRepository;
use App\Repositories\RouteGeometryRepository;
use App\Support\Geo\GeoLine;
use Illuminate\Console\Command;

/**
 * D8: dev-only GPS simulator. Replays positions along a trip's canonical
 * route into the normal location pipeline (same storage the driver app
 * feeds). Refuses to run unless GPS_SIMULATOR_ENABLED=true and the app is
 * not in production. It is an artisan command only: no UI, no route.
 */
class SimulateGps extends Command
{
    protected $signature = 'gps:simulate {trip : trip_id}
                            {--step=60 : Metres advanced per tick}
                            {--interval=2 : Seconds between ticks (0 = no delay)}
                            {--accuracy=8 : Accuracy radius (m) to report}
                            {--offset=0 : Metres to shift the vehicle off the line (test the off-route state)}
                            {--max-ticks=0 : Stop after N ticks (0 = end of route)}';

    protected $description = 'Dev only: replay GPS positions along a trip route (requires GPS_SIMULATOR_ENABLED=true).';

    public function handle(RouteGeometryRepository $geometry, FleetLocationRepository $locations): int
    {
        if (app()->environment('production') || ! config('route_geometry.gps.simulator_enabled')) {
            $this->error('GPS simulator is disabled. Set GPS_SIMULATOR_ENABLED=true in a non-production environment.');

            return self::FAILURE;
        }

        $trip = Trip::with('fleetRoute')->find((int) $this->argument('trip'));
        if (! $trip || ! $trip->fleetRoute) {
            $this->error('Trip or its fleet route not found.');

            return self::FAILURE;
        }

        $line = $geometry->get((int) $trip->fleetRoute->route_id);
        if (! $line) {
            $this->error('This route has no canonical geometry yet (run routes:generate-geometry).');

            return self::FAILURE;
        }

        $coords = $trip->leg_direction === 'reverse' ? GeoLine::reverse($line['coordinates']) : $line['coordinates'];
        $step = max(5.0, (float) $this->option('step'));
        $maxTicks = (int) $this->option('max-ticks');
        $offsetDeg = ((float) $this->option('offset')) / 111320.0;

        $travelled = 0.0;
        $segIndex = 0;
        $segStart = 0.0;
        $ticks = 0;

        while ($segIndex < count($coords) - 1) {
            [$ax, $ay] = $coords[$segIndex];
            [$bx, $by] = $coords[$segIndex + 1];
            $segLen = GeoLine::haversineM($ay, $ax, $by, $bx);

            if ($travelled > $segStart + $segLen) {
                $segStart += $segLen;
                $segIndex++;

                continue;
            }

            $t = $segLen > 0 ? ($travelled - $segStart) / $segLen : 0.0;
            $lng = $ax + $t * ($bx - $ax);
            $lat = $ay + $t * ($by - $ay) + $offsetDeg;

            $locations->upsertLocation([
                'fleet_id' => $trip->fleetRoute->fleet_id,
                'trip_id' => $trip->trip_id,
                'latitude' => round($lat, 7),
                'longitude' => round($lng, 7),
                // 2026-10-29: the heading of the road segment, as a phone reports it (the map's bus arrow uses it).
                'heading' => self::bearing($ay, $ax, $by, $bx),
                'speed_kmh' => null,
                'accuracy' => (float) $this->option('accuracy'),
            ]);

            $ticks++;
            $this->line(sprintf('tick %d: %.6f, %.6f (%.0f m along)', $ticks, $lat, $lng, $travelled));

            if ($maxTicks > 0 && $ticks >= $maxTicks) {
                break;
            }

            $travelled += $step;
            if ((float) $this->option('interval') > 0) {
                usleep((int) (((float) $this->option('interval')) * 1_000_000));
            }
        }

        $this->info("simulation finished after {$ticks} ticks");

        return self::SUCCESS;
    }

    /** Compass bearing in degrees (0 = north, 90 = east) from one point to the next. */
    private static function bearing(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $p1 = deg2rad($lat1);
        $p2 = deg2rad($lat2);
        $dl = deg2rad($lng2 - $lng1);
        $deg = rad2deg(atan2(sin($dl) * cos($p2), cos($p1) * sin($p2) - sin($p1) * cos($p2) * cos($dl)));

        return round(fmod($deg + 360.0, 360.0), 1);
    }
}
