<?php

namespace Database\Seeders;

use App\Models\Route;
use App\Models\RouteStop;
use App\Models\Stop;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Dev/mockup data for the Davao -> Tagum corridor (Batch 24). Idempotent.
 *
 * - Creates the corridor's stops (approximate coordinates, mock data) and
 *   lays them out in geographic order along the route (north-bound).
 * - Corrects the Ecoland - Toril mock route, where Bankerohan (north of
 *   Ecoland) sat between Ecoland and Bapa and forced the drawn line to
 *   double back. Bankerohan belongs to the Davao - Tagum corridor.
 *
 * Distances are mock road distances (km); they are inputs to fares, so this
 * seeder never rewrites them from generated geometry.
 * Custom drop-off (routes.allow_custom_dropoff, off by default) is switched on for
 * this seeded route so the feature is visible in dev; operators can toggle it per
 * route in the portal (re-running this seeder turns it back on for this route).
 * Run: php artisan db:seed --class=DavaoTagumRouteSeeder
 */
class DavaoTagumRouteSeeder extends Seeder
{
    /** [name, lat, lng, km from origin, stop_type, municipality] in travel order. */
    private const CORRIDOR = [
        ['Ecoland Terminal', 7.0525, 125.6069, 0.0, 'terminal', 'Davao City'],
        ['Bankerohan Public Market', 7.0691, 125.6083, 3.5, 'stop', 'Davao City'],
        ['Agdao Public Market', 7.0870, 125.6300, 6.5, 'stop', 'Davao City'],
        ['Buhangin Crossing', 7.1230, 125.6270, 12.0, 'stop', 'Davao City'],
        ['Bunawan Public Market', 7.2310, 125.6420, 22.0, 'stop', 'Davao City'],
        ['Panabo City Public Market', 7.3080, 125.6840, 33.0, 'stop', 'Panabo City'],
        ['Carmen Public Market', 7.3600, 125.7080, 43.0, 'stop', 'Carmen'],
        ['Tagum City Terminal', 7.4478, 125.8078, 56.0, 'terminal', 'Tagum City'],
    ];

    /** Municipality of the Ecoland - Toril mock stops (all inside Davao City). */
    private const ECOLAND_TORIL_MUNICIPALITY = 'Davao City';

    public function run(): void
    {
        DB::transaction(function () {
            $tagum = Route::firstOrCreate(
                ['route_name' => 'Davao - Tagum'],
                ['origin' => 'Davao', 'destination' => 'Tagum']
            );
            $tagum->update(['allow_custom_dropoff' => true]);

            $this->replaceStops($tagum);
            $this->correctEcolandToril();
        });

        // Offsets and geometry distances come from the routing engine, never from typed-in numbers.
        $this->call(RouteStopScheduleSeeder::class);
    }

    private function replaceStops(Route $route): void
    {
        RouteStop::where('route_id', $route->route_id)->delete();

        foreach (self::CORRIDOR as $i => [$name, $lat, $lng, $km, $type, $municipality]) {
            $stop = Stop::firstOrCreate(
                ['stop_name' => $name],
                ['latitude' => $lat, 'longitude' => $lng]
            );
            $stop->update(['municipality' => $municipality]);

            RouteStop::create([
                'route_id' => $route->route_id,
                'stop_id' => $stop->stop_id,
                'stop_order' => $i + 1,
                'distance_from_origin_km' => $km,
                'stop_type' => $type,
            ]);
        }
    }

    private function correctEcolandToril(): void
    {
        // The mock coordinates for these two stops were 1.5 km+ from any road
        // (Daliao sat in the gulf). Replaced with points on the Ecoland-Toril
        // road taken 45% and 75% along the ORS route between the terminals.
        foreach ([
            'Bapa Crossing' => [7.046431, 125.557092],
            'Daliao' => [7.033200, 125.515379],
            'Toril Public Market' => [7.004372, 125.496641], // was 205 m from the road
        ] as $name => [$lat, $lng]) {
            Stop::where('stop_name', $name)->update(['latitude' => $lat, 'longitude' => $lng]);
        }

        $route = Route::where('route_name', 'Ecoland - Toril')->first();
        if ($route) {
            Stop::whereIn('stop_id', RouteStop::where('route_id', $route->route_id)->pluck('stop_id'))
                ->update(['municipality' => self::ECOLAND_TORIL_MUNICIPALITY]);
        }

        $bankerohan = Stop::where('stop_name', 'Bankerohan Public Market')->first();
        if (! $route || ! $bankerohan) {
            return;
        }

        RouteStop::where('route_id', $route->route_id)->where('stop_id', $bankerohan->stop_id)->delete();

        // Renumber the remaining stops 1..n (issued tickets keep their own
        // stop_id snapshots, so they are unaffected).
        RouteStop::where('route_id', $route->route_id)->orderBy('stop_order')->get()
            ->each(fn (RouteStop $routeStop, int $i) => $routeStop->update(['stop_order' => $i + 1]));
    }
}
