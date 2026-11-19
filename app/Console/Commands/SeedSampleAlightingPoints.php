<?php

namespace App\Console\Commands;

use App\Models\AlightingPoint;
use App\Services\AlightingPointService;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

/**
 * Batch 28 Stage 3, item 10: DEV ONLY sample designated alighting points. No coordinate is typed: each point is taken
 * from the route's own line at an even fraction of its length, so it is on the route by construction, and every row is
 * labelled SAMPLE (source and name). The real list comes from the operator's fare matrix, entered by the team.
 */
class SeedSampleAlightingPoints extends Command
{
    protected $signature = 'dropoff:sample-points {route_id : Route id} {--count=3 : How many sample points} {--inactive : Create them switched off}';

    protected $description = 'DEV ONLY: adds SAMPLE designated drop-off points at even fractions along a route line.';

    public function handle(AlightingPointService $points): int
    {
        $routeId = (int) $this->argument('route_id');
        $count = max(1, min(10, (int) $this->option('count')));
        $kinds = AlightingPoint::KINDS;
        $made = 0;

        for ($i = 1; $i <= $count; $i++) {
            $name = "SAMPLE point {$i}";
            if (AlightingPoint::where('route_id', $routeId)->where('name', $name)->exists()) {
                continue;
            }

            $at = $points->pointAtFraction($routeId, $i / ($count + 1));
            if ($at === null) {
                $this->error('This route has no generated path, so no sample point can be placed on it.');

                return self::FAILURE;
            }

            try {
                $attributes = $points->prepare([
                    'route_id' => $routeId,
                    'name' => $name,
                    'kind' => $kinds[($i - 1) % count($kinds)],
                    'latitude' => $at['latitude'],
                    'longitude' => $at['longitude'],
                    'source' => 'SAMPLE - dev only, not a real place',
                    'note' => 'SAMPLE: placed on the route line by the dev command.',
                    'is_active' => ! $this->option('inactive'),
                ]);
            } catch (ValidationException $e) {
                $this->error(collect($e->errors())->flatten()->implode(' '));

                return self::FAILURE;
            }
            AlightingPoint::create($attributes);
            $made++;
        }

        $this->info("Created {$made} SAMPLE point(s) on route {$routeId}.");

        return self::SUCCESS;
    }
}
